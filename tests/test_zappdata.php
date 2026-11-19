<?php
/* Appdata cleanup: orphan detection, protections, quarantine / restore / purge, permanent delete, saved templates. Real folders under /mnt/dau-test, Docker mocked. */

function adenv(array $mounts, array $o = []) {
  $root = '/mnt/dau-test/appdata';
  if (is_dir('/mnt/dau-test')) dau_test_rm('/mnt/dau-test');
  @mkdir($root, 0755, true);
  foreach (['plex', 'old-app', 'stopped-app', 'tpl-only', 'nested', 'zzz-empty'] as $d) { @mkdir("$root/$d", 0755, true); file_put_contents("$root/$d/data.bin", str_repeat('x', 4096 * (1 + strlen($d)))); }
  @mkdir("$root/nested/sub", 0755, true);
  @symlink('/tmp', "$root/linked"); @mkdir("$root/.hidden", 0755, true);
  file_put_contents("$root/plain-file.txt", 'x');
  $cfg = dau_defaults(); $cfg['appdata'] = ['roots' => [$root], 'safe_mode' => $o['safe'] ?? true]; dau_save_config($cfg);
  $GLOBALS['AD'] = ['docker_down' => !empty($o['down'])];
  $all = [];
  foreach ($mounts as $n => $m) $all[] = ['Name' => "/$n", 'State' => ['Status' => $m[0]], 'Mounts' => array_map(function ($s) { return ['Type' => 'bind', 'Source' => $s]; }, $m[1]), 'HostConfig' => ['Binds' => []]];
  mock_exec(function ($a) use ($all) {
    $j = implode(' ', $a);
    if (basename($a[0]) === 'du') { if (!is_dir(end($a))) return ['out' => "0\t" . end($a) . "\n"]; $sz = 0; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(end($a), FilesystemIterator::SKIP_DOTS)); foreach ($it as $f) $sz += $f->isFile() ? $f->getSize() : 0; return ['out' => $sz . "\t" . end($a) . "\n"]; }
    if (basename($a[0]) === 'rm') { dau_test_rm(end($a)); return null; }
    if (strpos($j, 'webGui/scripts/notify') !== false) { $GLOBALS['NOTIFY'][] = $a; return null; }
    if (strpos($j, 'docker') === false) return null;
    if (strpos($j, ' volume ls') !== false) return ['out' => "unused-vol\n" . str_repeat('a', 64) . "\n"];
    if (strpos($j, ' volume inspect') !== false) return ['out' => json_encode([['Name' => 'unused-vol', 'Mountpoint' => '/var/lib/docker/volumes/unused-vol/_data', 'CreatedAt' => 'x'], ['Name' => str_repeat('a', 64), 'Mountpoint' => '/var/lib/docker/volumes/x/_data']])];
    if (strpos($j, ' volume rm') !== false) { $GLOBALS['VOLRM'][] = end($a); return null; }
    if (strpos($j, ' image ls') !== false || strpos($j, ' image inspect') !== false) return null;
    if ($GLOBALS['AD']['docker_down']) return ['code' => 1, 'err' => 'Cannot connect'];
    if (preg_match('# ps -aq#', $j)) return ['out' => implode("\n", array_map(function ($i) { return "id$i"; }, array_keys($all))) . "\n"];
    if (strpos($j, ' inspect ') !== false) return ['out' => json_encode($all)];
    return null;
  });
  return $root;
}

t('appdata: only folders no container maps are offered; protections hold', function () {
  $root = adenv(['plex' => ['running', ['/mnt/user/dau-nope', '/mnt/dau-test/appdata/plex']], 'old-stopped' => ['exited', ['/mnt/dau-test/appdata/stopped-app/config']],
    'tool' => ['running', ['/mnt/dau-test']]]);
  file_put_contents(DAU_TEMPLATES . '/my-gone.xml', '<Container><Name>gone</Name><Repository>x/gone</Repository><Config Name="c" Type="Path" Target="/c">/mnt/dau-test/appdata/tpl-only/cfg</Config></Container>');
  $s = dau_appdata_scan(); ok($s['ok'], json_encode($s));
  $names = array_column($s['orphans'], 'name'); sort($names);
  eq($names, ['nested', 'old-app', 'tpl-only', 'zzz-empty'], 'plex (running) and stopped-app (stopped container) are in use');
  eq($s['in_use'], 2); 
  eq(array_column($s['protected'], 'name'), ['linked']); eq($s['protected'][0]['why'], 'symbolic link');
  ok(in_array('tool', $s['broad']), 'a mount of a parent folder is listed, not counted as ownership');
  $tpl = array_values(array_filter($s['orphans'], function ($o) { return $o['name'] === 'tpl-only'; }))[0]; eq($tpl['tpl'], ['gone']);
  ok($s['total'] > 0 && $s['orphans'][0]['size'] >= $s['orphans'][1]['size'], 'biggest first'); eq(count(array_filter($s['orphans'], function ($o) { return $o['name'] === '.hidden'; })), 0);
});

