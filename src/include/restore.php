<?php
/*
 * Unraid Swiss Manager - one-click restore of an appdata backup (for example onto a new cache drive).
 *
 * Every appdata backup also carries a "restore kit" (restore-kit.json, inside the archive and next to it as
 * appdata-<stamp>.json): which folders were archived, the Docker templates, every container with its image, autostart
 * order and network, and the custom Docker networks. With it a restore:
 *   1. stops the containers that use the appdata folders,
 *   2. moves every folder the backup holds that exists now into the quarantine (nothing is overwritten; undo from Cleanup),
 *   3. unpacks the appdata folders, and puts back templates that are missing (newer ones on the flash drive are kept),
 *   4. creates missing custom networks and autostart entries,
 *   5. recreates missing containers with Unraid's own rebuild_container (it downloads the image when needed) and starts the rest.
 * The flash drive must be working: the plugin runs from it. Restoring the flash drive itself is Unraid's own USB restore.
 */

define('DAU_RS_KIT',    DAU_DIR . '/restore-kit.json');
define('DAU_AUTOSTART', '/var/lib/docker/unraid-autostart');
const DAU_RS_KIT_IN_TAR = 'boot/config/plugins/unraid-swiss-manager/restore-kit.json';
const DAU_RS_TPL_IN_TAR = 'boot/config/plugins/dockerMan/templates-user';

/** Sidecar file of an archive: appdata-YYYYmmdd-HHMMSS.tar.gz -> appdata-YYYYmmdd-HHMMSS.json */
function dau_rs_sidecar($archive) { return preg_replace('/\.tar\.gz$/', '.json', (string)$archive); }

/** Lines of Unraid's autostart file: [['name' => .., 'wait' => ..]] in start order. */
function dau_rs_autostart_read() {
  $o = [];
  foreach (@file(DAU_AUTOSTART, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $p = preg_split('/\s+/', trim($l)); if (!dau_valid_name($p[0] ?? '')) continue;
    $o[] = ['name' => $p[0], 'wait' => isset($p[1]) && ctype_digit($p[1]) ? (int)$p[1] : 0];
  }
  return $o;
}

/** Custom Docker networks (not bridge/host/none, not the br0-style networks Unraid makes itself). null = Docker did not answer. */
function dau_rs_networks() {
  $r = dau_docker(['network', 'ls', '--filter', 'type=custom', '--format', '{{.Name}}'], 30);
  if ($r['code'] !== 0) return null;
  $names = array_values(array_filter(preg_split('/\s+/', trim($r['out'])), function ($n) { return $n !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $n); }));
  if (!$names) return [];
  $r = dau_docker(array_merge(['network', 'inspect'], $names), 30);
  $all = json_decode($r['out'], true); if (!is_array($all)) return null;
  $o = [];
  foreach ($all as $n) {
    $drv = (string)($n['Driver'] ?? ''); $name = (string)($n['Name'] ?? '');
    if ($drv !== 'bridge' || $name === '') continue;          // macvlan / ipvlan (br0, eth0, wg0 ...) come from Unraid's network settings
    $ipam = $n['IPAM']['Config'][0] ?? [];
    $o[] = ['name' => $name, 'driver' => $drv, 'subnet' => (string)($ipam['Subnet'] ?? ''), 'gateway' => (string)($ipam['Gateway'] ?? '')];
  }
  return $o;
}

