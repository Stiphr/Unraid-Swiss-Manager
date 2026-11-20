<?php
/* Scheduled backups of appdata and of the boot drive. Real folders under /mnt/dau-test, tar/du/gzip mocked (nothing is archived for real). */

function bkenv(array $bk = [], array $o = []) {
  $root = '/mnt/dau-test/appdata'; $out = '/mnt/dau-test/backups';
  if (is_dir('/mnt/dau-test')) dau_test_rm('/mnt/dau-test');
  @mkdir("$root/plex", 0755, true); @mkdir($out, 0755, true);
  $cfg = dau_defaults(); $cfg['appdata'] = ['roots' => [$root], 'safe_mode' => true];
  $cfg['backups']['appdata'] = array_replace($cfg['backups']['appdata'], ['dest' => $out, 'schedule' => 'weekly', 'keep' => 2], $bk['appdata'] ?? []);
  $cfg['backups']['flash'] = array_replace($cfg['backups']['flash'], ['dest' => $out, 'schedule' => 'monthly', 'keep' => 2], $bk['flash'] ?? []);
  dau_save_config($cfg);
  $GLOBALS['TARS'] = []; $GLOBALS['BK'] = $o;
  mock_exec(function ($a) {
    $b = basename($a[0]);
    if ($b === 'du') return ['out' => ($GLOBALS['BK']['du'] ?? 4096) . "\t" . end($a) . "\n"];
    if ($b === 'gzip') return ['code' => $GLOBALS['BK']['gzip'] ?? 0];
    if ($b === 'tar') {
      $GLOBALS['TARS'][] = $a;
      $code = $GLOBALS['BK']['tar'] ?? 0;
      if ($code === 0 || $code === 1) file_put_contents($a[2], 'archive');
      return ['code' => $code, 'err' => $code > 1 ? 'tar: Cannot write: No space left on device' : ''];
    }
    return null;
  });
  return dau_load_config();
}
function bk_files($kind) { return array_map('basename', array_column(dau_bk_list($kind, '/mnt/dau-test/backups'), 'file')); }

t('backups: settings are validated (keep 1-3, schedule list, destination under /mnt)', function () {
  $c = dau_defaults(); eq($c['backups']['appdata']['schedule'], 'off'); eq($c['backups']['flash']['keep'], 2);
  $n = dau_sanitize_config(['backups' => ['appdata' => ['keep' => 9, 'schedule' => 'hourly', 'dest' => '/etc/x', 'time' => '25:00'], 'flash' => ['keep' => 0, 'schedule' => 'monthly', 'monthday' => 40]]], $c);
  eq($n['backups']['appdata']['keep'], 3); eq($n['backups']['appdata']['schedule'], 'off', 'unknown schedule ignored'); eq($n['backups']['appdata']['dest'], '', 'outside /mnt refused'); eq($n['backups']['appdata']['time'], '03:00');
  eq($n['backups']['flash']['keep'], 1); eq($n['backups']['flash']['schedule'], 'monthly'); eq($n['backups']['flash']['monthday'], 28);
  $n = dau_sanitize_config(['backups' => ['appdata' => ['before_update' => true, 'required' => true, 'exclude' => ['plex-cache', '../etc', 'a/b', '/qui/backups/', 'x/../y', '.hidden', 'app/.cache', "a\nb", 'a//b', 'plex-cache', 'logs/*.log']]]], $c);
  ok($n['backups']['appdata']['before_update'] && $n['backups']['appdata']['required']);
  eq($n['backups']['appdata']['exclude'], ['plex-cache', 'a/b', 'qui/backups', 'app/.cache', 'logs/*.log'], 'paths inside the appdata folder; never .., a hidden first part, empty parts or control characters');
});

t('backups: destination checks', function () {
  $cfg = bkenv(); eq(dau_bk_check_dest('appdata', $cfg), '');
  $cfg['backups']['appdata']['dest'] = ''; has(dau_bk_check_dest('appdata', $cfg), 'Choose where');
  $cfg['backups']['appdata']['dest'] = '/mnt/dau-test/appdata/plex/b'; has(dau_bk_check_dest('appdata', $cfg), 'inside the folder that is backed up');
  $cfg['backups']['appdata']['dest'] = '/mnt/dau-test/appdata'; has(dau_bk_check_dest('appdata', $cfg), 'inside the folder that is backed up');
  $cfg['backups']['appdata']['dest'] = '/mnt/not-mounted/x/y'; has(dau_bk_check_dest('appdata', $cfg), 'does not exist');
  $cfg['backups']['appdata']['dest'] = '/boot/config'; has(dau_bk_check_dest('appdata', $cfg), 'under /mnt');
});