t('appdata: a stopped container still protects its folder (also through another disk name)', function () {
  adenv(['plex' => ['running', ['/mnt/cache/appdata/plex']], 'sleeper' => ['exited', ['/mnt/disk1/appdata/stopped-app/db']]]);
  $s = dau_appdata_scan(); $names = array_column($s['orphans'], 'name'); sort($names);
  eq($names, ['nested', 'old-app', 'tpl-only', 'zzz-empty']); eq($s['in_use'], 2);
});

t('appdata: when Docker cannot be asked nothing is offered', function () {
  adenv([], ['down' => true]); $s = dau_appdata_scan(); eq($s['ok'], false); has($s['error'], 'not running');
  $r = dau_appdata_run(['0:old-app'], 'quarantine', false, false); eq($r['ok'], false); ok(is_dir('/mnt/dau-test/appdata/old-app'));
});

t('appdata: dry run changes nothing; quarantine moves, restore and purge work', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]);
  $r = dau_appdata_run(['0:old-app', '0:plex', '0:../etc'], 'quarantine', true, false);
  eq(array_column($r['would'], 'name'), ['old-app']); eq(count($r['skipped']), 2); ok(is_dir("$root/old-app")); eq(dau_quarantine_load(), []);
  $r = dau_appdata_run(['0:old-app', '0:plex'], 'quarantine', false, false); eq($r['done'], ['old-app']); eq(count($r['skipped']), 1);
  ok(!is_dir("$root/old-app") && is_dir("$root/plex"), 'plex (in use) was refused');
  $q = dau_quarantine_list()['rows']; eq(count($q), 1); ok($q[0]['exists'] && $q[0]['free'], 'restorable'); ok(strpos($q[0]['path'], '/.unraid-autoupdater-quarantine/old-app__') !== false);
  ok(!in_array('.unraid-autoupdater-quarantine', array_column(dau_appdata_scan()['orphans'], 'name')), 'quarantine is not offered as an orphan');
  /* restore, then conflict handling */
  $id = $q[0]['id']; $r = dau_quarantine_act($id, 'restore'); ok($r['ok']); ok(is_file("$root/old-app/data.bin")); eq(dau_quarantine_load(), []);
  dau_appdata_run(['0:old-app'], 'quarantine', false, false); $id = dau_quarantine_list()['rows'][0]['id']; @mkdir("$root/old-app"); file_put_contents("$root/old-app/new.txt", 'n');
  $r = dau_quarantine_act($id, 'restore'); eq($r['ok'], false); ok(!empty($r['conflict'])); ok(is_file("$root/old-app/new.txt") && !is_file("$root/old-app/data.bin"), 'nothing overwritten');
  $r = dau_quarantine_act($id, 'restore', true); ok($r['ok']); ok(is_file("$root/old-app-restored/data.bin"), 'restored beside it'); ok(is_file("$root/old-app/new.txt"));
  /* purge */
  dau_appdata_run(['0:nested'], 'quarantine', false, false); $e = dau_quarantine_list()['rows'][0]; $r = dau_quarantine_act($e['id'], 'purge'); ok($r['ok']); ok(!is_dir($e['path'])); eq(dau_quarantine_load(), []);
  /* a tampered record cannot reach outside the quarantine folder */
  $q = [['id' => 'abcdefabcdef', 'name' => 'x', 'original' => "$root/x", 'path' => "$root/plex", 'root' => $root, 'size' => 1, 'at' => 1]]; dau_quarantine_save($q);
  $r = dau_quarantine_act('abcdefabcdef', 'purge'); eq($r['ok'], false); ok(is_dir("$root/plex") && is_file("$root/plex/data.bin"), 'plex untouched');
});

t('appdata: permanent delete needs Safe mode off and a confirmation', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]);
  $r = dau_appdata_run(['0:old-app'], 'delete', false, true); eq($r['ok'], false); has($r['error'], 'Safe mode'); ok(is_dir("$root/old-app"));
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]], ['safe' => false]);
  $r = dau_appdata_run(['0:old-app'], 'delete', false, false); eq($r['ok'], false); ok(is_dir("$root/old-app"), 'no confirmation, no delete');
  $r = dau_appdata_run(['0:old-app', '0:plex'], 'delete', true, true); eq(array_column($r['would'], 'name'), ['old-app']); ok(is_dir("$root/old-app"), 'dry run');
  $r = dau_appdata_run(['0:old-app', '0:plex'], 'delete', false, true); eq($r['done'], ['old-app']); ok(!is_dir("$root/old-app") && is_dir("$root/plex")); ok($r['freed'] > 0);
  foreach (calls_matching(' rm ') as $a) ok(in_array('--one-file-system', $a, true));
});