/** The restore kit for an appdata backup of $srcs. $leftOut: top-level names that are not in the archive. */
function dau_rs_kit(array $srcs, array $leftOut, $bytes) {
  $kit = ['version' => 1, 'plugin' => DAU_VERSION, 'at' => dau_now(), 'bytes' => (float)$bytes, 'roots' => [], 'containers' => [], 'networks' => [], 'autostart' => dau_rs_autostart_read(), 'templates' => []];
  foreach ($srcs as $s) {
    $names = [];
    foreach (@scandir($s) ?: [] as $n) if ($n !== '.' && $n !== '..' && $n !== DAU_QDIR_NAME && !in_array($n, $leftOut, true)) $names[] = $n;
    $kit['roots'][] = ['path' => $s, 'folders' => $names];
  }
  foreach (glob(DAU_TEMPLATES . '/my-*.xml') ?: [] as $f) $kit['templates'][] = basename($f);
  foreach (dau_inspect_all() ?: [] as $c) {
    $n = ltrim((string)($c['Name'] ?? ''), '/'); if (!dau_valid_name($n)) continue;
    $kit['containers'][] = ['name' => $n, 'image' => (string)($c['Config']['Image'] ?? ''), 'running' => !empty($c['State']['Running']),
      'net' => (string)($c['HostConfig']['NetworkMode'] ?? ''), 'template' => is_file(DAU_TEMPLATES . "/my-$n.xml")];
  }
  $kit['networks'] = dau_rs_networks() ?: [];
  return $kit;
}

/** Restore kit of an archive in the list, or null (made before 2026.11.20, or not an archive of this kind). */
function dau_rs_kit_of($file) {
  $k = dau_read_json(dau_rs_sidecar($file), null);
  return is_array($k) && ($k['version'] ?? 0) === 1 && isset($k['roots']) ? $k : null;
}

/** The archive named $name if it is one of the appdata backups at the configured destination. */
function dau_rs_find(array $cfg, $name) {
  foreach (dau_bk_list('appdata', (string)$cfg['backups']['appdata']['dest']) as $f) if ($f['name'] === $name) return $f;
  return null;
}

/** Containers in the order they are brought back: autostart order, and one that uses another's network after that one. */
function dau_rs_order(array $kit) {
  $pos = []; foreach ($kit['autostart'] as $i => $a) $pos[$a['name']] = $i;
  $cs = $kit['containers'];
  usort($cs, function ($a, $b) use ($pos) { return ($pos[$a['name']] ?? 9999) <=> ($pos[$b['name']] ?? 9999) ?: strcmp($a['name'], $b['name']); });
  $done = []; $out = []; $guard = 0;
  while ($cs && $guard++ < 1000) {
    $c = array_shift($cs);
    $dep = preg_match('/^container:(.+)$/', $c['net'], $m) ? $m[1] : '';
    $known = $dep !== '' && in_array($dep, array_column($kit['containers'], 'name'), true);
    if ($known && !isset($done[$dep]) && $guard < 500) { $cs[] = $c; continue; }
    $done[$c['name']] = true; $out[] = $c;
  }
  return array_merge($out, $cs);
}

/**
 * What a restore of $name would do, without changing anything.
 * @return array ['ok','error','at','size','folders'=>[[root,name,exists]],'containers'=>[[name,action]],'networks'=>[...],'templates'=>n,'free','need']
 */
