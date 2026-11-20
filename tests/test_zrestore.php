<?php
/* One-click restore: a real tar round trip under /mnt/dau-test; Docker and Unraid's rebuild_container are simulated. */

/* Simulated Docker: containers (name => [running, net, image]), custom networks, and every call in $GLOBALS['RS']['log']. */
function rs_docker(array $containers, array $nets) {
  $GLOBALS['RS'] = ['c' => $containers, 'n' => $nets, 'log' => []];
  mock_exec(function ($a) {
    $R =& $GLOBALS['RS']; $b = basename($a[0]);
    if ($b === 'tar' || $b === 'rm') { exec(implode(' ', array_map('escapeshellarg', $a)) . ' 2>&1', $o, $code); return ['code' => $code, 'out' => implode("\n", $o), 'err' => $code > 1 ? implode("\n", $o) : '']; }
    if ($b === 'du') return ['out' => ($R['du'][end($a)] ?? 1000) . "\t" . end($a) . "\n"];
    if (isset($a[1]) && basename($a[1]) === 'rebuild_container') { $n = $a[2]; $R['log'][] = "rebuild $n"; $R['c'][$n] = ['running' => true, 'net' => 'bridge', 'image' => "img/$n"]; return ['code' => 0]; }
    if ($b !== 'docker') return null;
        if ($a[1] === 'ps') return ['out' => implode("\n", array_keys($R['c'])) . "\n"];
    if ($a[1] === 'inspect') {
      $want = array_slice($a, 2); $o = [];
      foreach ($R['c'] as $n => $c) if (in_array($n, $want, true))
        $o[] = ['Name' => "/$n", 'Id' => "id$n", 'State' => ['Running' => $c['running'], 'Status' => $c['running'] ? 'running' : 'exited'], 'Config' => ['Image' => $c['image']], 'HostConfig' => ['NetworkMode' => $c['net']],
          'Mounts' => [['Type' => 'bind', 'Source' => "/mnt/dau-test/appdata/$n", 'Destination' => '/config']]];
      return $o ? ['out' => json_encode($o)] : ['code' => 1, 'out' => '[]', 'err' => 'No such object'];
    }
    if ($a[1] === 'network' && $a[2] === 'ls') return ['out' => implode("\n", array_merge(in_array('type=custom', $a, true) ? [] : ['bridge', 'host', 'none'], array_keys($R['n']))) . "\n"];
    if ($a[1] === 'network' && $a[2] === 'inspect') { $o = []; foreach ($R['n'] as $n => $sub) $o[] = ['Name' => $n, 'Driver' => 'bridge', 'IPAM' => ['Config' => [['Subnet' => $sub, 'Gateway' => '']]]]; return ['out' => json_encode($o)]; }
    if ($a[1] === 'network' && $a[2] === 'create') { $R['n'][end($a)] = $a[array_search('--subnet', $a, true) + 1] ?? ''; $R['log'][] = 'network ' . end($a); return ['code' => 0]; }
    if ($a[1] === 'stop' || $a[1] === 'start') { $n = end($a); $R['log'][] = $a[1] . " $n"; if (isset($R['c'][$n])) $R['c'][$n]['running'] = $a[1] === 'start'; return ['code' => 0]; }
    return ['code' => 0];
  });
}