t('appdata: settings are validated', function () {
  $c = dau_sanitize_config(['appdata' => ['roots' => ['/mnt/user/appdata', '/etc', '/mnt/user/../x', 'rel', '/mnt/cache/apps/'], 'safe_mode' => false]], dau_defaults());
  eq($c['appdata']['roots'], ['/mnt/user/appdata', '/mnt/cache/apps']); eq($c['appdata']['safe_mode'], false);
  $c = dau_sanitize_config(['appdata' => ['roots' => ['/etc']]], dau_defaults()); eq($c['appdata']['roots'], ['/mnt/user/appdata'], 'all bad = unchanged'); eq($c['appdata']['safe_mode'], true, 'safe by default');
});

t('appdata: saved templates of removed containers can be archived and restored', function () {
  adenv(['live' => ['running', []]]);
  foreach (['live', 'gone'] as $n) file_put_contents(DAU_TEMPLATES . "/my-$n.xml", "<Container><Name>$n</Name><Repository>x/$n</Repository></Container>");
  $s = dau_tpl_scan(); eq(array_column($s['templates'], 'name'), ['gone']);
  $r = dau_tpl_act('archive', 'live'); eq($r['ok'], false); ok(is_file(DAU_TEMPLATES . '/my-live.xml'), 'a template of an installed container is refused');
  $r = dau_tpl_act('archive', 'gone'); ok($r['ok'], json_encode($r)); ok(!is_file(DAU_TEMPLATES . '/my-gone.xml')); ok(is_file(DAU_TPL_BACKUPS . '/' . $r['backup']));
  $s = dau_tpl_scan(); eq($s['templates'], []); eq(count($s['backups']), 1); ok($s['backups'][0]['free']);
  $f = $s['backups'][0]['file']; $r = dau_tpl_act('restore', '', $f); ok($r['ok']); ok(is_file(DAU_TEMPLATES . '/my-gone.xml'));
  $r = dau_tpl_act('restore', '', $f); eq($r['ok'], false); has($r['error'], 'Nothing was overwritten');
  $r = dau_tpl_act('restore', '', '../../etc/passwd'); eq($r['ok'], false); $r = dau_tpl_act('drop', '', $f); ok($r['ok']); eq(dau_tpl_scan()['backups'], []);
});


t('appdata: ignore list, recent warning and looking inside a folder', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]);
  touch("$root/old-app", gmmktime(0, 0, 0, 1, 1, 2025)); touch("$root/tpl-only", dau_now() - 86400);
  $s = dau_appdata_scan(); $by = []; foreach ($s['orphans'] as $o) $by[$o['name']] = $o;
  eq($by['old-app']['recent'], false); eq($by['tpl-only']['recent'], true, 'changed yesterday = recent');
  ok(dau_appdata_ignore('nested', true)['ok']); $s = dau_appdata_scan(); ok(!in_array('nested', array_column($s['orphans'], 'name'))); eq($s['ignored'], ['nested']);
  $r = dau_appdata_run(['0:nested'], 'quarantine', false, false); eq($r['done'], [], 'an ignored folder cannot be moved'); ok(is_dir("$root/nested"));
  ok(dau_appdata_ignore('nested', false)['ok']); ok(in_array('nested', array_column(dau_appdata_scan()['orphans'], 'name')));
  eq(dau_appdata_ignore('../etc', true)['ok'], false); eq(dau_appdata_ignore('.hidden', true)['ok'], false);
  $p = dau_appdata_peek('0:nested'); ok($p['ok']); eq($p['items'][0]['name'], 'data.bin'); eq($p['count'], 2); ok($p['items'][0]['size'] > 0);
  eq(dau_appdata_peek('0:../x')['ok'], false); eq(dau_appdata_peek('0:linked')['ok'], false); eq(dau_appdata_peek('9:nested')['ok'], false); eq(dau_appdata_peek('0:nope')['ok'], false);
});

t('appdata: quarantine expires only when a time was set', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]);
  dau_appdata_run(['0:old-app'], 'quarantine', false, false); $GLOBALS['DAU_NOW'] += 5 * 86400; dau_appdata_run(['0:nested'], 'quarantine', false, false); $GLOBALS['DAU_NOW'] += 4 * 86400;
  $cfg = dau_load_config(); eq(dau_quarantine_expire($cfg), 0, 'off by default'); eq(count(dau_quarantine_load()), 2);
  $cfg['appdata']['purge_days'] = 7; dau_save_config($cfg); eq(dau_quarantine_expire(dau_load_config()), 1, 'only the 9 day old one'); $q = dau_quarantine_load(); eq(count($q), 1); eq($q[0]['name'], 'nested');
  ok(is_dir($q[0]['path']), 'the younger one stays');
});

