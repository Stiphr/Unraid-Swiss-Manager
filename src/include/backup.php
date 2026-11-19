<?php
/*
 * Unraid Swiss Manager - optional pre-update backups.
 * Three DIFFERENT things, never conflated:
 *   config  = the container's Unraid template (my-NAME.xml) + `docker inspect` JSON. Small, enough to recreate the container.
 *   image   = `docker save` of the image currently in use (lets you go back to the exact old version offline). Large.
 *   appdata = tar of the container's bind-mounted host paths under /mnt. NOT application-consistent: the container
 *             is not stopped for it, so databases may be captured mid-write. Use a proper backup plugin for those.
 * The config copy holds the template as it was saved, which can contain passwords, so it is private (0600); the destination is always under /mnt.
 */

function dau_backup_dir_for($dest, $name) { return rtrim($dest, '/') . '/' . $name; }

function dau_backup_free($dest) { $f = @disk_free_space($dest); return $f === false ? null : (float)$f; }

/**
 * Cache folders of Plex, Emby and Jellyfin inside $roots: the folders themselves, their children and grandchildren
 * are looked at, so appdata/plex, appdata/binhex-jellyfin and appdata/jellyfin/config are all found. Everything
 * listed is rebuilt by the server on its own (thumbnails it has to redo come from Cache, not from Metadata, which
 * holds posters and edits and is kept). A folder only counts as a media server's when its own files say so,
 * because "cache" and "logs" are common names:
 *   Plex             Preferences.xml and Plug-in Support in <dir>/Library/Application Support/Plex Media Server,
 *                    <dir>/Plex Media Server or <dir> itself
 *   Emby / Jellyfin  <dir>/config/system.xml and <dir>/data/library.db or <dir>/data/jellyfin.db
 * @return string[] existing folders, absolute, without a trailing slash
 */
function dau_media_cache_dirs(array $roots) {
  $plex = ['Cache', 'Codecs', 'Crash Reports', 'Diagnostics', 'Drivers', 'Logs', 'Updates'];
  $emby = ['cache', 'logs', 'log', 'transcoding-temp', 'transcodes', 'data/transcodes'];
  $out = [];
  $kids = function ($d) {
    $r = [];
    foreach (@scandir($d) ?: [] as $n) if ($n !== '.' && $n !== '..' && $n !== DAU_QDIR_NAME && is_dir("$d/$n") && !is_link("$d/$n")) $r[] = "$d/$n";
    return $r;
  };
  foreach ($roots as $root) {
    $root = rtrim((string)$root, '/');
    if ($root === '' || !is_dir($root)) continue;
    $cands = [$root];
    foreach ($kids($root) as $k) { $cands[] = $k; foreach ($kids($k) as $g) $cands[] = $g; }
    foreach ($cands as $d) {
      foreach (["$d/Library/Application Support/Plex Media Server", "$d/Plex Media Server", $d] as $pms)    // the last one: hotio's image, where /config is the Plex folder itself
        if (is_file("$pms/Preferences.xml") && is_dir("$pms/Plug-in Support")) foreach ($plex as $c) $out[] = "$pms/$c";
      if (is_file("$d/config/system.xml") && (is_file("$d/data/library.db") || is_file("$d/data/jellyfin.db"))) {
        foreach ($emby as $c) $out[] = "$d/$c";
        if (basename($d) === 'config') { $out[] = dirname($d) . '/cache'; $out[] = dirname($d) . '/transcodes'; }   // official Jellyfin image: /config and /cache side by side
      }
    }
  }
  return array_values(array_unique(array_filter($out, function ($p) { return is_dir($p) && !is_link($p); })));
}

/**
 * Host folders inside $roots that a Plex, Emby or Jellyfin container maps to /cache, /transcode or /transcodes,
 * written as they appear under the root that holds them (/mnt/cache/appdata/transcode becomes /mnt/user/appdata/transcode
 * when /mnt/user/appdata is backed up). [] when Docker does not answer.
 * @return string[]
 */