t('backups: an archive is written privately, atomically and listed', function () {
  $cfg = bkenv(['appdata' => ['exclude' => ['Cache']]]);
  $r = dau_bk_run('appdata', $cfg);
  ok($r['ok'], $r['error']); eq($r['kept'], 1); ok(is_file($r['file'])); eq(substr(sprintf('%o', fileperms($r['file'])), -4), '0600');
  ok((bool)preg_match('#/Unraid-Swiss-Manager/appdata/appdata-\d{8}-\d{6}\.tar\.gz$#', $r['file']));
  eq(glob('/mnt/dau-test/backups/Unraid-Swiss-Manager/appdata/.*.partial'), [], 'no temporary file left');
  $a = $GLOBALS['TARS'][0]; has(implode(' ', $a), '-C / '); has(implode(' ', $a), '--exclude=mnt/dau-test/appdata/Cache'); has(implode(' ', $a), '--exclude=mnt/dau-test/appdata/.unraid-autoupdater-quarantine'); has(implode(' ', $a), ' -- mnt/dau-test/appdata');
  $f = dau_bk_list('appdata', '/mnt/dau-test/backups'); eq(count($f), 1); ok($f[0]['size'] > 0);
});

t('backups: only the newest N are kept; the fresh copy is never removed', function () {
  foreach ([1, 2, 3] as $keep) {
    $cfg = bkenv(['appdata' => ['keep' => $keep]]);
    $last = '';
    for ($i = 0; $i < 5; $i++) { $GLOBALS['DAU_NOW'] += 3600; $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']); $last = basename($r['file']); }
    $f = bk_files('appdata'); eq(count($f), $keep, "keep $keep"); eq($f[0], $last, 'newest survives');
  }
  $cfg = bkenv(); $GLOBALS['DAU_NOW'] += 10; dau_bk_run('appdata', $cfg); $GLOBALS['DAU_NOW'] += 10; dau_bk_run('appdata', $cfg); $GLOBALS['DAU_NOW'] += 10; $r = dau_bk_run('appdata', $cfg);
  eq($r['pruned'], 1); eq($r['kept'], 2);
  file_put_contents('/mnt/dau-test/backups/Unraid-Swiss-Manager/appdata/notes.txt', 'mine'); file_put_contents('/mnt/dau-test/backups/Unraid-Swiss-Manager/flash-x.tar.gz', 'x');
  dau_bk_prune('appdata', '/mnt/dau-test/backups', 1); ok(is_file('/mnt/dau-test/backups/Unraid-Swiss-Manager/appdata/notes.txt'), 'foreign files are left alone'); eq(count(bk_files('appdata')), 1);
  eq(dau_bk_prune('appdata', '/mnt/dau-test/backups', 0), 0, 'keep is at least 1');
});

t('backups: a failed run changes nothing (older archives stay) and says why', function () {
  $cfg = bkenv(); dau_bk_run('appdata', $cfg); $GLOBALS['DAU_NOW'] += 60; dau_bk_run('appdata', $cfg); $before = bk_files('appdata');
  $GLOBALS['DAU_NOW'] += 60; $GLOBALS['BK']['tar'] = 2; $r = dau_bk_run('appdata', $cfg);
  ok(!$r['ok']); has($r['error'], 'No space left'); eq(bk_files('appdata'), $before, 'nothing removed'); eq(glob('/mnt/dau-test/backups/Unraid-Swiss-Manager/appdata/.*.partial'), []);
  $GLOBALS['BK']['tar'] = 0; $GLOBALS['BK']['du'] = '9000000000000000'; $r = dau_bk_run('appdata', $cfg); ok(!$r['ok']); has($r['error'], 'Not enough free space'); eq(bk_files('appdata'), $before);
  $GLOBALS['BK']['du'] = 4096; $GLOBALS['BK']['tar'] = 1; $GLOBALS['DAU_NOW'] += 60; $r = dau_bk_run('appdata', $cfg); ok($r['ok'], 'exit 1 = files changed while reading, still a backup'); has(implode(' ', $r['notes']), 'changed');
});

/* docker ps/inspect/stop/start for the stop-containers option; DOCKER_LOG records stop/start order */
function bk_docker(array $running, array $fail = []) {
  $GLOBALS['BKD'] = ['running' => $running, 'fail' => $fail, 'log' => []];
  $prev = $GLOBALS['DAU_EXEC_MOCK'];
  mock_exec(function ($a, $in) use ($prev) {
    if (basename($a[0]) !== 'docker') return $prev($a, $in);
    $D =& $GLOBALS['BKD'];
    if ($a[1] === 'ps') return ['out' => implode("\n", array_keys($D['running'])) . "\n"];
    if ($a[1] === 'inspect') { $o = []; foreach ($D['running'] as $n => $src) $o[] = ['Name' => "/$n", 'State' => ['Status' => 'running'], 'Mounts' => [['Type' => 'bind', 'Source' => $src]]]; return ['out' => json_encode($o)]; }
    if ($a[1] === 'stop' || $a[1] === 'start') { $n = end($a); $D['log'][] = $a[1] . ' ' . $n; return ['code' => in_array($a[1] . ' ' . $n, $D['fail'], true) ? 1 : 0]; }
    return null;
  });
}

t('backups: "stop the containers" stops users of appdata (dependants first) and starts them again in order', function () {
  $cfg = bkenv(['appdata' => ['stop_containers' => true]]);
  $cfg['containers'] = ['app' => ['after' => ['db']]]; dau_save_config($cfg); $cfg = dau_load_config();
  bk_docker(['app' => '/mnt/dau-test/appdata/plex', 'db' => '/mnt/dau-test/appdata/db', 'media' => '/mnt/dau-test/media']);
  $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']);
  eq($GLOBALS['BKD']['log'], ['stop app', 'stop db', 'start db', 'start app'], 'media is not stopped, db starts first');
  has(implode(' ', $r['notes']), 'Stopped during the backup');
  $cfg['backups']['appdata']['stop_containers'] = false; bk_docker(['app' => '/mnt/dau-test/appdata/plex']); $GLOBALS['DAU_NOW'] += 60;
  ok(dau_bk_run('appdata', $cfg)['ok']); eq($GLOBALS['BKD']['log'], [], 'off: nothing is stopped');
});

t('backups: containers are started again when tar fails; a refused stop aborts; a failed start is reported', function () {
  $cfg = bkenv(['appdata' => ['stop_containers' => true]]);
  bk_docker(['app' => '/mnt/dau-test/appdata/plex']); $GLOBALS['BK']['tar'] = 2;
  $r = dau_bk_run('appdata', $cfg); ok(!$r['ok']); eq($GLOBALS['BKD']['log'], ['stop app', 'start app'], 'started again after a failed tar');
  $GLOBALS['BK']['tar'] = 0;
  bk_docker(['a' => '/mnt/dau-test/appdata/x', 'b' => '/mnt/dau-test/appdata/y'], ['stop a']); $GLOBALS['TARS'] = [];
  $r = dau_bk_run('appdata', $cfg); ok(!$r['ok']); has($r['error'], 'could not be stopped'); eq($GLOBALS['TARS'], [], 'no archive'); eq($GLOBALS['BKD']['log'], ['stop b', 'stop a', 'start b'], 'what was stopped is started again');
  bk_docker(['app' => '/mnt/dau-test/appdata/plex'], ['start app']); $GLOBALS['DAU_NOW'] += 60;
  $r = dau_bk_run('appdata', $cfg); ok(!$r['ok'], 'a container left down is not a success'); has($r['error'], 'did not start again'); ok(is_file($r['file']), 'the archive itself is kept');
});

t('backups: the boot drive archive is checked and /boot is the source', function () {
  $cfg = bkenv(); $r = dau_bk_run('flash', $cfg); ok($r['ok'], $r['error']);
  has(implode(' ', $GLOBALS['TARS'][0]), ' -- boot'); ok((bool)preg_match('#/flash/flash-\d{8}-\d{6}\.tar\.gz$#', $r['file']));
  $GLOBALS['BK']['gzip'] = 1; $GLOBALS['DAU_NOW'] += 60; $r = dau_bk_run('flash', $cfg); ok(!$r['ok']); has($r['error'], 'integrity'); eq(count(bk_files('flash')), 1, 'the discarded archive is gone, the earlier one stays');
});

t('backups: weekly and monthly slots are due at the right time, only with a usable destination', function () {
  $cfg = bkenv(['appdata' => ['schedule' => 'weekly', 'weekday' => 0, 'time' => '03:00'], 'flash' => ['schedule' => 'off']]);
  $s = dau_bk_slots($cfg); eq(array_keys($s), ['appdata']);
  $now = 1793000000;                                                    // Monday 2026-10-26 07:33 UTC
  $sun = dau_occ_before($s['appdata']['sched'], $now); eq(date('D H:i', $sun), 'Sun 03:00');
  ok(dau_slot_due($s['appdata']['sched'], ['seen' => $sun - 86400, 'last' => 0], $sun + 600), 'due just after');
  ok(!dau_slot_due($s['appdata']['sched'], ['seen' => $sun - 86400, 'last' => $sun + 5], $sun + 600), 'not twice');
  $cfg['backups']['appdata']['dest'] = ''; eq(dau_bk_slots($cfg), [], 'no destination = no schedule');
  $cfg = bkenv(['flash' => ['schedule' => 'monthly', 'monthday' => 1, 'time' => '03:30']]); $s = dau_bk_slots($cfg); ok(isset($s['flash']));
  eq(date('Y-m-d H:i', dau_next_run($s['flash']['sched'], ['seen' => $now, 'last' => 0], $now)), '2026-11-01 03:30');
});

t('backups: a scheduled tick makes the backup, records it, and a failure is reported', function () {
  $cfg = bkenv(['appdata' => ['schedule' => 'weekly', 'weekday' => (int)date('w', 1793000000 - 86400), 'time' => '03:00'], 'flash' => ['schedule' => 'off']]);
  $st = dau_state_load(); $st['slots']['b:appdata'] = ['seen' => 1793000000 - 3 * 86400, 'last' => 0]; dau_state_save($st);
  $GLOBALS['DAU_NOW'] = (int)(strtotime('2026-10-25 03:10:00 UTC')); $GLOBALS['NOTIFY'] = [];
  eq(dau_job_main('tick'), 0);
  eq(count(bk_files('appdata')), 1, 'tick ran the backup'); $st = dau_state_load(); ok($st['backups']['appdata']['ok']); eq($st['backups']['appdata']['reason'], 'scheduled');
  $h = dau_history_load(); eq($h[0]['type'], 'backup'); eq($h[0]['result'], 'completed'); has($h[0]['message'], '1 kept');
  $GLOBALS['BK']['tar'] = 2; $r = dau_job_main('backup', ['kinds' => ['appdata']]); $h = dau_history_load(); eq($h[0]['result'], 'failed'); has($h[0]['error'], 'No space'); eq(dau_state_load()['backups']['appdata']['ok'], false);
  eq(count(bk_files('appdata')), 1, 'the earlier backup is still there');
});

t('backups: before an update, once per job; "required" stops the update when the backup fails', function () {
  $cfg = bkenv(['appdata' => ['before_update' => true, 'schedule' => 'off']]);
  $st = dau_state_load(); $J = dau_job_new('scheduled', $cfg, $st);
  ok(dau_bk_before_update($J, 'Docker')); ok(dau_bk_before_update($J, 'plugin')); eq(count(bk_files('appdata')), 1, 'one backup for the whole job');
  $cfg = bkenv(['appdata' => ['before_update' => false]]); $J = dau_job_new('scheduled', $cfg, dau_state_load()); ok(dau_bk_before_update($J, 'Docker')); eq(count(bk_files('appdata')), 0, 'off = nothing');
  $GLOBALS['BK']['tar'] = 2;
  $cfg = bkenv(['appdata' => ['before_update' => true, 'required' => false]]); $GLOBALS['BK']['tar'] = 2; $J = dau_job_new('scheduled', $cfg, dau_state_load()); ok(dau_bk_before_update($J, 'Docker'), 'not required: updates go on');
  $cfg = bkenv(['appdata' => ['before_update' => true, 'required' => true]]); $GLOBALS['BK']['tar'] = 2; $J = dau_job_new('scheduled', $cfg, dau_state_load()); ok(!dau_bk_before_update($J, 'Docker'), 'required: updates are held back');
  $k = array_column($J['events'], 'kind'); ok(in_array('backup_failed', $k, true));
});

t('backups: api shows settings and refuses a run without a usable destination', function () {
  bkenv(['flash' => ['dest' => '']]);
  $r = api_call('backups'); ok($r['ok'] ?? false); eq($r['backups']['appdata']['problem'], ''); has($r['backups']['flash']['problem'], 'Choose where'); eq($r['backups']['appdata']['next'] !== null, true);
  has(api_call('run', ['what' => 'backup', 'kind' => 'flash'])['error'] ?? '', 'Choose where'); has(api_call('run', ['what' => 'backup', 'kind' => 'all'])['error'] ?? '', 'Choose where');
  has(api_call('run', ['what' => 'backup', 'kind' => 'x'])['error'] ?? '', 'Unknown');
});

t('backups: the dispatcher cron line exists when only a backup is scheduled', function () {
  $cfg = bkenv(['appdata' => ['schedule' => 'weekly'], 'flash' => ['schedule' => 'off']]);
  $cfg['global']['enabled'] = false; $cfg['plugins']['check_enabled'] = false; $cfg['os']['check_enabled'] = false;
  @unlink(DAU_CRON); dau_cron_apply($cfg); ok(is_file(DAU_CRON), 'cron line written for a backup-only schedule');
  $cfg['backups']['appdata']['schedule'] = 'off'; dau_cron_apply($cfg); ok(!is_file(DAU_CRON), 'removed when nothing is scheduled');
});

t('release: every file under src/include is packed into the plugin file', function () {
  $plg = file_get_contents(__DIR__ . '/../unraid-swiss-manager.plg');
  foreach (glob(__DIR__ . '/../src/include/*.php') as $f) has($plg, 'Name="&emhttp;/include/' . basename($f) . '"', 'not packed');
  foreach (glob(__DIR__ . '/../src/assets/*') as $f) has($plg, '/assets/' . basename($f) . '"', 'not packed');
});

t('rename: the settings page lives at Settings/UnraidSwissManager, the tile in UnraidSwissManagerDash.page', function () {
  $plg = file_get_contents(__DIR__ . '/../unraid-swiss-manager.plg');
  has($plg, 'launch="Settings/UnraidSwissManager"'); has($plg, 'Name="&emhttp;/UnraidSwissManager.page"'); has($plg, 'Name="&emhttp;/UnraidSwissManagerDash.page"'); lacks(substr($plg, strpos($plg, '</CHANGES>')), 'UnraidAutoUpdater', 'only the changelog may mention the old name');
  foreach (array_merge(glob(__DIR__ . '/../src/*.page'), glob(__DIR__ . '/../src/include/*.php')) as $f) lacks(file_get_contents($f), 'Settings/UnraidAutoUpdater', basename($f));
});

/* Plex, Emby and Jellyfin as their images lay them out, plus an app whose own "cache" folder must be kept. */
function bk_media($root) {
  $mk = function ($p, $file = null) { @mkdir(dirname($file ?? "$p/x"), 0755, true); @mkdir($p, 0755, true); if ($file) file_put_contents($file, 'x'); };
  $pms = "$root/plex/Library/Application Support/Plex Media Server";
  $mk("$pms/Metadata", "$pms/Preferences.xml"); foreach (['Cache/Transcode', 'Codecs', 'Crash Reports', 'Logs', 'Updates'] as $c) $mk("$pms/$c");
  $mk("$root/plex/Library/Application Support/Plex Media Server/Plug-in Support/Databases");
  $j = "$root/binhex-jellyfin";   // linuxserver / binhex: one /config folder
  $mk("$j/config", "$j/config/system.xml"); $mk("$j/data", "$j/data/jellyfin.db"); foreach (['cache', 'log', 'metadata', 'data/transcodes'] as $c) $mk("$j/$c");
  $o = "$root/jellyfin";          // official image: /config and /cache side by side
  $mk("$o/config/config", "$o/config/config/system.xml"); $mk("$o/config/data", "$o/config/data/jellyfin.db"); $mk("$o/config/log"); $mk("$o/cache/images"); $mk("$o/config/metadata");
  $e = "$root/EmbyServer";
  $mk("$e/config", "$e/config/system.xml"); $mk("$e/data", "$e/data/library.db"); foreach (['cache', 'logs', 'transcoding-temp', 'metadata'] as $c) $mk("$e/$c");
  $mk("$root/nextcloud/cache"); $mk("$root/nextcloud/config", "$root/nextcloud/config/system.xml");   // looks a bit like Jellyfin, has no database: not a media server
}

t('backups: the caches of Plex, Emby and Jellyfin are found, their libraries and other apps\' caches are not', function () {
  bkenv(); $root = '/mnt/dau-test/appdata'; bk_media($root);
  $d = dau_media_cache_dirs([$root]); sort($d);
  $pms = "$root/plex/Library/Application Support/Plex Media Server";
  $want = ["$pms/Cache", "$pms/Codecs", "$pms/Crash Reports", "$pms/Logs", "$pms/Updates",
    "$root/binhex-jellyfin/cache", "$root/binhex-jellyfin/data/transcodes", "$root/binhex-jellyfin/log",
    "$root/jellyfin/cache", "$root/jellyfin/config/log",
    "$root/EmbyServer/cache", "$root/EmbyServer/logs", "$root/EmbyServer/transcoding-temp"];
  sort($want); eq($d, $want);
  foreach (["$pms/Metadata", "$pms/Plug-in Support", "$root/binhex-jellyfin/metadata", "$root/EmbyServer/metadata", "$root/nextcloud/cache", "$root/jellyfin/config/metadata"] as $keep) ok(!in_array($keep, $d, true), "kept: $keep");
  eq(dau_media_cache_dirs(["$root/plex"]), array_values(array_filter($want, function ($p) use ($pms) { return strpos($p, $pms) === 0; })), 'also found when the root is the app folder itself');
  eq(dau_media_cache_dirs(['/mnt/dau-test/none']), [], 'a missing root finds nothing');
  eq(dau_tar_exclude('/mnt/a/Plex Media Server/[x]*?'), '--exclude=mnt/a/Plex Media Server/\[x\]\*\?', 'tar wildcards are escaped');
});

t('backups: the scheduled appdata backup leaves the media caches out and says so', function () {
  $cfg = bkenv(); bk_media('/mnt/dau-test/appdata');
  $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']);
  $a = implode(' ', $GLOBALS['TARS'][0]);
  has($a, '--exclude=mnt/dau-test/appdata/plex/Library/Application Support/Plex Media Server/Cache'); has($a, '--exclude=mnt/dau-test/appdata/jellyfin/cache'); has($a, '--exclude=mnt/dau-test/appdata/EmbyServer/transcoding-temp');
  lacks($a, 'Metadata'); lacks($a, 'nextcloud');
  ok(strpos($a, '--exclude=mnt/dau-test/appdata/jellyfin/cache') < strpos($a, ' -- '), 'excludes come before the folders');
  has(implode(' ', $r['notes']), 'Left out, because the apps rebuild them or the backup already holds them: caches of Plex, Emby and Jellyfin (13 items');
  $cfg = bkenv(); $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']);
  lacks(implode(' ', $GLOBALS['TARS'][0]), 'Plex Media Server'); lacks(implode(' ', $r['notes']), 'Left out', 'no media server, no note');
});

t('backups: the free-space check does not count the caches', function () {
  $cfg = bkenv(); bk_media('/mnt/dau-test/appdata');
  $huge = 1e18;     // far more than any disk: only fits when the caches are taken off again
  mock_exec(function ($a) use ($huge) {
    $b = basename($a[0]);
    if ($b === 'du') return ['out' => (end($a) === '/mnt/dau-test/appdata' ? $huge + 4096 : (strpos(end($a), '/Plex Media Server/Cache') !== false ? $huge : 0)) . "\t" . end($a) . "\n"];
    if ($b === 'tar') { $GLOBALS['TARS'][] = $a; file_put_contents($a[2], 'archive'); return ['code' => 0]; }
    return null;
  });
  $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']);
  has(implode(' ', $r['notes']), 'the apps rebuild them');
  eq(dau_du_total([]), 0.0);
});

t('docker: the backup before an update leaves the media caches and a /cache or /transcode mount out', function () {
  @mkdir('/mnt/dau-test-bk4', 0755, true); bkenv(); bk_media('/mnt/dau-test/appdata');
  $cfg = dau_defaults(); $cfg['global']['backup'] = ['enabled' => true, 'dest' => '/mnt/dau-test-bk4', 'retention' => 2, 'config' => false, 'image' => false, 'appdata' => true, 'required' => false];
  $inspect = ['Config' => ['Image' => 'jellyfin/jellyfin:latest'], 'Mounts' => [
    ['Type' => 'bind', 'Source' => '/mnt/dau-test/appdata/jellyfin/config', 'Destination' => '/config'],
    ['Type' => 'bind', 'Source' => '/mnt/dau-test/appdata/jellyfin/cache', 'Destination' => '/cache']]];
  $r = dau_backup_run($cfg, 'jellyfin', $inspect); ok($r['ok'], $r['error']);
  $a = implode(' ', $GLOBALS['TARS'][0]);
  has($a, '--exclude=mnt/dau-test/appdata/jellyfin/config/log'); lacks($a, '/mnt/dau-test/appdata/jellyfin/cache', 'the /cache mount is not archived');
  ok(substr($a, -strlen('-- /mnt/dau-test/appdata/jellyfin/config')) === '-- /mnt/dau-test/appdata/jellyfin/config', $a);
  ok(dau_is_media_cache_mount(['Config' => ['Image' => 'lscr.io/linuxserver/plex@sha256:ab']], ['Destination' => '/transcode/']));
  ok(!dau_is_media_cache_mount(['Config' => ['Image' => 'redis:7']], ['Destination' => '/cache']), 'other apps keep their /cache');
  ok(!dau_is_media_cache_mount(['Config' => ['Image' => 'plexinc/pms-docker']], ['Destination' => '/config']));
  dau_test_rm('/mnt/dau-test-bk4');
});

t('backups: hotio\'s Plex (the appdata folder is the Plex folder itself) and a transcode folder a media container maps', function () {
  $cfg = bkenv(); $root = '/mnt/dau-test/appdata';
  @mkdir("$root/plex/Plug-in Support/Databases", 0755, true); @mkdir("$root/plex/Cache", 0755, true); @mkdir("$root/plex/Metadata", 0755, true); file_put_contents("$root/plex/Preferences.xml", 'x');
  @mkdir("$root/transcode/Transcode", 0755, true); @mkdir("$root/redis-cache", 0755, true);
  @mkdir("$root/tool/Plug-in Support", 0755, true); @mkdir("$root/tool/Cache", 0755, true);   // a Plug-in Support folder alone is not Plex
  eq(dau_media_cache_dirs([$root]), ["$root/plex/Cache"]);
  mock_exec(function ($a) {
    $b = basename($a[0]);
    if ($b === 'docker' && $a[1] === 'ps') return ['out' => "p\nr\n"];
    if ($b === 'docker' && $a[1] === 'inspect') return ['out' => json_encode([
      ['Name' => '/plex', 'Config' => ['Image' => 'ghcr.io/hotio/plex:latest'], 'Mounts' => [
        ['Type' => 'bind', 'Source' => '/mnt/user/dau-test-elsewhere/plex', 'Destination' => '/config'],
        ['Type' => 'bind', 'Source' => '/mnt/cache/appdata/../../etc', 'Destination' => '/cache'],
        ['Type' => 'bind', 'Source' => '/mnt/cache/appdata/transcode', 'Destination' => '/transcode']]],
      ['Name' => '/redis', 'Config' => ['Image' => 'redis:7'], 'Mounts' => [['Type' => 'bind', 'Source' => '/mnt/dau-test/appdata/redis-cache', 'Destination' => '/cache']]]])];
    if ($b === 'du') return ['out' => "4096\t" . end($a) . "\n"];
    if ($b === 'tar') { $GLOBALS['TARS'][] = $a; file_put_contents($a[2], 'archive'); return ['code' => 0]; }
    return null;
  });
  eq(dau_media_cache_mounts([$root]), ["$root/transcode"], 'the cache drive path is matched to the share path; other apps\' /cache is not');
  $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']);
  $a = implode(' ', $GLOBALS['TARS'][0]);
  has($a, '--exclude=mnt/dau-test/appdata/plex/Cache'); has($a, '--exclude=mnt/dau-test/appdata/transcode'); lacks($a, 'redis-cache'); lacks($a, 'tool/Cache');
});

t('backups: logs, artwork and copies other apps rebuild are left out, their databases and settings are kept', function () {
  $cfg = bkenv(['appdata' => ['exclude' => ['qui/backups', 'big']]]); $root = '/mnt/dau-test/appdata';
  $mk = function ($p, $file = null) { @mkdir($p, 0755, true); if ($file !== null) file_put_contents("$p/$file", 'x'); };
  $mk("$root/sonarr", 'config.xml'); $mk("$root/sonarr", 'sonarr.db'); $mk("$root/sonarr", 'logs.db'); foreach (['MediaCover/1', 'logs', 'Backups/scheduled', 'Sentry'] as $c) $mk("$root/sonarr/$c");
  $mk("$root/binhex-radarr/config", 'config.xml'); $mk("$root/binhex-radarr/config", 'radarr.db'); $mk("$root/binhex-radarr/config/MediaCover");
  $mk("$root/other", 'config.xml'); $mk("$root/other/logs"); $mk("$root/other/Backups");   // config.xml alone is not Sonarr
  $mk("$root/adguard/config", 'AdGuardHome.yaml'); $mk("$root/adguard/workingdir/data", 'querylog.json'); $mk("$root/adguard/workingdir/data", 'querylog.json.1'); $mk("$root/adguard/workingdir/data", 'stats.db');
  $mk("$root/dns/workingdir/data", 'querylog.json');   // no AdGuardHome.yaml: kept
  $mk("$root/immich/machine-learning/models/clip"); $mk("$root/immich/upload");
  $pms = "$root/plex/Library/Application Support/Plex Media Server"; $mk($pms, 'Preferences.xml');
  $db = "$pms/Plug-in Support/Databases"; $mk($db, 'com.plexapp.plugins.library.db'); $mk($db, 'com.plexapp.plugins.library.db-2025-05-06'); $mk($db, 'com.plexapp.plugins.library.blobs.db-2025-05-03'); $mk($db, 'com.plexapp.plugins.library.db-shm');
  $mk("$root/qui/backups"); $mk("$root/qui", 'qui.db');
  $s = dau_app_skip_paths([$root]); $p = array_keys($s); sort($p);
  $want = ["$root/adguard/workingdir/data/querylog.json", "$root/adguard/workingdir/data/querylog.json.1", "$root/binhex-radarr/config/MediaCover", "$root/immich/machine-learning/models",
    "$db/com.plexapp.plugins.library.blobs.db-2025-05-03", "$db/com.plexapp.plugins.library.db-2025-05-06",
    "$root/sonarr/Backups", "$root/sonarr/MediaCover", "$root/sonarr/Sentry", "$root/sonarr/logs", "$root/sonarr/logs.db"];
  sort($want); eq($p, $want);
  eq($s["$root/adguard/workingdir/data/querylog.json"], 'the AdGuard query log');
  $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']);
  $a = implode(' ', $GLOBALS['TARS'][0]);
  has($a, '--exclude=mnt/dau-test/appdata/qui/backups'); has($a, '--exclude=mnt/dau-test/appdata/big'); has($a, '--exclude=mnt/dau-test/appdata/sonarr/MediaCover'); has($a, '--exclude=mnt/dau-test/appdata/immich/machine-learning/models');
  lacks($a, 'sonarr.db '); lacks($a, 'stats.db'); lacks($a, 'library.db '); lacks($a, 'other/'); lacks($a, 'dns/'); lacks($a, 'immich/upload');
  $n = implode(' ', $r['notes']); has($n, 'the AdGuard query log'); has($n, 'Immich machine-learning models'); has($n, 'old copies of the Plex database'); has($n, 'artwork, logs and own backups of Sonarr, Radarr and the like');
});