t('appdata: unused volumes are listed and removed without force', function () {
  adenv([]); $GLOBALS['VOLRM'] = [];
  $s = dau_cleanup_scan(); ok(isset($s['volumes']), json_encode($s)); eq(array_column($s['volumes'], 'name'), ['unused-vol', str_repeat('a', 64)]); ok($s['volumes'][1]['anonymous']);
  $r = dau_cleanup_run([], [], ['unused-vol', 'in-use-vol', '../x']); eq($GLOBALS['VOLRM'], ['unused-vol']); eq(count($r['skipped']), 2); eq($r['removed'], ['volume unused-vol']);
  foreach (calls_matching(' volume rm') as $a) ok(!in_array('-f', $a, true) && !in_array('--force', $a, true), 'never forced');
});

t('appdata: weekly report counts, notifies once and deletes nothing', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]); @mkdir(dirname(DAU_NOTIFY), 0755, true); file_put_contents(DAU_NOTIFY, 'x'); $GLOBALS['NOTIFY'] = [];
  $cfg = dau_load_config(); ok(!dau_cleanup_due($cfg, dau_state_load(), dau_now()), 'off by default');
  $cfg['appdata']['report'] = true; $cfg['notify_unraid']['docker'] = true; dau_save_config($cfg); $cfg = dau_load_config(); ok(dau_cleanup_due($cfg, dau_state_load(), dau_now()));
  $st = dau_state_load(); $J = dau_job_new('scheduled', $cfg, $st); dau_job_cleanup($J);
  eq(count($J['events']), 1); eq($J['events'][0]['kind'], 'cleanup_report'); has($J['events'][0]['detail'], 'Unused appdata folders: 5'); eq($J['st']['cleanup']['appdata']['n'], 5); ok($J['st']['cleanup']['appdata']['size'] > 0);
  dau_job_finish($J); ok(count($GLOBALS['NOTIFY']) === 1, 'one Unraid notification'); has(json_encode($GLOBALS['NOTIFY']), 'space you can free');
  foreach (['old-app', 'nested', 'tpl-only', 'zzz-empty', 'stopped-app'] as $d) ok(is_dir("$root/$d"), "$d untouched");
  /* the same numbers a day later: no second message; a week later with the same numbers: still none */
  $GLOBALS['DAU_NOW'] += 86400 + 10; $st = dau_state_load(); ok(dau_cleanup_due($cfg, $st, dau_now())); $J = dau_job_new('scheduled', $cfg, $st); dau_job_cleanup($J); eq($J['events'], []);
  $GLOBALS['DAU_NOW'] += 8 * 86400; @mkdir("$root/brand-new"); $st = dau_state_load(); $J = dau_job_new('scheduled', $cfg, $st); dau_job_cleanup($J); eq(count($J['events']), 1, 'numbers changed and a week passed');
  /* quiet hours hold it like any other notification */
  $cfg['quiet'] = ['enabled' => true, 'days' => [0, 1, 2, 3, 4, 5, 6], 'start' => '00:00', 'end' => '23:59', 'urgent' => true]; $J['cfg'] = $cfg; $GLOBALS['NOTIFY'] = []; dau_job_finish($J); eq($GLOBALS['NOTIFY'], []); eq(count($J['st']['queue']), 1);
});

t('appdata: report settings are validated', function () {
  $c = dau_sanitize_config(['appdata' => ['purge_days' => 9999, 'report' => '1', 'ignore' => ['ok', '../bad', '.hid', 'a/b', 'fine folder']]], dau_defaults());
  eq($c['appdata']['purge_days'], 365); eq($c['appdata']['report'], true); eq($c['appdata']['ignore'], ['ok', 'fine folder']);
});


t('cleanup: every item carries a status and a plain-language reason; in-use items are not offered', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]); touch("$root/old-app", gmmktime(0, 0, 0, 1, 1, 2025)); touch("$root/tpl-only", dau_now() - 86400);
  file_put_contents(DAU_TEMPLATES . '/my-gone.xml', '<Container><Name>gone</Name><Config Type="Path" Target="/c">/mnt/dau-test/appdata/nested/cfg</Config></Container>');
  $s = dau_appdata_scan(); $by = []; foreach ($s['orphans'] as $o) $by[$o['name']] = $o;
  eq($by['old-app']['status'], 'eligible'); eq($by['tpl-only']['status'], 'review', 'changed yesterday'); eq($by['nested']['status'], 'review', 'a template points here'); has($by['nested']['reason'], 'saved template');
  eq($s['protected'][0]['status'], 'protected'); ok($s['protected'][0]['reason'] !== ''); eq($s['in_use_rows'], [['name' => 'plex', 'by' => ['plex']]]);
  foreach ($by as $o) ok($o['reason'] !== '' && in_array($o['status'], ['eligible', 'review'], true));
  $d = dau_cleanup_scan(); foreach ($d['volumes'] as $v) eq($v['status'], 'review', 'a volume that is not provably empty is never pre-approved'); ok(isset($d['in_use_images']));
});

