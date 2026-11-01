<?php
/* Usage: php tests/run.php [test_file_substring]
   Later files use helpers defined by earlier ones, so a single file can only be run by name when it stands alone. */
require __DIR__ . '/bootstrap.php';
$only = $argv[1] ?? '';
foreach (glob(__DIR__ . '/test_*.php') as $f) {
  if ($only !== '' && strpos(basename($f), $only) === false) continue;
  $p = $GLOBALS['T']['pass']; $x = $GLOBALS['T']['fail'];
  require $f;
  echo '   ' . basename($f) . ': ' . ($GLOBALS['T']['pass'] - $p) . ' passed, ' . ($GLOBALS['T']['fail'] - $x) . " failed\n";      // per file, so an environment that skips a file shows up
}
exit(test_summary());