function dau_media_cache_mounts(array $roots) {
  $all = dau_inspect_all(); if (!$all) return [];
  $out = [];
  foreach ($all as $c) foreach ($c['Mounts'] ?? [] as $m) {
    if (($m['Type'] ?? 'bind') !== 'bind' || !dau_is_media_cache_mount($c, $m)) continue;
    $tail = dau_path_tail((string)($m['Source'] ?? ''));
    if ($tail === '' || dau_has($tail, '..')) continue;
    foreach ($roots as $root) {
      $rt = dau_path_tail($root);
      if ($tail !== $rt && dau_path_in($tail, $rt)) { $p = rtrim($root, '/') . substr($tail, strlen(rtrim($rt, '/'))); if (is_dir($p) && !is_link($p)) $out[] = $p; }
    }
  }
  return array_values(array_unique($out));
}

/** tar --exclude pattern for one folder: its path without the leading slash, with tar's wildcard characters escaped. */
function dau_tar_exclude($dir) { return '--exclude=' . preg_replace('/([*?\[\]\\\\])/', '\\\\$1', ltrim($dir, '/')); }

/** Bytes in $dirs according to du (0 when it cannot tell). */
function dau_du_total(array $dirs) {
  $t = 0.0;
  foreach ($dirs as $d) { $r = dau_run([dau_bin('du'), '-sb', $d], 600); $t += (float)preg_replace('/\s.*/s', '', trim($r['out'])); }
  return $t;
}

/** Container folders that only hold a media server's transcoding or cache files (official Jellyfin /cache, Plex /transcode). */
function dau_is_media_cache_mount(array $inspect, array $mount) {
  $img = strtolower((string)($inspect['Config']['Image'] ?? ''));
  return (bool)preg_match('#(^|/)[^/]*(plex|emby|jellyfin)[^/]*(:|@|$)#', $img) && in_array(rtrim((string)($mount['Destination'] ?? ''), '/'), ['/cache', '/transcode', '/transcodes'], true);
}