t('cleanup: older copies of an image are Safe, the newest copy and the rollback image are not', function () {
  $id = function ($c) { return 'sha256:' . str_repeat($c, 64); };
  $GLOBALS['IMGS'] = [
    [$id('1'), 'app', 'v1', '2026-01-01T10:00:00.123456789+05:30'], [$id('2'), 'app', 'v2', '2026-02-01T10:00:00Z'], [$id('3'), 'app', 'v3', '2026-03-01T10:00:00Z'],
    [$id('4'), '<none>', '<none>', '2025-01-01T00:00:00Z'], [$id('5'), 'reg.lan:5000/tool', '1', '2025-01-01T00:00:00Z'], [$id('6'), 'reg.lan:5000/tool', '2', ''],
    [$id('7'), 'solo', 'latest', '2024-01-01T00:00:00Z'], [$id('8'), 'base', 'old', '2024-01-01T00:00:00Z'], [$id('9'), 'base', 'new', '2025-01-01T00:00:00Z'],
  ];
  mock_exec(function ($a) {
    $j = implode(' ', $a);
    if ($a[1] === 'ps') return ['out' => ''];
    if (strpos($j, 'image ls') !== false) return ['out' => implode("\n", array_map(function ($i) { return "$i[0]|$i[1]|$i[2]|a while ago"; }, $GLOBALS['IMGS'])) . "\n"];
    if (strpos($j, 'image inspect') !== false) return ['out' => implode("\n", array_map(function ($i) { return trim("$i[0] 100 $i[3]"); }, $GLOBALS['IMGS'])) . "\n"];
    if (strpos($j, 'volume ls') !== false) return ['out' => ''];
    return null;
  });
  $st = dau_state_load(); $st['docker']['rollback']['base'] = ['prev_id' => $id('8'), 'ref' => 'base@sha256:x', 'at' => 1]; dau_state_save($st);
  $s = dau_cleanup_scan(); $by = []; foreach ($s['images'] as $i) $by[$i['id']] = $i;
  eq($by[$id('1')]['status'], 'eligible', 'an older copy'); eq($by[$id('2')]['status'], 'eligible'); ok(!empty($by[$id('1')]['older'])); has($by[$id('1')]['reason'], 'app:v3 is kept for a rollback');
  eq($by[$id('3')]['status'], 'review', 'the newest copy stays'); has($by[$id('3')]['reason'], 'newest of 3 unused copies of app');
  eq($by[$id('4')]['status'], 'eligible', 'a nameless image');
  eq($by[$id('5')]['status'], 'review', 'a copy without a date makes the repository unsure'); eq($by[$id('6')]['status'], 'review');
  eq($by[$id('7')]['status'], 'review', 'the only copy of an app');
  eq($by[$id('8')]['status'], 'review', 'the Rollback button needs it'); has($by[$id('8')]['reason'], 'Rollback button of base'); eq($by[$id('9')]['status'], 'review');
  eq(dau_image_repo('reg.lan:5000/tool:2'), 'reg.lan:5000/tool'); eq(dau_image_repo('reg.lan:5000/tool'), 'reg.lan:5000/tool'); eq(dau_image_repo('lscr.io/x/app:1.2'), 'lscr.io/x/app');
  $sum = dau_cleanup_summary(['ok' => false], $s); eq($sum['safe'], ['n' => 3, 'size' => 300], 'the summary counts what Smart cleanup (safe) takes');
});