function dau_rs_plan(array $cfg, $name) {
  $f = dau_rs_find($cfg, $name);
  if (!$f) return ['ok' => false, 'error' => 'That backup is not in the backup folder any more.'];
  $kit = dau_rs_kit_of($f['file']);
  if (!$kit) return ['ok' => false, 'error' => 'This backup was made before restore kits existed (2026.11.20), so it cannot be restored with one click. Unpack it by hand (see How to restore).'];
  $have = []; foreach (dau_inspect_all() ?: [] as $c) $have[ltrim((string)($c['Name'] ?? ''), '/')] = !empty($c['State']['Running']);
  $out = ['ok' => true, 'error' => '', 'name' => $name, 'at' => (int)$kit['at'], 'size' => $f['size'], 'need' => (float)$kit['bytes'], 'folders' => [], 'containers' => [], 'networks' => [], 'templates' => 0, 'free' => null];
  foreach ($kit['roots'] as $r) {
    foreach ($r['folders'] as $n) $out['folders'][] = ['root' => $r['path'], 'name' => $n, 'exists' => file_exists($r['path'] . '/' . $n)];
    $fr = dau_backup_free(is_dir($r['path']) ? $r['path'] : dirname($r['path'])); if ($fr !== null) $out['free'] = $out['free'] === null ? $fr : min($out['free'], $fr);
  }
  foreach (dau_rs_order($kit) as $c) {
    $tpl = is_file(DAU_TEMPLATES . '/my-' . $c['name'] . '.xml') || in_array('my-' . $c['name'] . '.xml', $kit['templates'], true);
    $act = isset($have[$c['name']]) ? 'start' : ($tpl ? 'recreate' : 'missing');
    $out['containers'][] = ['name' => $c['name'], 'image' => $c['image'], 'action' => $act, 'was_running' => $c['running']];
  }
  $nets = []; $r = dau_docker(['network', 'ls', '--format', '{{.Name}}'], 30); foreach (preg_split('/\s+/', trim($r['out'])) as $n) $nets[$n] = true;
  foreach ($kit['networks'] as $n) $out['networks'][] = ['name' => $n['name'], 'exists' => isset($nets[$n['name']])];
  foreach ($kit['templates'] as $t) if (!is_file(DAU_TEMPLATES . '/' . $t)) $out['templates']++;
  if ($out['free'] !== null && $out['need'] > $out['free']) { $out['ok'] = false; $out['error'] = 'Not enough free space: the backup unpacks to about ' . dau_bytes_text($out['need']) . ', ' . dau_bytes_text($out['free']) . ' is free. The folders that are moved aside stay on the same disk.'; }
  return $out;
}