function rs_env() {
  $root = '/mnt/dau-test/appdata';
  if (is_dir('/mnt/dau-test')) dau_test_rm('/mnt/dau-test');
  foreach (glob(DAU_TEMPLATES . '/my-*.xml') ?: [] as $f) @unlink($f);
  @unlink(DAU_AUTOSTART); @unlink(DAU_RS_KIT); dau_quarantine_save([]);
  foreach (['app', 'vpn', 'torrent'] as $n) { @mkdir("$root/$n", 0755, true); file_put_contents("$root/$n/settings.txt", "$n v1"); file_put_contents(DAU_TEMPLATES . "/my-$n.xml", "<Container><Name>$n</Name></Container>"); }
  @mkdir("$root/big-copies", 0755, true); file_put_contents("$root/big-copies/old.zip", 'zip');
  @mkdir('/mnt/dau-test/backups', 0755, true);
  @mkdir(DAU_DM . '/scripts', 0755, true); file_put_contents(DAU_DM . '/scripts/rebuild_container', "<?php // stand-in: the mock answers for it\n");
  file_put_contents(DAU_AUTOSTART, "vpn\ntorrent 10\napp\n");
  $cfg = dau_defaults(); $cfg['appdata'] = ['roots' => [$root], 'safe_mode' => true];
  $cfg['backups']['appdata'] = array_replace($cfg['backups']['appdata'], ['dest' => '/mnt/dau-test/backups', 'schedule' => 'weekly', 'keep' => 2, 'stop_containers' => true, 'exclude' => ['big-copies']]);
  dau_save_config($cfg);
  rs_docker(['app' => ['running' => true, 'net' => 'mynet', 'image' => 'img/app'], 'torrent' => ['running' => true, 'net' => 'container:vpn', 'image' => 'img/torrent'], 'vpn' => ['running' => true, 'net' => 'bridge', 'image' => 'img/vpn']], ['mynet' => '172.30.0.0/16']);
  return dau_load_config();
}

t('restore: an appdata backup carries a restore kit (templates, containers, autostart, networks)', function () {
  $cfg = rs_env();
  $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']);
  $kit = dau_rs_kit_of($r['file']); ok(is_array($kit), 'sidecar next to the archive');
  eq(substr(sprintf('%o', fileperms(dau_rs_sidecar($r['file']))), -4), '0600', 'private: it names images and networks');
  eq($kit['roots'][0]['folders'], ['app', 'torrent', 'vpn'], 'what is in the archive; an excluded folder is not');
  eq(array_column($kit['containers'], 'name'), ['app', 'torrent', 'vpn']); eq($kit['networks'], [['name' => 'mynet', 'driver' => 'bridge', 'subnet' => '172.30.0.0/16', 'gateway' => '']]);
  eq($kit['autostart'][1], ['name' => 'torrent', 'wait' => 10]);
  $list = []; exec('tar -tzf ' . escapeshellarg($r['file']), $list);
  ok(in_array(DAU_RS_KIT_IN_TAR, $list, true), 'the kit is inside the archive too'); ok(in_array(DAU_RS_TPL_IN_TAR . '/my-app.xml', $list, true), 'templates are inside the archive');
  ok(!array_filter($list, function ($l) { return strpos($l, 'big-copies') !== false; }), 'the excluded folder is not');
  eq(array_column(dau_rs_order($kit), 'name'), ['vpn', 'torrent', 'app'], 'autostart order, and a container using another one\'s network after it');
});

t('restore: the space a restore needs counts only what is in the archive (not the quarantine or the exclude list)', function () {
  $cfg = rs_env(); $root = '/mnt/dau-test/appdata';
  @mkdir("$root/" . DAU_QDIR_NAME . '/old__x', 0755, true);
  $cfg['backups']['appdata']['exclude'] = ['big-copies', 'big-copies/old.zip', 'no-such-*']; dau_save_config($cfg); $cfg = dau_load_config();
  $GLOBALS['RS']['du'] = [$root => 10000, "$root/" . DAU_QDIR_NAME => 3000, "$root/big-copies" => 2000, "$root/big-copies/old.zip" => 2000];
  $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']);
  eq((float)dau_rs_kit_of($r['file'])['bytes'], 5000.0, 'the folder minus the quarantine and the excluded folder, each counted once');
  eq(dau_rs_plan($cfg, basename($r['file']))['need'], 5000.0);
  eq(dau_paths_outermost(['/a/b/c', '/a/b/', '/a/bc', '/a/b', '/x']), ['/x', '/a/b', '/a/bc']);
});