t('cleanup: an empty folder and an empty volume are Safe; a size of 0 alone is not enough', function () {
  $root = adenv([]); @mkdir("$root/fresh-empty"); touch("$root/fresh-empty", dau_now());
  $s = dau_appdata_scan(); $by = []; foreach ($s['orphans'] as $o) $by[$o['name']] = $o;
  eq($by['fresh-empty']['status'], 'eligible', 'changed today, but empty'); ok($by['fresh-empty']['empty']); has($by['fresh-empty']['reason'], 'it is empty');
  ok(!$by['tpl-only']['empty']); ok(!dau_dir_empty("$root/plain-file.txt") && !dau_dir_empty("$root/nope") && !dau_dir_empty("$root/linked"));
  $vroot = '/var/lib/docker/volumes';      // the bootstrap's own /var/lib/docker: tests run only in a throwaway container
  @mkdir("$vroot/dau-test-empty/_data", 0755, true); @mkdir("$vroot/dau-test-full/_data", 0755, true); file_put_contents("$vroot/dau-test-full/_data/x", '');
  mock_exec(function ($a) use ($vroot) {
    $j = implode(' ', $a);
    if (basename($a[0]) === 'du') return ['out' => "0\t" . end($a) . "\n"];      // du says 0 for both: only the empty one may be Safe
    if ($a[1] === 'ps') return ['out' => ''];
    if (strpos($j, 'volume ls') !== false) return ['out' => "dau-test-empty\ndau-test-full\ndau-test-gone\n"];
    if (strpos($j, 'volume inspect') !== false) return ['out' => json_encode([['Name' => 'dau-test-empty', 'Mountpoint' => "$vroot/dau-test-empty/_data"], ['Name' => 'dau-test-full', 'Mountpoint' => "$vroot/dau-test-full/_data"], ['Name' => 'dau-test-gone', 'Mountpoint' => "$vroot/dau-test-gone/_data"]])];
    return ['out' => ''];
  });
  $d = dau_cleanup_scan(); $v = []; foreach ($d['volumes'] as $x) $v[$x['name']] = $x;
  eq($v['dau-test-empty']['status'], 'eligible'); ok($v['dau-test-empty']['empty']);
  eq($v['dau-test-full']['status'], 'review', 'an empty file is still content'); eq($v['dau-test-gone']['status'], 'review', 'a missing folder is not proof of emptiness');
  foreach (['dau-test-empty', 'dau-test-full'] as $n) dau_test_rm("$vroot/$n");
});

t('cleanup: the figures of Docker, the build cache and where an image came from', function () {
  eq(dau_docker_bytes('4.691GB (10%)'), 4691000000); eq(dau_docker_bytes('25.34GB'), 25340000000); eq(dau_docker_bytes('0B'), 0); eq(dau_docker_bytes('3.351MB (100%)'), 3351000); eq(dau_docker_bytes('junk'), 0);
  $id = function ($c) { return 'sha256:' . str_repeat($c, 64); };
  mock_exec(function ($a) use ($id) {
    $j = implode(' ', $a);
    if ($a[1] === 'ps') return ['out' => ''];
    if (strpos($j, 'image ls') !== false) return ['out' => $id('a') . "|mine/app|1|x\n" . $id('b') . "|lscr.io/x/tool|2|x\n"];
    if (strpos($j, 'image inspect') !== false) return ['out' => $id('a') . " 100 2026-01-01T00:00:00Z 0\n" . $id('b') . " 200 2026-01-01T00:00:00Z 1\n"];
    if (strpos($j, 'system df') !== false) return ['out' => "Images|4.691GB (10%)\nContainers|0B (0%)\nLocal Volumes|0B\nBuild Cache|25.34GB\n"];
    if (strpos($j, 'builder prune') !== false) { $GLOBALS['PRUNE'][] = $a; return ['out' => "ID x\nTotal:\t25.34GB\n"]; }
    return ['out' => ''];
  });
  $s = dau_cleanup_scan(); ok(!isset($s['reclaim']) && !isset($s['cache']), 'the quick scan does not ask docker system df'); eq(calls_matching('system df'), []);
  $by = []; foreach ($s['images'] as $i) $by[$i['id']] = $i;
  ok($by[$id('a')]['local'], 'no registry digest: built here'); has($by[$id('a')]['reason'], 'cannot be downloaded again'); ok(!$by[$id('b')]['local']);
  $f = dau_cleanup_scan(true); eq($f['reclaim'], ['images' => 4691000000, 'cache' => 25340000000]); eq($f['cache']['status'], 'eligible'); eq($f['cache']['size'], 25340000000);
  $sum = dau_cleanup_summary(['ok' => false], $f); eq($sum['cache'], ['n' => 1, 'size' => 25340000000]); eq($sum['freeable'], 4691000000 + 25340000000, 'images as Docker counts them, plus the rest');
  $GLOBALS['PRUNE'] = [];
  $r = dau_cleanup_run([], [], [], true, true); eq($r['would'], ['Docker build cache (only what nothing uses)']); eq($GLOBALS['PRUNE'], [], 'a preview prunes nothing');
  $r = dau_cleanup_run([], [], [], false, true); eq($r['removed'], ['Docker build cache']); eq($r['size_removed'], 25340000000); eq($GLOBALS['PRUNE'], [['docker', 'builder', 'prune', '-f']], 'unused cache only, never --all');
});