/** Restore $name. Runs inside a job (lock held). @return array ['ok','error','notes'=>[],'moved','recreated','started','failed'] */
function dau_rs_run(array $cfg, $name) {
  $res = ['ok' => false, 'error' => '', 'notes' => [], 'moved' => [], 'recreated' => [], 'started' => [], 'failed' => []];
  $plan = dau_rs_plan($cfg, $name);
  if (!$plan['ok']) { $res['error'] = $plan['error']; return $res; }
  $f = dau_rs_find($cfg, $name); $kit = dau_rs_kit_of($f['file']);
  $roots = array_column($kit['roots'], 'path');

  /* 1. stop what uses the appdata folders */
  dau_phase('Stopping containers', ['stage' => 'stop']);
  $users = dau_bk_users($cfg, $roots);
  if ($users === null) { $res['error'] = 'Docker did not answer, so nothing was restored. Is Docker running (Settings > Docker)?'; return $res; }
  $stopped = dau_bk_stop($users, $res);
  if ($res['error'] !== '') { dau_bk_start($stopped, $res); return $res; }

  /* 2. move what is there now aside: the quarantine keeps it (Cleanup tab), nothing is overwritten */
  dau_phase('Moving the current folders aside', ['stage' => 'move']);
  $q = dau_quarantine_load(); $stamp = gmdate('Ymd-His', dau_now());
  foreach ($kit['roots'] as $r) {
    $root = $r['path'];
    if (!is_dir($root) && !@mkdir($root, 0777, true)) { $res['error'] = "Cannot create $root."; dau_quarantine_save($q); dau_bk_start($stopped, $res); return $res; }
    $qd = $root . '/' . DAU_QDIR_NAME;
    foreach ($r['folders'] as $n) {
      $p = $root . '/' . $n;
      if (!file_exists($p) && !is_link($p)) continue;
      if (!is_dir($qd) && !@mkdir($qd, 0700, true)) { $res['error'] = 'The quarantine folder could not be created, so nothing was restored.'; break 2; }
      $dest = $qd . '/' . $n . '__before-restore-' . $stamp;
      if (!@rename($p, $dest)) { $res['error'] = "$p could not be moved aside, so it was not restored. Nothing else was changed after this point."; break 2; }
      $q[] = ['id' => substr(hash('sha256', $dest . $stamp), 0, 12), 'name' => $n, 'original' => $p, 'path' => $dest, 'root' => $root, 'size' => 0, 'at' => dau_now(), 'why' => 'restore'];
      $res['moved'][] = $n;
    }
  }
  dau_quarantine_save($q);
  if ($res['error'] !== '') { dau_rs_undo_moves($res['moved'], $kit, $stamp); dau_bk_start($stopped, $res); return $res; }

  /* 3. unpack */
  dau_phase('Unpacking ' . $name, ['stage' => 'unpack']);
  $members = array_map(function ($p) { return ltrim($p, '/'); }, $roots);
  $t = dau_run(array_merge([dau_bin('tar'), '-xzf', $f['file'], '-C', '/', '--'], $members), 6 * 3600, null, 4096);
  if (($t['code'] !== 0 && $t['code'] !== 1) || $t['timeout']) {
    $res['error'] = 'Unpacking failed: ' . (dau_clip(dau_redact(trim($t['err'])), 200) ?: 'tar stopped with code ' . $t['code']) . '. The folders that were there before are in the quarantine (Cleanup tab).';
    dau_bk_start($stopped, $res); return $res;
  }
  $tmp = DAU_DIR . '/.restore-' . $stamp;
  @mkdir($tmp, 0700, true);
  $tt = dau_run([dau_bin('tar'), '-xzf', $f['file'], '-C', $tmp, '--', DAU_RS_TPL_IN_TAR], 600);
  $added = 0;
  foreach (glob($tmp . '/' . DAU_RS_TPL_IN_TAR . '/my-*.xml') ?: [] as $x) {
    $to = DAU_TEMPLATES . '/' . basename($x);
    if (!is_file($to)) { @mkdir(DAU_TEMPLATES, 0777, true); if (@copy($x, $to)) $added++; }
  }
  dau_run([dau_bin('rm'), '-rf', '--', $tmp], 120);
  if ($added) $res['notes'][] = $added . ' Docker template' . ($added === 1 ? '' : 's') . ' put back.';
  elseif ($tt['code'] > 1) $res['notes'][] = 'The templates could not be read from the backup; the ones on the flash drive were used.';

  /* 4. networks and autostart */
  dau_phase('Setting up Docker', ['stage' => 'containers']);
  $nets = []; $r = dau_docker(['network', 'ls', '--format', '{{.Name}}'], 30); foreach (preg_split('/\s+/', trim($r['out'])) as $n) $nets[$n] = true;
  foreach ($kit['networks'] as $n) {
    if (isset($nets[$n['name']])) continue;
    $a = ['network', 'create', '--driver', 'bridge'];
    if ($n['subnet'] !== '') { $a[] = '--subnet'; $a[] = $n['subnet']; }
    if ($n['gateway'] !== '') { $a[] = '--gateway'; $a[] = $n['gateway']; }
    $a[] = $n['name'];
    $c = dau_docker($a, 60);
    $res['notes'][] = $c['code'] === 0 ? 'Network ' . $n['name'] . ' created.' : 'Network ' . $n['name'] . ' could not be created: ' . dau_clip(trim($c['err']), 120);
  }
  $auto = dau_rs_autostart_read(); $inAuto = array_column($auto, 'name'); $add = [];
  foreach ($kit['autostart'] as $a) if (!in_array($a['name'], $inAuto, true)) $add[] = $a['name'] . ($a['wait'] ? ' ' . $a['wait'] : '');
  if ($add) { @mkdir(dirname(DAU_AUTOSTART), 0755, true); @file_put_contents(DAU_AUTOSTART, implode("\n", array_merge(array_map(function ($a) { return $a['name'] . ($a['wait'] ? ' ' . $a['wait'] : ''); }, $auto), $add)) . "\n"); }

  /* 5. containers: recreate the missing ones, start the ones that ran */
  $script = DAU_DM . '/scripts/rebuild_container';
  foreach ($plan['containers'] as $c) {
    $n = $c['name'];
    if ($c['action'] === 'recreate') {
      dau_phase('Recreating ' . $n . ' (the image is downloaded if needed)', ['stage' => 'containers']);
      if (!is_file($script)) { $res['failed'][] = $n; continue; }
      $r = dau_run([DAU_PHP, $script, $n], 1800);
      $i = dau_inspect_one($n);
      if ($i === null) { $res['failed'][] = $n; continue; }
      $res['recreated'][] = $n;
      if ($c['was_running'] && empty($i['State']['Running'])) { $s = dau_docker_restore($n); if ($s === 'failed') $res['failed'][] = $n; }
    } elseif ($c['action'] === 'start' && in_array($n, $stopped, true)) {
      $s = dau_docker_restore($n); if ($s === 'started' || $s === 'running') $res['started'][] = $n; else $res['failed'][] = $n;
    } elseif ($c['action'] === 'missing') $res['notes'][] = $n . ' has no Unraid template, so it was not recreated.';
  }
  $res['ok'] = !$res['failed'];
  if ($res['failed']) $res['error'] = 'The files are back, but these containers did not start: ' . implode(', ', array_unique($res['failed'])) . '. Open them on the Docker tab.';
  return $res;
}