t('restore: after the cache drive died everything comes back (folders, templates, network, autostart, containers)', function () {
  $cfg = rs_env(); $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']); $name = basename($r['file']);
  /* the drive dies: appdata, docker.img (containers, networks, autostart) are gone; one template was lost too */
  dau_test_rm('/mnt/dau-test/appdata'); @unlink(DAU_AUTOSTART); @unlink(DAU_TEMPLATES . '/my-vpn.xml');
  rs_docker([], []);
  $p = dau_rs_plan($cfg, $name); ok($p['ok'], $p['error']);
  eq(array_column($p['containers'], 'action'), ['recreate', 'recreate', 'recreate']); eq($p['templates'], 1); eq($p['networks'], [['name' => 'mynet', 'exists' => false]]);
  ok(!array_filter(array_column($p['folders'], 'exists')), 'nothing to move aside');
  $res = dau_rs_run($cfg, $name); ok($res['ok'], $res['error']);
  eq(file_get_contents('/mnt/dau-test/appdata/app/settings.txt'), 'app v1'); ok(is_file(DAU_TEMPLATES . '/my-vpn.xml'), 'the lost template is back');
  eq($GLOBALS['RS']['log'], ['network mynet', 'rebuild vpn', 'rebuild torrent', 'rebuild app'], 'network first, then containers in start order');
  eq(file_get_contents(DAU_AUTOSTART), "vpn\ntorrent 10\napp\n"); eq($res['moved'], []);
});

t('restore: on a working server the current folders go to the quarantine and the containers are stopped and started', function () {
  $cfg = rs_env(); $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']); $name = basename($r['file']);
  file_put_contents('/mnt/dau-test/appdata/app/settings.txt', 'app v2'); file_put_contents(DAU_TEMPLATES . '/my-app.xml', '<Container><Name>app</Name><Edited/></Container>');
  $GLOBALS['RS']['log'] = [];
  $res = dau_rs_run($cfg, $name); ok($res['ok'], $res['error']);
  eq(file_get_contents('/mnt/dau-test/appdata/app/settings.txt'), 'app v1', 'the backup is back');
  has(file_get_contents(DAU_TEMPLATES . '/my-app.xml'), '<Edited/>', 'a template on the flash drive is newer: kept');
  eq($res['moved'], ['app', 'torrent', 'vpn']); ok(is_file('/mnt/dau-test/appdata/big-copies/old.zip'), 'a folder that is not in the backup is not touched');
  $q = dau_quarantine_load(); eq(count($q), 3); eq(file_get_contents($q[0]['path'] . '/settings.txt'), 'app v2', 'what was there is kept in the quarantine'); eq($q[0]['why'], 'restore');
  ok(in_array('stop app', $GLOBALS['RS']['log'], true) && in_array('start app', $GLOBALS['RS']['log'], true)); ok(!calls_matching('rebuild_container'), 'nothing recreated: they all exist');
  eq($res['started'], ['vpn', 'torrent', 'app']);
});

t('restore: refused for unknown or old backups, and through the API only with a confirmation', function () {
  $cfg = rs_env(); $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']); $name = basename($r['file']);
  has(dau_rs_plan($cfg, 'appdata-20200101-000000.tar.gz')['error'], 'not in the backup folder');
  @unlink(dau_rs_sidecar($r['file'])); has(dau_rs_plan($cfg, $name)['error'], 'before restore kits existed');
  eq(dau_rs_kit_of('/nonexistent.tar.gz'), null);
  $cfg = rs_env(); $r = dau_bk_run('appdata', $cfg); ok($r['ok'], $r['error']);
  eq(dau_rs_sidecar('/x/appdata-20261120-010203.tar.gz'), '/x/appdata-20261120-010203.json');
  foreach ([1, 2, 3] as $i) { $GLOBALS['DAU_NOW'] += 60; $cfg2 = dau_load_config(); dau_bk_run('appdata', $cfg2); }
  eq(count(glob('/mnt/dau-test/backups/Unraid-Swiss-Manager/appdata/*.json')), 2, 'an old archive takes its kit with it when it is pruned');
});