/** @return array ['ok'=>bool,'error'=>string,'files'=>[],'kinds'=>[...],'skipped'=>[reason,...]] */
function dau_backup_run(array $cfg, $name, array $inspect) {
  $b = $cfg['global']['backup'];
  $res = ['ok' => false, 'error' => '', 'files' => [], 'kinds' => [], 'skipped' => []];
  if (!dau_valid_name($name)) { $res['error'] = 'invalid container name'; return $res; }
  if (!dau_valid_dest($b['dest'] ?? '')) { $res['error'] = 'backup destination is not set to a valid path under /mnt'; return $res; }
  $dir = dau_backup_dir_for($b['dest'], $name);
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { $res['error'] = "cannot create $dir"; return $res; }
  foreach (glob($dir . '/.*.partial') ?: [] as $p) if (@filemtime($p) < dau_now() - 86400) @unlink($p);      // left by a run that was killed
  $stamp = date('Ymd-His', dau_now());
  $free = dau_backup_free($dir);

  $need = function ($bytes, $what) use ($free, &$res) {
    if ($free !== null && $bytes * 1.2 > $free) { $res['skipped'][] = "$what: not enough free space at the destination"; return false; }
    return true;
  };

  if (!empty($b['config'])) {
    $f = "$dir/$stamp-config.json";
    $tpl = DAU_TEMPLATES . "/my-$name.xml";
    $payload = ['container' => $name, 'time' => dau_ts_iso(dau_now()), 'template_xml' => is_file($tpl) ? (string)@file_get_contents($tpl) : null, 'inspect' => $inspect];
    // environment variables can hold passwords: strip values from the inspect copy; the template keeps what the user configured
    if (isset($payload['inspect']['Config']['Env'])) $payload['inspect']['Config']['Env'] = array_map(function ($e) { return preg_replace('/=.*/s', '=<omitted>', $e); }, $payload['inspect']['Config']['Env']);
    if (dau_write_json($f, $payload, true)) { $res['files'][] = $f; $res['kinds'][] = 'config'; } else $res['skipped'][] = 'config: write failed';
  }
  if (!empty($b['image'])) {
    $img = (string)($inspect['Image'] ?? '');
    $r = dau_docker(['image', 'inspect', '--format', '{{.Size}}', $img], 30);
    $size = (float)trim($r['out']);
    if ($need($size, 'image') && $img !== '') {
      $f = "$dir/$stamp-image.tar";
      $tmp = "$dir/.$stamp-image.tar.partial";
      $s = dau_docker(['save', '-o', $tmp, $img], 1800);
      if ($s['code'] === 0 && !$s['timeout'] && is_file($tmp) && @rename($tmp, $f)) { $res['files'][] = $f; $res['kinds'][] = 'image'; }
      else { @unlink($tmp); $res['skipped'][] = 'image: docker save failed'; }
    }
  }
  if (!empty($b['appdata'])) {
    $paths = [];
    foreach (($inspect['Mounts'] ?? []) as $m) {
      $src = (string)($m['Source'] ?? '');
      if (($m['Type'] ?? '') === 'bind' && preg_match('#^/mnt/[^\x00-\x1F]+$#', $src) && !dau_has($src, '..') && strpos($src . '/', rtrim($b['dest'], '/') . '/') !== 0 && is_dir($src) && !dau_is_media_cache_mount($inspect, $m)) $paths[] = $src;
    }
    if (!$paths) $res['skipped'][] = 'appdata: this container has no host folders under /mnt';
    else {
      $skip = dau_media_cache_dirs($paths);      // Plex / Emby / Jellyfin caches: rebuilt by the server, often the largest part
      $size = max(0.0, dau_du_total($paths) - dau_du_total($skip));
      if ($need($size, 'appdata')) {
        $f = "$dir/$stamp-appdata.tar.gz"; $tmp = "$dir/.$stamp-appdata.tar.gz.partial";   // renamed only when complete: an interrupted tar never looks like a backup
        $t = dau_run(array_merge([dau_bin('tar'), '-czf', $tmp], array_map('dau_tar_exclude', $skip), ['--'], $paths), 3600);
        if (($t['code'] === 0 || $t['code'] === 1) && !$t['timeout'] && is_file($tmp) && @rename($tmp, $f)) { $res['files'][] = $f; $res['kinds'][] = 'appdata'; }   // 1 = files changed while reading (expected, container is running)
        else { @unlink($tmp); $res['skipped'][] = 'appdata: tar failed'; }
      }
    }
  }
  $wanted = array_filter([!empty($b['config']) ? 'config' : null, !empty($b['image']) ? 'image' : null, !empty($b['appdata']) ? 'appdata' : null]);
  $missing = array_diff($wanted, $res['kinds']);
  $res['ok'] = !$missing && $res['kinds'];
  if ($res['ok']) dau_backup_prune($dir, (int)$b['retention']);   // only a complete new set may push an older one out
  if (!$res['ok']) $res['error'] = $res['skipped'] ? implode('; ', $res['skipped']) : 'no backup type selected';
  return $res;
}

/** Keep the newest $keep backup sets (a set = all files sharing one timestamp prefix). */
function dau_backup_prune($dir, $keep) {
  if ($keep < 1) return;
  $sets = [];
  foreach (glob($dir . '/*') ?: [] as $f) if (preg_match('#/(\d{8}-\d{6})-(config\.json|image\.tar|appdata\.tar\.gz)$#', $f, $m)) $sets[$m[1]][] = $f;
  krsort($sets);
  foreach (array_slice($sets, $keep, null, true) as $files) foreach ($files as $f) @unlink($f);
}