/** Put folders moved aside by a failed restore back where they were (only those of this run). */
function dau_rs_undo_moves(array $moved, array $kit, $stamp) {
  $q = dau_quarantine_load();
  foreach ($kit['roots'] as $r) foreach ($moved as $n) {
    $p = $r['path'] . '/' . $n; $src = $r['path'] . '/' . DAU_QDIR_NAME . '/' . $n . '__before-restore-' . $stamp;
    if (is_dir($src) && !file_exists($p) && @rename($src, $p)) $q = array_values(array_filter($q, function ($e) use ($src) { return ($e['path'] ?? '') !== $src; }));
  }
  dau_quarantine_save($q);
}

/** As a job: history, log and notification like a backup. */
function dau_job_restore(array &$J, $name) {
  $cfg = $J['cfg']; $t0 = dau_now();
  dau_sticky(['steps' => [['stop', 'Stop containers'], ['move', 'Move current folders aside'], ['unpack', 'Unpack the backup'], ['containers', 'Bring containers back']]]);
  try { $r = dau_rs_run($cfg, $name); }
  catch (Throwable $e) { $r = ['ok' => false, 'error' => 'Unexpected error: ' . $e->getMessage(), 'notes' => [], 'moved' => [], 'recreated' => [], 'started' => [], 'failed' => []]; }
  $msg = 'From ' . $name . '. ' . ($r['moved'] ? count($r['moved']) . ' folders moved to the quarantine first. ' : '') . ($r['recreated'] ? 'Recreated: ' . implode(', ', $r['recreated']) . '. ' : '') . ($r['started'] ? 'Started again: ' . count($r['started']) . '. ' : '') . implode(' ', $r['notes']);
  dau_history_add(['type' => 'backup', 'name' => dau_bk_label('appdata'), 'action' => 'restore', 'result' => $r['ok'] ? 'completed' : 'failed', 'started' => $t0, 'finished' => dau_now(),
    'trigger' => $J['trigger'], 'error' => $r['error'], 'message' => trim($msg)], (int)$cfg['history']['max_entries']);
  dau_log($r['ok'] ? 'info' : 'error', 'backup', 'appdata', 'restore', $r['ok'] ? 'ok' : 'fail', $r['ok'] ? trim($msg) : $r['error']);
  $J['events'][] = dau_ev('backup', dau_bk_label('appdata'), $r['ok'] ? 'restore_done' : 'restore_failed', ['detail' => trim($msg), 'error' => $r['error'], 'next' => $r['ok'] ? '' : 'Open the Activity tab for details; the folders that were there before are in the quarantine.']);
  return $r;
}
