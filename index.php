<?php
$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__ . '/class/woobanksync.class.php';

$langs->loadLangs(array('banks', 'woobanksync@woobanksync'));
if (!$user->hasRight('banque', 'lire')) accessforbidden();

$action = GETPOST('action', 'aZ09');

if ($action === 'syncnow') {
    if (!$user->hasRight('woobanksync', 'run') && !$user->admin) accessforbidden();
    @set_time_limit(120);
    try {
        $sync = new WooBankSync($db, $conf, $langs);
        $pages = max(1, min(20, (int) ($conf->global->WBS_MANUAL_SYNC_PAGES ?? 5)));
        $perPage = max(1, min(100, (int) ($conf->global->WBS_MANUAL_SYNC_PER_PAGE ?? 20)));
        $stats = $sync->sync($pages, $perPage);
        $messages = array('Imported: ' . (int) $stats['imported'], 'Skipped: ' . (int) $stats['skipped'], 'Errors: ' . (int) $stats['errors']);
        setEventMessages(implode(' / ', $messages), null, $stats['errors'] ? 'warnings' : 'mesgs');
        if (!empty($stats['messages'])) setEventMessages('', array_slice($stats['messages'], 0, 200), $stats['errors'] ? 'warnings' : 'mesgs');
    } catch (Throwable $e) {
        dol_syslog('WooBankSync fatal during manual sync: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
        setEventMessages('WooBankSync stopped with a PHP error: ' . $e->getMessage(), null, 'errors');
    }
}

if ($action === 'resyncdiff') {
    if (!$user->hasRight('woobanksync', 'run') && !$user->admin) accessforbidden();
    @set_time_limit(300);
    try {
        $sync = new WooBankSync($db, $conf, $langs);
        $stats = $sync->resyncDifferences();
        $summary = 'Checked: ' . (int) $stats['checked'] . ' / Updated: ' . (int) $stats['updated'] . ' / Unchanged: ' . (int) $stats['unchanged'] . ' / Errors: ' . (int) $stats['errors'];
        $type = $stats['errors'] ? 'warnings' : ($stats['updated'] ? 'mesgs' : 'mesgs');
        setEventMessages($summary, null, $type);
        if (!empty($stats['messages'])) setEventMessages('', array_slice($stats['messages'], 0, 200), $type);
    } catch (Throwable $e) {
        dol_syslog('WooBankSync fatal during resync: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
        setEventMessages('WooBankSync stopped with a PHP error: ' . $e->getMessage(), null, 'errors');
    }
}

llxHeader('', $langs->trans('WooBankSync'));
?>
<?php echo load_fiche_titre($langs->trans('WooBankSync'), '<a href="admin/setup.php">Setup</a>', 'bank'); ?>
<?php

?>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="syncnow">
<input class="button" type="submit" value="Sync now">
</form><br>
<?php

if (!empty($conf->global->WBS_LAST_SYNC)) {
?>
<div class="opacitymedium">Last sync: <?php echo dol_print_date((int) $conf->global->WBS_LAST_SYNC, 'dayhour'); ?></div><br>
<?php
}

$sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'woobanksync_log WHERE entity=' . (int) $conf->entity . ' ORDER BY rowid DESC LIMIT 100';
$resql = $db->query($sql);

?>
<div class="div-table-responsive-no-min">
<table class="liste centpercent">
<tr class="liste_titre"><th>Date sync</th><th>Woo order</th><th>Invoice</th><th>Gateway</th><th class="right">Gross</th><th class="right">Fee</th><th class="right">Payout</th><th>Status</th><th>Message</th></tr>
<?php
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
?>
<tr class="oddeven">
<td><?php echo dol_print_date($db->jdate($obj->date_sync), 'dayhour'); ?></td>
<td><?php echo dol_escape_htmltag($obj->woo_order_number); ?></td>
<td><?php echo dol_escape_htmltag($obj->woo_invoice_number ?? ''); ?></td>
<td><?php echo dol_escape_htmltag($obj->payment_method); ?></td>
<td class="right"><?php echo price($obj->gross_amount); ?> <?php echo dol_escape_htmltag($obj->currency); ?></td>
<td class="right"><?php echo price($obj->fee_amount); ?> <?php echo dol_escape_htmltag($obj->currency); ?></td>
<td class="right"><?php echo price($obj->payout_amount ?? 0); ?> <?php echo dol_escape_htmltag($obj->currency); ?></td>
<td><?php echo dol_escape_htmltag($obj->sync_status); ?></td>
<td><?php echo dol_escape_htmltag($obj->sync_message); ?></td>
</tr>
<?php
    }
} else {
?>
<tr><td colspan="9"><?php echo dol_escape_htmltag($db->lasterror()); ?></td></tr>
<?php
}
?>
</table></div>
<?php
llxFooter();
$db->close();