t('cleanup: copies of a folder are Safe when the original is there, and older versions when it is gone', function () {
  $root = adenv([]);
  foreach (['app', 'app.bak-0.9', 'app-old', 'tool.v1.2', 'tool.v1.10', 'tool.v1.9', 'site_20260101', 'lone.v2'] as $d) { @mkdir("$root/$d"); file_put_contents("$root/$d/f", 'x'); touch("$root/$d", dau_now() - 86400); }
  file_put_contents(DAU_TEMPLATES . '/my-pinned.xml', '<Container><Name>pinned</Name><Config Type="Path" Target="/c">/mnt/dau-test/appdata/tool.v1.2</Config></Container>');
  $s = dau_appdata_scan(); $by = []; foreach ($s['orphans'] as $o) $by[$o['name']] = $o;
  eq($by['app.bak-0.9']['status'], 'eligible', 'changed yesterday, but a copy of app'); eq($by['app.bak-0.9']['copy_of'], 'app'); has($by['app.bak-0.9']['reason'], 'A copy of app');
  eq($by['app-old']['status'], 'eligible'); eq($by['app']['status'], 'review', 'the original stays');
  eq($by['tool.v1.10']['status'], 'review', 'the newest version (1.10 > 1.9) is kept'); eq($by['tool.v1.9']['status'], 'eligible'); has($by['tool.v1.9']['reason'], 'the newer tool.v1.10 is kept');
  eq($by['tool.v1.2']['status'], 'review', 'a saved template points to it');
  eq($by['lone.v2']['status'], 'review', 'a single version of a gone folder is not a copy of anything'); eq($by['site_20260101']['status'], 'review');
  eq(dau_folder_copy_parts('webapp-docker.bak-0.9.7'), ['webapp-docker', '0.9.7']); eq(dau_folder_copy_parts('myapp-src.v0.12.1'), ['myapp-src', '0.12.1']); eq(dau_folder_copy_parts('plex'), null);
  @unlink(DAU_TEMPLATES . '/my-pinned.xml');
});

t('cleanup: a built-here image is saved to quarantine before removal when asked, and can be loaded back', function () {
  $root = adenv([]); $cfg = dau_load_config(); $cfg['appdata']['keep_images'] = true; dau_save_config($cfg);
  $id = 'sha256:' . str_repeat('c', 64); $GLOBALS['IRM'] = []; $GLOBALS['ILOAD'] = []; $GLOBALS['SAVEFAIL'] = false;
  mock_exec(function ($a) use ($id) {
    $j = implode(' ', $a);
    if ($a[1] === 'ps') return ['out' => ''];
    if (strpos($j, 'image ls') !== false) return ['out' => "$id|mine/app|1|x\n"];
    if (strpos($j, 'image inspect') !== false) return ['out' => "$id 100 2026-01-01T00:00:00Z 0\n"];
    if (strpos($j, 'image save') !== false) { if ($GLOBALS['SAVEFAIL']) return ['code' => 1, 'err' => 'no space left']; file_put_contents($a[4], 'tar'); return null; }
    if (strpos($j, 'image rm') !== false) { $GLOBALS['IRM'][] = $a; return null; }
    if (strpos($j, 'image load') !== false) { $GLOBALS['ILOAD'][] = end($a); return null; }
    return ['out' => ''];
  });
  $r = dau_cleanup_run([$id], [], [], true); has($r['would'][0], 'a copy is kept in quarantine'); eq(calls_matching('image save'), [], 'a preview saves nothing');
  $GLOBALS['SAVEFAIL'] = true; $r = dau_cleanup_run([$id], []); eq($GLOBALS['IRM'], [], 'no copy, no removal'); has($r['failed'][0]['why'], 'a copy could not be kept');
  $GLOBALS['SAVEFAIL'] = false; $r = dau_cleanup_run([$id], []); eq(count($GLOBALS['IRM']), 1); eq($r['kept'], ['mine/app:1']);
  $q = dau_quarantine_list()['rows']; eq($q[0]['type'], 'image'); ok($q[0]['exists']); eq($q[0]['name'], 'mine/app:1'); ok(strpos($q[0]['path'], "$root/" . DAU_QDIR_NAME . '/images/') === 0, $q[0]['path']);
  $ok = dau_quarantine_act($q[0]['id'], 'restore'); ok($ok['ok'], json_encode($ok)); eq(count($GLOBALS['ILOAD']), 1); ok(!file_exists($q[0]['path']), 'the copy is gone once loaded'); eq(dau_quarantine_load(), []);
  /* an older copy is never kept: a newer copy exists anyway */
  $cfg['appdata']['keep_images'] = false; dau_save_config($cfg); ok(!dau_image_keep_wanted(['local' => true, 'older' => false, 'tags' => ['a:1']]));
  $cfg['appdata']['keep_images'] = true; dau_save_config($cfg); ok(!dau_image_keep_wanted(['local' => true, 'older' => true, 'tags' => ['a:1']])); ok(!dau_image_keep_wanted(['local' => false, 'tags' => ['a:1']])); ok(dau_image_keep_wanted(['local' => true, 'tags' => ['a:1']]));
});

