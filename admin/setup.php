<?php
$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once __DIR__ . '/../class/woobanksync.class.php';

$langs->loadLangs(array('admin', 'banks', 'ecm', 'woobanksync@woobanksync'));
if (!$user->admin) accessforbidden();

if (!function_exists('wbs_set_const_safe')) {
    function wbs_set_const_safe($db, $name, $value, $type = 'chaine', $visible = 0, $note = '', $entity = 1)
    {
        global $conf;

        if (function_exists('dolibarr_set_const')) {
            $result = dolibarr_set_const($db, $name, $value, $type, $visible, $note, $entity);
        } else {
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "const WHERE name='" . $db->escape($name) . "' AND entity=" . ((int) $entity);
            $resql = $db->query($sql);
            if ($resql && ($obj = $db->fetch_object($resql))) {
                $sql = "UPDATE " . MAIN_DB_PREFIX . "const SET value='" . $db->escape((string) $value) . "', type='" . $db->escape($type) . "', visible=" . ((int) $visible) . ", note='" . $db->escape((string) $note) . "' WHERE rowid=" . ((int) $obj->rowid);
            } else {
                $sql = "INSERT INTO " . MAIN_DB_PREFIX . "const (name, entity, value, type, visible, note) VALUES ('" . $db->escape($name) . "', " . ((int) $entity) . ", '" . $db->escape((string) $value) . "', '" . $db->escape($type) . "', " . ((int) $visible) . ", '" . $db->escape((string) $note) . "')";
            }
            $result = $db->query($sql) ? 1 : -1;
        }

        if ($result > 0) {
            if (!isset($conf->global)) $conf->global = new stdClass();
            $conf->global->$name = $value;
        }
        return $result;
    }
}

$action = GETPOST('action', 'aZ09');
$sync = new WooBankSync($db, $conf, $langs);

function wbs_bank_select($name, $selected)
{
    global $db, $conf;
    $html = '<select class="flat minwidth300" name="' . dol_escape_htmltag($name) . '">';
    $html .= '<option value="">-- not mapped --</option>';
    $sql = 'SELECT rowid, ref, label FROM ' . MAIN_DB_PREFIX . 'bank_account WHERE entity IN (0,' . ((int) $conf->entity) . ') AND clos=0 ORDER BY label ASC';
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $value = (string) $obj->rowid;
            $label = trim($obj->ref . ' - ' . $obj->label);
            $html .= '<option value="' . dol_escape_htmltag($value) . '"' . ($value === (string) $selected ? ' selected' : '') . '>' . dol_escape_htmltag($label) . '</option>';
        }
    }
    $html .= '</select>';
    return $html;
}

function wbs_meta_select($name, $keys, $selected, $placeholder)
{
    $html = '<select class="flat minwidth300" name="' . dol_escape_htmltag($name) . '">';
    $html .= '<option value="">' . dol_escape_htmltag($placeholder) . '</option>';
    foreach ((array) $keys as $key) {
        $html .= '<option value="' . dol_escape_htmltag($key) . '"' . ((string) $key === (string) $selected ? ' selected' : '') . '>' . dol_escape_htmltag($key) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function wbs_ecm_folder_select($name, $selected)
{
    global $db, $conf;
    $html = '<select class="flat minwidth300" name="' . dol_escape_htmltag($name) . '">';
    $html .= '<option value="">-- no folder --</option>';
    $sql = 'SELECT rowid, label FROM ' . MAIN_DB_PREFIX . 'ecm_directories WHERE entity IN (0,' . ((int) $conf->entity) . ') ORDER BY label ASC';
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $value = (string) $obj->rowid;
            $html .= '<option value="' . dol_escape_htmltag($value) . '"' . ($value === (string) $selected ? ' selected' : '') . '>' . dol_escape_htmltag($obj->label) . '</option>';
        }
    }
    $html .= '</select>';
    return $html;
}

