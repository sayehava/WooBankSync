#!/usr/bin/env php
<?php
$sapi = php_sapi_name();
if ($sapi !== 'cli') {
    die('CLI only');
}

$res = 0;
$paths = array(__DIR__ . '/../../../main.inc.php', __DIR__ . '/../../main.inc.php');
foreach ($paths as $path) {
    if (file_exists($path)) {
        $res = include $path;
        break;
    }
}
if (!$res) {
    fwrite(STDERR, "Include of main.inc.php failed\n");
    exit(1);
}

require_once __DIR__ . '/../class/woobanksync.class.php';
$langs->loadLangs(array('woobanksync@woobanksync'));

$sync = new WooBankSync($db, $conf, $langs);
$stats = $sync->sync(20);

echo 'WooBankSync imported=' . $stats['imported'] . ' skipped=' . $stats['skipped'] . ' errors=' . $stats['errors'] . PHP_EOL;
foreach (array_slice($stats['messages'], 0, 20) as $message) {
    echo '- ' . $message . PHP_EOL;
}
exit($stats['errors'] ? 1 : 0);
