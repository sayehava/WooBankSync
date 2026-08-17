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

$sync = new DolliCommerceHub($db, $conf, $langs);
$totalErrors = 0;
list($dbOk, $dbMessage) = $sync->runDatabaseChecks();
if (!$dbOk) {
    fwrite(STDERR, 'Dolli Commerce Hub database check failed: ' . $dbMessage . PHP_EOL);
    exit(1);
}

if (!isset($conf->global->DCH_WOOCOMMERCE_ENABLED) || !empty($conf->global->DCH_WOOCOMMERCE_ENABLED)) {
    $stats = $sync->sync(100, 100);
    echo 'Dolli Commerce Hub (WooCommerce) imported=' . $stats['imported'] . ' skipped=' . $stats['skipped'] . ' errors=' . $stats['errors'] . PHP_EOL;
    foreach (array_slice($stats['messages'], 0, 20) as $message) echo '- ' . $message . PHP_EOL;
    $totalErrors += (int) $stats['errors'];
}

foreach (array('amazon', 'sumup') as $connector) {
    if (empty($conf->global->{'DCH_' . strtoupper($connector) . '_ENABLED'})) continue;
    $cursor = '';
    $aggregate = array('imported' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => array());
    for ($batch = 0; $batch < 500; $batch++) {
        $stats = $sync->syncConnectorBatch($connector, $cursor, 100);
        foreach (array('imported', 'skipped', 'errors') as $key) $aggregate[$key] += (int) ($stats[$key] ?? 0);
        $aggregate['messages'] = array_merge($aggregate['messages'], (array) ($stats['messages'] ?? array()));
        if (empty($stats['has_more']) || empty($stats['next_cursor'])) break;
        $cursor = (string) $stats['next_cursor'];
    }
    echo 'Dolli Commerce Hub (' . ucfirst($connector) . ') imported=' . $aggregate['imported'] . ' skipped=' . $aggregate['skipped'] . ' errors=' . $aggregate['errors'] . PHP_EOL;
    foreach (array_slice($aggregate['messages'], 0, 20) as $message) echo '- ' . $message . PHP_EOL;
    $totalErrors += $aggregate['errors'];
}

exit($totalErrors ? 1 : 0);