if ($action === 'save_api') {
    $keys = array('WBS_WOO_URL', 'WBS_WOO_CONSUMER_KEY', 'WBS_WOO_CONSUMER_SECRET', 'WBS_SYNC_FROM_DATE', 'WBS_ORDER_STATUSES', 'WBS_MANUAL_SYNC_PAGES', 'WBS_MANUAL_SYNC_PER_PAGE');
    foreach ($keys as $key) wbs_set_const_safe($db, $key, GETPOST($key, 'restricthtml'), 'chaine', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_DRY_RUN', GETPOST('WBS_DRY_RUN', 'int') ? '1' : '0', 'yesno', 0, '', $conf->entity);
    setEventMessages('API settings saved.', null, 'mesgs');
}

if ($action === 'refresh') {
    list($ok, $msg) = $sync->refreshWooDiscovery();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'autosetup') {
    list($ok, $msg) = $sync->autoCreateAndMapAccounts();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'save_map') {
    $sync->saveGatewayMapFromPost();
    setEventMessages('Payment method mapping saved.', null, 'mesgs');
}

if ($action === 'save_docs') {
    wbs_set_const_safe($db, 'WBS_DOCUMENT_SYNC_ENABLED', GETPOST('WBS_DOCUMENT_SYNC_ENABLED', 'int') ? '1' : '0', 'yesno', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_DOCUMENT_FOLDER_ID', GETPOST('WBS_DOCUMENT_FOLDER_ID', 'int'), 'chaine', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_BANK_EXTRAFIELD_ENABLED', GETPOST('WBS_BANK_EXTRAFIELD_ENABLED', 'int') ? '1' : '0', 'yesno', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_BANK_EXTRAFIELD_CODE', GETPOST('WBS_BANK_EXTRAFIELD_CODE', 'aZ09'), 'chaine', 0, '', $conf->entity);
    setEventMessages('Document settings saved.', null, 'mesgs');
}

if ($action === 'create_invoice_extrafield') {
    list($ok, $msg) = $sync->createAndMapInvoiceBankExtraField();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'createdocs') {
    list($ok, $msg) = $sync->createDocumentFolder();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'dbcheck') {
    list($ok, $msg) = $sync->runDatabaseChecks();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'diagnose_meta') {
    $client = $sync->client();
    $orders = $client->getRecentOrders(10);
    if ($orders === false) {
        wbs_set_const_safe($db, 'WBS_META_DIAG_JSON', json_encode(array('error' => $client->error)), 'chaine', 0, '', $conf->entity);
        setEventMessages('API request failed: ' . $client->error, null, 'errors');
    } else {
        $diagResult = array();
        foreach ($orders as $order) {
            $orderNum = '#' . (string) ($order['number'] ?? $order['id']);
            $keys = array();
            foreach (($order['meta_data'] ?? array()) as $meta) {
                $key = (string) ($meta['key'] ?? '');
                if ($key !== '') $keys[$key] = substr(print_r($meta['value'], true), 0, 120);
            }
            ksort($keys);
            $diagResult[$orderNum] = $keys;
        }
        wbs_set_const_safe($db, 'WBS_META_DIAG_JSON', json_encode($diagResult), 'chaine', 0, '', $conf->entity);
        setEventMessages('Diagnostic complete for ' . count($orders) . ' recent orders. Results shown below.', null, 'mesgs');
    }
}

if ($action === 'desync') {
    if (GETPOST('confirm_desync', 'alpha') !== 'yes') {
        setEventMessages('Desync was not confirmed.', null, 'errors');
    } else {
        list($ok, $msg) = $sync->desyncAllSyncedEntries();
        setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
    }
}

llxHeader('', $langs->trans('WooBankSyncSetup'));
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>';
?>
<?php echo load_fiche_titre($langs->trans('WooBankSyncSetup'), $linkback, 'title_setup'); ?>
<?php

?>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="center" style="margin-bottom:12px;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="dbcheck">
<input class="button" type="submit" value="Run/update database checks without disabling module">
</form>
<?php

?>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="save_api">
<table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">WooCommerce API</td></tr>
<?php
$fields = array(
    'WBS_WOO_URL' => 'WooCommerce URL',
    'WBS_WOO_CONSUMER_KEY' => 'Consumer key',
    'WBS_WOO_CONSUMER_SECRET' => 'Consumer secret',
    'WBS_SYNC_FROM_DATE' => 'Sync from date (YYYY-MM-DD)',
    'WBS_ORDER_STATUSES' => 'Order statuses',
    'WBS_MANUAL_SYNC_PAGES' => 'Manual sync pages per click',
    'WBS_MANUAL_SYNC_PER_PAGE' => 'Orders per page',
);
foreach ($fields as $key => $label) {
    $type = ($key === 'WBS_WOO_CONSUMER_SECRET') ? 'password' : 'text';
    $defaultValue = '';
    if ($key === 'WBS_ORDER_STATUSES') $defaultValue = 'processing,completed';
    if ($key === 'WBS_MANUAL_SYNC_PAGES') $defaultValue = '5';
    if ($key === 'WBS_MANUAL_SYNC_PER_PAGE') $defaultValue = '20';
    $value = isset($conf->global->$key) ? $conf->global->$key : $defaultValue;
?>
<tr><td class="titlefield"><?php echo dol_escape_htmltag($label); ?></td><td><input class="flat minwidth500" type="<?php echo $type; ?>" name="<?php echo $key; ?>" value="<?php echo dol_escape_htmltag($value); ?>"></td></tr>
<?php
}
?>
<tr><td>Dry run</td><td><input type="checkbox" name="WBS_DRY_RUN" value="1"<?php echo !empty($conf->global->WBS_DRY_RUN) ? ' checked' : ''; ?>> Do not write bank lines</td></tr>
</table>
<div class="center"><input class="button button-save" type="submit" value="Save API settings"></div>
</form><br>
<?php

?>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="display:inline-block;margin-right:8px;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="refresh">
<input class="button" type="submit" value="Refresh active and used payment methods from WooCommerce">
</form>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="display:inline-block;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="autosetup">
<input class="button" type="submit" value="Yes, create virtual bank accounts and map listed gateways">
</form><br><br>
<?php

$gateways = $sync->getJsonConst('WBS_GATEWAYS_JSON', array());
$metaByGateway = $sync->getJsonConst('WBS_META_KEYS_JSON', array());
$map = $sync->gatewayMap();

?>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="save_map">
<table class="noborder centpercent">
<tr class="liste_titre"><td colspan="7">WooCommerce payment methods mapping (active or used)</td></tr>
<tr class="liste_titre"><th>ID</th><th>Title</th><th>Source</th><th>Orders found</th><th>Dolibarr bank account</th><th>Payout meta key</th><th>Fee meta key</th></tr>
<?php
if (empty($gateways)) {
?>
<tr><td colspan="7" class="opacitymedium">No payment methods detected yet. Save API settings, then click Refresh.</td></tr>
<?php
} else {
    foreach ($gateways as $gateway) {
        $gid = (string) $gateway['id'];
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $gid);
        $keys = isset($metaByGateway[$gid]) ? $metaByGateway[$gid] : array();
        $selected = isset($map[$gid]) ? $map[$gid] : array();
?>
<tr class="oddeven">
<td><strong><?php echo dol_escape_htmltag($gid); ?></strong></td>
<td><?php echo dol_escape_htmltag($gateway['title'] ?? $gid); ?></td>
<td><?php echo dol_escape_htmltag($gateway['source'] ?? (!empty($gateway['enabled']) ? 'active' : 'historical')); ?></td>
<td><?php echo (int) ($gateway['orders_count'] ?? 0); ?></td>
<td><?php echo wbs_bank_select('WBS_MAP_BANK_' . $safe, $selected['bank_id'] ?? ''); ?></td>
<td><?php echo wbs_meta_select('WBS_MAP_PAYOUT_' . $safe, $keys, $selected['payout_key'] ?? '', '-- auto / not used --'); ?></td>
<td><?php echo wbs_meta_select('WBS_MAP_FEE_' . $safe, $keys, $selected['fee_key'] ?? '', '-- auto detect --'); ?></td>
</tr>
<?php
    }
}
?>
</table>
<div class="center"><input class="button button-save" type="submit" value="Save mapping"></div>
</form><br>
<?php

$invoiceAvailable = !empty($conf->global->WBS_WOO_INVOICE_AVAILABLE);
$keys = $sync->getJsonConst('WBS_WOO_INVOICE_KEYS_JSON', array());
$bankExtraFields = $sync->getBankExtraFields();
$mappedBankExtraField = (string) ($conf->global->WBS_BANK_EXTRAFIELD_CODE ?? '');
?>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="save_docs">
<table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">WooCommerce invoice reference</td></tr>
<?php
if (empty($keys)) {
?>
<tr><td>Detected invoice meta keys</td><td class="opacitymedium">No invoice meta keys detected in scanned orders yet. Click <strong>Refresh</strong> to scan. The sync will always try these known keys automatically: <code>_wc_gzd_invoice_number, _wc_gzd_invoices, _wc_gzd_document_data, _wc_gzdp_invoice_number, _wcpdf_invoice_number, _wpo_wcpdf_invoice_number</code></td></tr>
<?php
} else {
?>
<tr><td>Detected invoice meta keys</td><td><?php echo dol_escape_htmltag(implode(', ', $keys)); ?></td></tr>
<?php
}
?>
<tr><td>Enable invoice reference on bank entries</td><td><input type="checkbox" name="WBS_DOCUMENT_SYNC_ENABLED" value="1"<?php echo !empty($conf->global->WBS_DOCUMENT_SYNC_ENABLED) ? ' checked' : ''; ?>> Store invoice reference in the native bank entry Number field and label</td></tr>
<tr><td>Store invoice reference in a custom field</td><td><input type="checkbox" name="WBS_BANK_EXTRAFIELD_ENABLED" value="1"<?php echo !empty($conf->global->WBS_BANK_EXTRAFIELD_ENABLED) ? ' checked' : ''; ?>> Also write the invoice number into the mapped bank-entry custom field</td></tr>
<tr><td>Bank-entry custom field</td><td><select class="flat minwidth300" name="WBS_BANK_EXTRAFIELD_CODE">
<option value="">-- not mapped --</option>
<?php
foreach ($bankExtraFields as $code => $label) {
?>
<option value="<?php echo dol_escape_htmltag($code); ?>"<?php echo $code === $mappedBankExtraField ? ' selected' : ''; ?>><?php echo dol_escape_htmltag($label . ' (' . $code . ')'); ?></option>
<?php
}
?>
</select><br><span class="opacitymedium">You can create this field manually in Dolibarr bank-entry custom fields, then select it here.</span></td></tr>
<tr><td>Document folder</td><td><?php echo wbs_ecm_folder_select('WBS_DOCUMENT_FOLDER_ID', $conf->global->WBS_DOCUMENT_FOLDER_ID ?? ''); ?><br><span class="opacitymedium">Optional ECM folder prepared for future/imported WooCommerce PDF invoices. This module currently stores the invoice reference on the bank entry; PDF upload/import needs a WordPress-side download helper.</span></td></tr>
</table>
<div class="center"><input class="button button-save" type="submit" value="Save document settings"></div>
</form>
<?php
$diagData = $sync->getJsonConst('WBS_META_DIAG_JSON', array());
if (!empty($diagData)) {
?>
<br><table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">Raw meta_data diagnostic — last 10 recent orders</td></tr>
<?php
    if (!empty($diagData['error'])) {
?>
<tr><td colspan="2" class="error"><?php echo dol_escape_htmltag($diagData['error']); ?></td></tr>
<?php
    } else {
        foreach ($diagData as $orderNum => $keys) {
?>
<tr class="oddeven"><td style="vertical-align:top;font-weight:bold;"><?php echo dol_escape_htmltag($orderNum); ?></td><td>
<?php
            if (empty($keys)) {
?>
<span class="opacitymedium">No meta_data returned by API for this order.</span>
<?php
            } else {
?>
<table style="width:100%;font-size:0.9em;">
<?php
                foreach ($keys as $key => $preview) {
?>
<tr><td style="width:40%;font-family:monospace;"><?php echo dol_escape_htmltag($key); ?></td><td class="opacitymedium"><?php echo dol_escape_htmltag($preview); ?></td></tr>
<?php
                }
?>
</table>
<?php
            }
?>
</td></tr>
<?php
        }
    }
?>
</table>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="center">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="diagnose_meta">
<input class="button" type="submit" value="Re-run meta diagnostic">
</form><br>
<?php
} else {
?>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="center">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="diagnose_meta">
<input class="button" type="submit" value="Diagnose: show all meta keys from 10 recent orders">
</form><br>
<?php
}

?>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="center">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="create_invoice_extrafield">
<input class="button" type="submit" value="Create and map invoice-number custom field"<?php echo $mappedBankExtraField !== '' ? ' disabled' : ''; ?>>
<?php
if ($mappedBankExtraField !== '') {
?>
<br><span class="opacitymedium">A custom field is already mapped. Clear the mapping and save if you need to create a different field.</span>
<?php
}
?>
</form>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="center">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="createdocs">
<input class="button" type="submit" value="Create Woo Invoices document folder">
</form>
<?php


?>
<br><table class="noborder centpercent">
<tr class="liste_titre"><td>Danger zone</td></tr>
<tr><td>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" onsubmit="return confirm(&quot;⚠️ Are you sure? This will delete all Dolibarr bank lines created by WooBankSync and clear the sync log so orders can be synced again.&quot;);">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="desync">
<input type="hidden" name="confirm_desync" value="yes">
<input class="button button-delete" style="background:#b00020;color:#fff;border-color:#b00020;" type="submit" value="⚠️ Desync: delete synced bank entries and reset log">
</form>
<span class="opacitymedium">Deletes only bank lines stored in the WooBankSync log. It does not delete WooCommerce orders and does not touch manually-created bank entries.</span>
</td></tr></table>
<?php

llxFooter();
$db->close();