t('cleanup: the weekly Smart cleanup (safe) takes only Safe items, once a week, and says what it did', function () {
  $root = adenv(['plex' => ['running', ['/mnt/dau-test/appdata/plex']]]); touch("$root/old-app", gmmktime(0, 0, 0, 1, 1, 2025)); $GLOBALS['NOTIFY'] = [];
  $cfg = dau_load_config(); ok(!dau_cleanup_due($cfg, dau_state_load(), dau_now()), 'off by default');
  $cfg['appdata']['auto_clean'] = true; dau_save_config($cfg); $cfg = dau_load_config(); ok(dau_cleanup_due($cfg, dau_state_load(), dau_now()));
  $st = dau_state_load(); $J = dau_job_new('scheduled', $cfg, $st); dau_job_cleanup($J);
  ok(!is_dir("$root/old-app"), 'the Safe folder went to quarantine'); eq(count(dau_quarantine_load()), 1);
  foreach (['nested', 'tpl-only', 'zzz-empty', 'stopped-app', 'plex'] as $d) ok(is_dir("$root/$d"), "$d (Check first or in use) untouched");
  eq($J['events'][0]['kind'], 'cleanup_auto'); has($J['events'][0]['detail'], 'Folder old-app (to quarantine)');
  $h = dau_history_load(); eq($h[0]['name'], 'Scheduled Smart cleanup (safe)'); eq($h[0]['trigger'], 'scheduled');
  eq($J['st']['cleanup']['auto_at'], dau_now()); eq($J['st']['cleanup']['appdata']['n'], 4, 'the numbers are read again afterwards');
  eq(calls_matching(' volume rm'), [], 'a volume that may hold data is never touched');
  /* the next day: no second run */
  $GLOBALS['DAU_NOW'] += 86400 + 10; touch("$root/nested", gmmktime(0, 0, 0, 1, 1, 2025)); $J2 = dau_job_new('scheduled', $cfg, $J['st']); dau_job_cleanup($J2);
  ok(is_dir("$root/nested"), 'only once a week'); eq($J2['events'], []);
});

t('cleanup: a look inside a volume or at a leftover container only for items the scan lists', function () {
  mock_exec(function ($a) { $j = implode(' ', $a); if ($a[1] === 'ps') return ['out' => '']; if (strpos($j, 'volume ls') !== false) return ['out' => "free-vol\n"]; if (strpos($j, 'volume inspect') !== false) return ['out' => "/etc\n"]; return ['out' => '']; });
  eq(dau_volume_peek('../x')['ok'], false); has(dau_volume_peek('busy-vol')['error'], 'in use'); has(dau_volume_peek('free-vol')['error'], 'cannot be read', 'a mount point outside Docker\'s volume folder is refused');
  eq(dau_container_logs('../x')['ok'], false); has(dau_container_logs('plex')['error'], 'not a leftover container'); eq(calls_matching(' logs '), []);
});

t('cleanup: a dry run of the Docker cleanup changes nothing and reports sizes', function () {
  adenv([]); $GLOBALS['VOLRM'] = [];
  $r = dau_cleanup_run([], [], ['unused-vol', 'nope'], true); eq($r['dry'], true); eq($r['would'], ['volume unused-vol']); eq($r['removed'], []); eq($GLOBALS['VOLRM'], [], 'nothing removed'); eq(count($r['skipped']), 1);
  eq(calls_matching(' volume rm'), []);
});

t('widget: the Dashboard tile never shows the Unraid OS version and uses the plugin logo', function () {
  $page = file_get_contents(__DIR__ . '/../src/UnraidSwissManagerDash.page'); $api = file_get_contents(__DIR__ . '/../src/include/api.php');
  lacks($page, 'os_installed'); lacks($page, 'j.os+'); lacks($page, "'Unraid '+"); has($page, 'images/unraid-swiss-manager.svg');
  lacks($page, 'class="w25"', 'no unstyled label columns (labels ran into their values on Unraid 7)'); has($page, "P+'?tab='+tab"); has($page, '#dau-tile .dau-card'); has($page, "P+'?tab=activity'");
  has(file_get_contents(__DIR__ . '/../src/assets/app.js'), "q.get('tab')");
  $w = substr($api, strpos($api, "case 'widget'"), 1400); lacks($w, 'os_installed'); lacks($w, 'dau_os_version'); has($w, 'os_update');
});
