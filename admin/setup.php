<?php
$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once __DIR__ . '/../class/financeautomationhub.class.php';
require_once __DIR__ . '/../helpers/FahIntegrationManager.php';

$langs->loadLangs(array('admin', 'banks', 'ecm', 'financeautomationhub@financeautomationhub'));
if (!$user->admin) accessforbidden();

if (!function_exists('fah_set_const_safe')) {
    function fah_set_const_safe($db, $name, $value, $type = 'chaine', $visible = 0, $note = '', $entity = 1)
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
$fahRequestedConnector = strtolower((string) GETPOST('connector_view', 'alpha'));
if (!in_array($fahRequestedConnector, array('woocommerce', 'amazon', 'sumup'), true)) $fahRequestedConnector = '';
$fahSetupAction = $_SERVER['PHP_SELF'] . ($fahRequestedConnector !== '' ? '?connector_view=' . $fahRequestedConnector : '');
$sync = new FinanceAutomationHub($db, $conf, $langs);
list($autoSchemaOk, $autoSchemaMessage) = $sync->runDatabaseChecks(); // Safe, idempotent lazy migration for uploaded upgrades.
if (!$autoSchemaOk) setEventMessages($autoSchemaMessage, null, 'errors');
$manager = new FahIntegrationManager($db, $conf);
$detectedIntegrations = $manager->getDetected();

// Dispatch AJAX actions to integrations (they echo JSON and exit).
foreach ($detectedIntegrations as $_integration) {
    $_integration->handleAjaxAction($action, $conf, $db, $langs, $sync);
}

// Dispatch POST actions to integrations.
$_integrationHandled = false;
foreach ($detectedIntegrations as $_integration) {
    if ($_integration->handleAction($action, $conf, $db, $langs, $sync)) {
        $_integrationHandled = true;
        break;
    }
}

function fah_bank_select($name, $selected)
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

function fah_meta_select($name, $keys, $selected, $placeholder, $style = 'min-width:180px;')
{
    $html = '<select class="flat" style="' . dol_escape_htmltag($style) . '" name="' . dol_escape_htmltag($name) . '">';
    $html .= '<option value="">' . dol_escape_htmltag($placeholder) . '</option>';
    foreach ((array) $keys as $key) {
        $html .= '<option value="' . dol_escape_htmltag($key) . '"' . ((string) $key === (string) $selected ? ' selected' : '') . '>' . dol_escape_htmltag($key) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function fah_ecm_folder_select($name, $selected)
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

function fah_warehouse_select($name, $selected, $warehouses, $formId = '')
{
    $html = '<select class="flat" style="max-width:340px;width:100%;" name="' . dol_escape_htmltag($name) . '"' . ($formId !== '' ? ' form="' . dol_escape_htmltag($formId) . '"' : '') . '>';
    $html .= '<option value="">-- connector default warehouse --</option>';
    foreach ((array) $warehouses as $warehouse) {
        $value = (string) $warehouse['id'];
        $label = trim((string) $warehouse['ref'] . (!empty($warehouse['label']) ? ' - ' . $warehouse['label'] : ''));
        $html .= '<option value="' . dol_escape_htmltag($value) . '"' . ($value === (string) $selected ? ' selected' : '') . '>' . dol_escape_htmltag($label) . '</option>';
    }
    return $html . '</select>';
}

function fah_product_select($name, $selected, $products, $formId = '')
{
    $html = '<select class="flat" style="max-width:360px;width:100%;" name="' . dol_escape_htmltag($name) . '"' . ($formId !== '' ? ' form="' . dol_escape_htmltag($formId) . '"' : '') . '>';
    $html .= '<option value="">-- choose Dolibarr product --</option>';
    foreach ((array) $products as $product) {
        $value = (string) $product['id'];
        $label = trim((string) $product['ref'] . ' - ' . (string) $product['label']);
        $html .= '<option value="' . dol_escape_htmltag($value) . '"' . ($value === (string) $selected ? ' selected' : '') . '>' . dol_escape_htmltag($label) . '</option>';
    }
    return $html . '</select>';
}

if ($action === 'save_connector') {
    $connector = strtolower((string) GETPOST('connector', 'alpha'));
    $allowed = array('woocommerce', 'amazon', 'sumup');
    if (!in_array($connector, $allowed, true)) {
        setEventMessages('Unknown connector.', null, 'errors');
    } else {
        $prefix = 'FAH_' . strtoupper($connector) . '_';
        fah_set_const_safe($db, $prefix . 'ENABLED', GETPOST('enabled', 'int') ? '1' : '0', 'yesno', 0, '', $conf->entity);
        fah_set_const_safe($db, $prefix . 'STOCK_ENABLED', GETPOST('stock_enabled', 'int') ? '1' : '0', 'yesno', 0, '', $conf->entity);
        fah_set_const_safe($db, $prefix . 'WAREHOUSE_ID', (string) max(0, (int) GETPOST('warehouse_id', 'int')), 'chaine', 0, '', $conf->entity);

        if ($connector === 'amazon') {
            foreach (array('LWA_CLIENT_ID', 'SELLER_ID', 'MARKETPLACE_IDS', 'SYNC_FROM_DATE') as $suffix) {
                fah_set_const_safe($db, $prefix . $suffix, GETPOST(strtolower($suffix), 'restricthtml'), 'chaine', 0, '', $conf->entity);
            }
            foreach (array('LWA_CLIENT_SECRET', 'REFRESH_TOKEN') as $suffix) {
                $value = GETPOST(strtolower($suffix), 'restricthtml');
                if ($value !== '') fah_set_const_safe($db, $prefix . $suffix, $value, 'password', 0, '', $conf->entity);
            }
            $region = strtolower((string) GETPOST('region', 'alpha'));
            if (!in_array($region, array('eu', 'na', 'fe'), true)) $region = 'eu';
            fah_set_const_safe($db, $prefix . 'REGION', $region, 'chaine', 0, '', $conf->entity);
            fah_set_const_safe($db, $prefix . 'FINANCE_ENABLED', GETPOST('finance_enabled', 'int') ? '1' : '0', 'yesno', 0, '', $conf->entity);
        } elseif ($connector === 'sumup') {
            $token = GETPOST('access_token', 'restricthtml');
            if ($token !== '') fah_set_const_safe($db, $prefix . 'ACCESS_TOKEN', $token, 'password', 0, '', $conf->entity);
            foreach (array('MERCHANT_CODE', 'SYNC_FROM_DATE') as $suffix) {
                fah_set_const_safe($db, $prefix . $suffix, GETPOST(strtolower($suffix), 'restricthtml'), 'chaine', 0, '', $conf->entity);
            }
            $posMode = strtolower((string) GETPOST('pos_duplicate_mode', 'alpha'));
            if (!in_array($posMode, array('off', 'all', 'reference'), true)) $posMode = 'off';
            fah_set_const_safe($db, $prefix . 'POS_DUPLICATE_MODE', $posMode, 'chaine', 0, '', $conf->entity);
            fah_set_const_safe($db, $prefix . 'POS_REFERENCE_PREFIXES', GETPOST('pos_reference_prefixes', 'restricthtml'), 'chaine', 0, '', $conf->entity);
        }
        list($schemaOk, $schemaMessage) = $sync->runDatabaseChecks();
        setEventMessages(ucfirst($connector) . ' connector settings saved.' . ($schemaOk ? '' : ' ' . $schemaMessage), null, $schemaOk ? 'mesgs' : 'errors');
    }
}

if ($action === 'save_channel_finance') {
    list($ok, $msg) = $sync->saveChannelFinanceMapFromPost(GETPOST('connector', 'alpha'));
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'auto_channel_account') {
    list($ok, $msg) = $sync->autoCreateChannelAccount(GETPOST('connector', 'alpha'));
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'refresh_woo_catalog') {
    list($ok, $msg) = $sync->refreshWooCatalog();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'backfill_woo_stock') {
    @set_time_limit(600);
    list($ok, $msg) = $sync->backfillWooStock();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'refresh_amazon_catalog') {
    @set_time_limit(600);
    list($ok, $msg) = $sync->refreshAmazonCatalog();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'refresh_sumup_catalog') {
    @set_time_limit(600);
    list($ok, $msg) = $sync->refreshSumUpCatalog();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'save_stock_recipe') {
    $catalogId = max(0, (int) GETPOST('catalog_id', 'int'));
    $mode = (string) GETPOST('stock_mode', 'alpha');
    $components = array();
    $componentProducts = isset($_POST['component_product']) && is_array($_POST['component_product']) ? $_POST['component_product'] : array();
    $componentWarehouses = isset($_POST['component_warehouse']) && is_array($_POST['component_warehouse']) ? $_POST['component_warehouse'] : array();
    $componentQuantities = isset($_POST['component_quantity']) && is_array($_POST['component_quantity']) ? $_POST['component_quantity'] : array();
    $componentCount = min(100, max(count($componentProducts), count($componentWarehouses), count($componentQuantities)));
    for ($i = 0; $i < $componentCount; $i++) {
        $components[] = array(
            'product_id' => isset($componentProducts[$i]) ? (int) $componentProducts[$i] : 0,
            'warehouse_id' => isset($componentWarehouses[$i]) ? (int) $componentWarehouses[$i] : 0,
            'quantity' => isset($componentQuantities[$i]) ? preg_replace('/[^0-9.,-]/', '', (string) $componentQuantities[$i]) : '',
        );
    }
    list($ok, $msg) = $sync->inventory()->saveRecipe($catalogId, $mode, $components, GETPOST('is_bundle', 'int') ? true : false);
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'save_api') {
    $keys = array('FAH_WOO_URL', 'FAH_WOO_CONSUMER_KEY', 'FAH_WOO_CONSUMER_SECRET', 'FAH_SYNC_FROM_DATE', 'FAH_ORDER_STATUSES');
    foreach ($keys as $key) fah_set_const_safe($db, $key, GETPOST($key, 'restricthtml'), 'chaine', 0, '', $conf->entity);
    $stripeSecret = GETPOST('FAH_STRIPE_SECRET_KEY', 'restricthtml');
    if ($stripeSecret !== '') fah_set_const_safe($db, 'FAH_STRIPE_SECRET_KEY', $stripeSecret, 'password', 0, '', $conf->entity);
    fah_set_const_safe($db, 'FAH_STRIPE_ACCOUNT_ID', GETPOST('FAH_STRIPE_ACCOUNT_ID', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
    fah_set_const_safe($db, 'FAH_DRY_RUN', GETPOST('FAH_DRY_RUN', 'int') ? '1' : '0', 'yesno', 0, '', $conf->entity);
    setEventMessages('API settings saved.', null, 'mesgs');
}

if ($action === 'save_batch_sizes') {
    $defaults = array(
        'FAH_CACHE_BATCH_SIZE' => 1,
        'FAH_SYNC_BATCH_SIZE' => 10,
        'FAH_DIFF_BATCH_SIZE' => 10,
    );
    foreach ($defaults as $key => $default) {
        $value = max(1, min(100, (int) GETPOST($key, 'int')));
        if ($value <= 0) $value = $default;
        fah_set_const_safe($db, $key, (string) $value, 'chaine', 0, '', $conf->entity);
    }
    setEventMessages('Batch size settings saved.', null, 'mesgs');
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
    fah_set_const_safe($db, 'FAH_DOCUMENT_FOLDER_ID', GETPOST('FAH_DOCUMENT_FOLDER_ID', 'int'), 'chaine', 0, '', $conf->entity);
    setEventMessages('Document settings saved.', null, 'mesgs');
}

if ($action === 'save_amount_fields') {
    list($ok, $msg) = $sync->saveAmountExtraFieldMapping(
        GETPOST('FAH_EXTRAFIELD_GROSS_CODE', 'aZ09'), GETPOST('FAH_EXTRAFIELD_FEE_CODE', 'aZ09'),
        GETPOST('FAH_EXTRAFIELD_GROSS_LABEL', 'restricthtml'), GETPOST('FAH_EXTRAFIELD_FEE_LABEL', 'restricthtml')
    );
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'create_amount_extrafields') {
    list($ok, $msg) = $sync->createAndMapAmountExtraFields(
        GETPOST('FAH_EXTRAFIELD_GROSS_LABEL', 'restricthtml'), GETPOST('FAH_EXTRAFIELD_FEE_LABEL', 'restricthtml')
    );
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'repair_amount_extrafields') {
    list($ok, $msg) = $sync->saveAmountExtraFieldMapping(
        GETPOST('FAH_EXTRAFIELD_GROSS_CODE', 'aZ09'), GETPOST('FAH_EXTRAFIELD_FEE_CODE', 'aZ09'),
        GETPOST('FAH_EXTRAFIELD_GROSS_LABEL', 'restricthtml'), GETPOST('FAH_EXTRAFIELD_FEE_LABEL', 'restricthtml')
    );
    if ($ok) list($ok, $msg) = $sync->repairExistingBankExtraFields();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'dbcheck') {
    list($ok, $msg) = $sync->runDatabaseChecks();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'cleanup_legacy_menus') {
    list($ok, $msg) = $sync->cleanupLegacyMenus();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'set_bank_sequence') {
    list($ok, $msg) = $sync->setBankEntrySequence(GETPOST('next_bank_reference', 'int'));
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'diagnose_meta') {
    $wooUrl = isset($conf->global->FAH_WOO_URL) ? $conf->global->FAH_WOO_URL : '';
    $wooKey = isset($conf->global->FAH_WOO_CONSUMER_KEY) ? $conf->global->FAH_WOO_CONSUMER_KEY : '';
    $wooSecret = isset($conf->global->FAH_WOO_CONSUMER_SECRET) ? $conf->global->FAH_WOO_CONSUMER_SECRET : '';

    $client = $sync->client();
    $orders = $client->getRecentOrders(10);

    if ($orders === false) {
        fah_set_const_safe($db, 'FAH_META_DIAG_JSON', json_encode(array('error' => $client->error)), 'chaine', 0, '', $conf->entity);
        setEventMessages('API request failed: ' . $client->error, null, 'errors');
    } else {
        $diagResult = array('meta_data_by_order' => array(), 'germanized_probe' => null);

        // Section 1: meta_data keys from list endpoint for all 10 orders
        foreach ($orders as $order) {
            $orderNum = '#' . (string) ($order['number'] ?? $order['id']);
            $keys = array();
            foreach (($order['meta_data'] ?? array()) as $meta) {
                $key = (string) ($meta['key'] ?? '');
                if ($key !== '') $keys[$key] = substr(print_r($meta['value'], true), 0, 120);
            }
            ksort($keys);
            $diagResult['meta_data_by_order'][$orderNum] = $keys;
        }

        // Section 2: Germanized Pro probe on most recent order (full single-order
        // response + all known document endpoints)
        $firstOrderId = !empty($orders[0]['id']) ? (int) $orders[0]['id'] : 0;
        if ($firstOrderId > 0) {
            $gzdIntegration = $manager->get('germanized');
            if ($gzdIntegration) $diagResult['germanized_probe'] = $gzdIntegration->probeGzd($firstOrderId);
        }

        fah_set_const_safe($db, 'FAH_META_DIAG_JSON', json_encode($diagResult), 'chaine', 0, '', $conf->entity);
        setEventMessages('Diagnostic complete for ' . count($orders) . ' orders. Germanized probe on order #' . $firstOrderId . '. Results shown below.', null, 'mesgs');
    }
}

// AJAX: return sync log rows for the log viewer modal
if ($action === 'setup_log_list') {
    header('Content-Type: application/json');
    if (!$user->admin) { echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit; }
    $sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'fah_sync_log WHERE entity=' . (int) $conf->entity . ' ORDER BY rowid DESC LIMIT 1000';
    $resql = $db->query($sql);
    $rows = array();
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $rows[] = array(
                'id'           => (string) ($obj->woo_order_id ?? ''),
                'connector'    => (string) ($obj->connector ?? 'woocommerce'),
                'number'       => (string) ($obj->woo_order_number ?? ''),
                'invoice'      => (string) ($obj->woo_invoice_number ?? ''),
                'payment'      => (string) ($obj->payment_method ?? ''),
                'gross'        => (string) ($obj->gross_amount ?? ''),
                'fee'          => (string) ($obj->fee_amount ?? ''),
                'fee_source'   => (string) ($obj->fee_source ?? ''),
                'net'          => (string) ($obj->payout_amount ?? ''),
                'woo_payout'   => (string) ($obj->woo_payout_raw ?? ''),
                'currency'     => (string) ($obj->currency ?? ''),
                'status'       => (string) ($obj->sync_status ?? ''),
                'message'      => (string) ($obj->sync_message ?? ''),
                'date'         => (string) ($obj->date_sync ?? ''),
                'pdf_url'      => (string) ($obj->woo_invoice_pdf_url ?? ''),
                'pdf_ecm'      => (string) ($obj->pdf_ecm_filepath ?? ''),
            );
        }
    }
    echo json_encode(array('ok' => true, 'rows' => $rows));
    exit;
}

// AJAX: list synced orders for cache refresh (called from setup page JS)
if ($action === 'setup_cache_refresh_list') {
    header('Content-Type: application/json');
    if (!$user->admin) { echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit; }
    $limit = max(0, (int) GETPOST('limit', 'int'));
    echo json_encode(array(
        'ok' => true,
        'orders' => $sync->getFullCacheRefreshOrders($limit),
        'integrations_detected' => !empty($detectedIntegrations),
        'batch_size' => max(1, min(100, (int) ($conf->global->FAH_CACHE_BATCH_SIZE ?? 10))),
    ));
    exit;
}

// AJAX: refresh one batch of cache entries (stores full JSON for the viewer)
if ($action === 'setup_cache_refresh_batch') {
    header('Content-Type: application/json');
    if (!$user->admin) { echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit; }
    $rawIds = GETPOST('order_ids', 'restricthtml');
    $orderIds = preg_split('/[^0-9]+/', (string) $rawIds, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($orderIds)) { echo json_encode(array('ok' => false, 'error' => 'No order IDs supplied')); exit; }
    @set_time_limit(300);
    echo json_encode(array('ok' => true, 'result' => $sync->refreshFullCacheBatch($orderIds, true)));
    exit;
}

// AJAX: list cached order JSON records
if ($action === 'setup_cached_json_list') {
    header('Content-Type: application/json');
    if (!$user->admin) { echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit; }
    echo json_encode(array('ok' => true, 'orders' => $sync->getCachedOrderJsonRows()));
    exit;
}

// AJAX: return one cached order JSON record
if ($action === 'setup_cached_json_item') {
    header('Content-Type: application/json');
    if (!$user->admin) { echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit; }
    $wooOrderId = GETPOST('woo_order_id', 'alphanohtml');
    if ($wooOrderId === '') { echo json_encode(array('ok' => false, 'error' => 'Missing order ID')); exit; }
    $rawJson = $sync->getCachedOrderJson($wooOrderId);
    if ($rawJson === null) { echo json_encode(array('ok' => false, 'error' => 'No cached JSON found for this order')); exit; }
    echo json_encode(array('ok' => true, 'json' => $rawJson));
    exit;
}

if ($action === 'desync_ajax') {
    header('Content-Type: application/json');
    if (!$user->admin) { echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit; }
    list($ok, $msg, $stats) = $sync->desyncAllSyncedEntries(GETPOST('delete_accounts', 'int') ? true : false);
    echo json_encode(array('ok' => $ok, 'message' => $msg, 'stats' => $stats ?? array()));
    exit;
}

llxHeader('', $langs->trans('FinanceAutomationHubSetup'));
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>';
?>
<?php echo load_fiche_titre($langs->trans('FinanceAutomationHubSetup'), $linkback, 'title_setup'); ?>
<?php

?>
<div class="center" style="margin-bottom:12px;">
<form method="POST" action="<?php echo dol_escape_htmltag($fahSetupAction); ?>" style="display:inline;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="dbcheck">
<input class="button" type="submit" value="Run/update database checks without disabling module">
</form>
 &nbsp; <button class="button" type="button" onclick="fahSetupOpenLogModal()">View sync log</button>
 &nbsp; <a class="button" href="<?php echo DOL_URL_ROOT; ?>/custom/financeautomationhub/reports.php?mainmenu=financeautomationhub">Sales analytics</a>
</div>
    <!-- Log viewer modal -->
<div id="fahLogModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:95%;max-width:1100px;max-height:90vh;display:flex;flex-direction:column;">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;align-items:center;gap:12px;">
      <b style="flex:1;font-size:1.1em;">Sync log</b>
      <input type="text" id="fahLogSearch" placeholder="Search order #, invoice, payment, status…" class="flat" style="width:300px;" oninput="fahSetupFilterLog()">
      <span id="fahLogCount" style="font-size:0.85em;color:#888;white-space:nowrap;"></span>
      <button class="button" type="button" onclick="fahSetupEsc('fahLogModal')">Close</button>
    </div>
    <div style="overflow:auto;flex:1;">
      <table class="liste centpercent" id="fahLogTable" style="font-size:0.82em;">
        <thead><tr class="liste_titre">
          <th>Date sync</th><th>Channel</th><th>Order #</th><th>Invoice #</th><th>Payment</th>
          <th class="right">Gross</th><th class="right">Fee</th><th>Fee source</th><th class="right">Net</th><th>Status</th><th>PDF</th><th>Message</th>
        </tr></thead>
        <tbody id="fahLogBody"><tr><td colspan="12" style="text-align:center;padding:20px;color:#888;">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>
<?php
$fahWarehouses = $sync->inventory()->getWarehouses();
$fahConnectorDefinitions = array(
    'woocommerce' => array('label' => 'WooCommerce', 'description' => 'Online shop orders, payments, invoices and stock'),
    'amazon' => array('label' => 'Amazon Seller', 'description' => 'Seller orders, listings and stock; Amazon invoices remain in Amazon'),
    'sumup' => array('label' => 'SumUp', 'description' => 'Card transactions and product lines, with Dolibarr POS duplicate protection'),
);
$fahSelectedConnector = $fahRequestedConnector;
if ($fahSelectedConnector === '') $fahSelectedConnector = strtolower((string) GETPOST('connector', 'alpha'));
if (!isset($fahConnectorDefinitions[$fahSelectedConnector])) $fahSelectedConnector = '';
$fahWooSetupAction = $_SERVER['PHP_SELF'] . '?connector_view=woocommerce';
?>
<h2>Sales channel submodules</h2>
<p class="opacitymedium">This list stays compact as more connectors are added. Activate only the channels you use, then configure one channel at a time.</p>
<div style="overflow-x:auto;margin-bottom:12px;"><table class="liste centpercent">
<tr class="liste_titre"><th>Submodule</th><th>Description</th><th>Status</th><th>Stock</th><th></th></tr>
<?php foreach ($fahConnectorDefinitions as $connectorKey => $connectorDefinition) {
    $connectorPrefix = 'FAH_' . strtoupper($connectorKey) . '_';
    $connectorEnabled = $connectorKey === 'woocommerce' && !isset($conf->global->{$connectorPrefix . 'ENABLED'}) ? true : !empty($conf->global->{$connectorPrefix . 'ENABLED'});
?>
<tr class="oddeven"><td><strong><?php echo dol_escape_htmltag($connectorDefinition['label']); ?></strong></td><td><?php echo dol_escape_htmltag($connectorDefinition['description']); ?></td><td><?php echo $connectorEnabled ? '<span class="badge badge-status4">Active</span>' : '<span class="badge badge-status0">Inactive</span>'; ?></td><td><?php echo !empty($conf->global->{$connectorPrefix . 'STOCK_ENABLED'}) ? 'Deduction active' : 'Off'; ?></td><td class="right"><a class="button" href="<?php echo dol_escape_htmltag($_SERVER['PHP_SELF'] . '?connector_view=' . $connectorKey); ?>">Configure</a></td></tr>
<?php } ?>
</table></div>

<?php if ($fahSelectedConnector !== '') { ?>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?connector_view=<?php echo dol_escape_htmltag($fahSelectedConnector); ?>" style="border:1px solid #ccc;border-radius:6px;padding:16px;margin-bottom:18px;max-width:900px;">
  <input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="save_connector"><input type="hidden" name="connector" value="<?php echo dol_escape_htmltag($fahSelectedConnector); ?>">
  <?php $fahSelectedPrefix = 'FAH_' . strtoupper($fahSelectedConnector) . '_'; $fahSelectedDefinition = $fahConnectorDefinitions[$fahSelectedConnector]; ?>
  <h3 style="margin-top:0;">Configure <?php echo dol_escape_htmltag($fahSelectedDefinition['label']); ?></h3>
  <p><label><input type="checkbox" name="enabled" value="1"<?php echo ($fahSelectedConnector === 'woocommerce' && !isset($conf->global->{$fahSelectedPrefix . 'ENABLED'})) || !empty($conf->global->{$fahSelectedPrefix . 'ENABLED'}) ? ' checked' : ''; ?>> Submodule active</label> &nbsp; <label><input type="checkbox" name="stock_enabled" value="1"<?php echo !empty($conf->global->{$fahSelectedPrefix . 'STOCK_ENABLED'}) ? ' checked' : ''; ?>> Deduct mapped products from stock</label></p>
  <?php if ($fahSelectedConnector === 'amazon') { ?>
    <p><label>LWA client ID<br><input class="flat minwidth500" name="lwa_client_id" value="<?php echo dol_escape_htmltag($conf->global->FAH_AMAZON_LWA_CLIENT_ID ?? ''); ?>"></label></p>
    <p><label>LWA client secret<br><input class="flat minwidth500" type="password" name="lwa_client_secret" placeholder="Leave blank to keep saved secret"></label></p>
    <p><label>Refresh token<br><input class="flat minwidth500" type="password" name="refresh_token" placeholder="Leave blank to keep saved token"></label></p>
    <p><label>Seller ID<br><input class="flat minwidth500" name="seller_id" value="<?php echo dol_escape_htmltag($conf->global->FAH_AMAZON_SELLER_ID ?? ''); ?>"></label></p>
    <p><label>Marketplace IDs <span class="opacitymedium">(comma-separated)</span><br><input class="flat minwidth500" name="marketplace_ids" value="<?php echo dol_escape_htmltag($conf->global->FAH_AMAZON_MARKETPLACE_IDS ?? ''); ?>"></label></p>
    <p><label>Region <select class="flat" name="region"><?php foreach (array('eu' => 'Europe', 'na' => 'North America', 'fe' => 'Far East') as $value => $label) { ?><option value="<?php echo $value; ?>"<?php echo ($conf->global->FAH_AMAZON_REGION ?? 'eu') === $value ? ' selected' : ''; ?>><?php echo $label; ?></option><?php } ?></select></label></p>
    <p><label>Sync from date<br><input class="flat" type="date" name="sync_from_date" value="<?php echo dol_escape_htmltag($conf->global->FAH_AMAZON_SYNC_FROM_DATE ?? ''); ?>"></label></p>
    <p><label><input type="checkbox" name="finance_enabled" value="1"<?php echo !isset($conf->global->FAH_AMAZON_FINANCE_ENABLED) || !empty($conf->global->FAH_AMAZON_FINANCE_ENABLED) ? ' checked' : ''; ?>> Retrieve exact Amazon expenses and net proceeds through Finances API</label></p>
    <div class="info">Amazon generates its own invoices. With the current API roles, Finance Automation Hub does not request or download Amazon invoice PDFs; the invoice remains available in Amazon Seller Central.</div>
  <?php } elseif ($fahSelectedConnector === 'sumup') { ?>
    <p><label>Access token<br><input class="flat minwidth500" type="password" name="access_token" placeholder="Leave blank to keep saved token"></label></p>
    <p><label>Merchant code<br><input class="flat minwidth500" name="merchant_code" value="<?php echo dol_escape_htmltag($conf->global->FAH_SUMUP_MERCHANT_CODE ?? ''); ?>"></label></p>
    <p><label>Sync from date<br><input class="flat" type="date" name="sync_from_date" value="<?php echo dol_escape_htmltag($conf->global->FAH_SUMUP_SYNC_FROM_DATE ?? ''); ?>"></label></p>
    <div class="warning" style="margin:10px 0;"><strong>Existing Dolibarr POS + SumUp module:</strong> when that module already creates the sale, payment and stock movement, enable duplicate protection here. The transaction is still counted in sales analytics, but Finance Automation Hub will not create a second bank or stock entry.</div>
    <?php $fahPosMode = (string) ($conf->global->FAH_SUMUP_POS_DUPLICATE_MODE ?? 'off'); ?>
    <p><label>POS duplicate protection<br><select class="flat minwidth300" name="pos_duplicate_mode"><option value="off"<?php echo $fahPosMode === 'off' ? ' selected' : ''; ?>>Off — Finance Automation Hub owns SumUp imports</option><option value="all"<?php echo $fahPosMode === 'all' ? ' selected' : ''; ?>>Skip all — Dolibarr POS module owns all SumUp sales</option><option value="reference"<?php echo $fahPosMode === 'reference' ? ' selected' : ''; ?>>Skip only matching POS references</option></select></label></p>
    <p><label>POS reference prefixes <span class="opacitymedium">(comma-separated; reference mode only)</span><br><input class="flat minwidth500" name="pos_reference_prefixes" value="<?php echo dol_escape_htmltag($conf->global->FAH_SUMUP_POS_REFERENCE_PREFIXES ?? ''); ?>" placeholder="POS-, TAKEPOS-, DOLIBARR-"></label></p>
    <div class="info">SumUp receipts remain in SumUp. No SumUp receipt/invoice PDF download is attempted.</div>
  <?php } ?>
  <p><label>Fallback stock warehouse<br><?php echo fah_warehouse_select('warehouse_id', $conf->global->{$fahSelectedPrefix . 'WAREHOUSE_ID'} ?? '', $fahWarehouses); ?></label><br><span class="opacitymedium">Used only when a product recipe component has no explicit warehouse. Per-product warehouses are configured below.</span></p>
  <button class="button button-save" type="submit">Save <?php echo dol_escape_htmltag($fahSelectedDefinition['label']); ?> settings</button>
</form>
<?php if (in_array($fahSelectedConnector, array('amazon', 'sumup'), true)) {
    $fahFinanceMap = $sync->channelFinanceMap($fahSelectedConnector);
    $fahFinanceBankId = (int) ($fahFinanceMap[$fahSelectedConnector]['bank_id'] ?? 0);
    $fahFeeSourceLabel = $fahSelectedConnector === 'amazon' ? 'Amazon Finances API expense breakdowns' : 'SumUp transaction detail fee_amount';
?>
<div style="border:1px solid #ccc;border-radius:6px;padding:16px;margin-bottom:18px;max-width:900px;">
  <h3 style="margin-top:0;">Virtual bank and cost mapping</h3>
  <p class="opacitymedium">Like WooCommerce gateway mapping, the gross sale, exact provider cost, and net payout are recorded separately. The Dolibarr clearing account receives the net payout.</p>
  <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?connector_view=<?php echo dol_escape_htmltag($fahSelectedConnector); ?>">
    <input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="save_channel_finance"><input type="hidden" name="connector" value="<?php echo dol_escape_htmltag($fahSelectedConnector); ?>">
    <table class="noborder centpercent"><tr class="liste_titre"><th>Payment source</th><th>Exact cost source</th><th>Dolibarr virtual/clearing bank</th></tr>
    <tr><td><strong><?php echo dol_escape_htmltag($fahSelectedDefinition['label']); ?></strong></td><td><?php echo dol_escape_htmltag($fahFeeSourceLabel); ?></td><td><?php echo fah_bank_select('finance_bank_id', $fahFinanceBankId); ?></td></tr></table>
    <div class="center"><button class="button button-save" type="submit">Save finance mapping</button></div>
  </form>
  <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?connector_view=<?php echo dol_escape_htmltag($fahSelectedConnector); ?>" class="center" style="margin-top:8px;">
    <input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="auto_channel_account"><input type="hidden" name="connector" value="<?php echo dol_escape_htmltag($fahSelectedConnector); ?>">
    <button class="button" type="submit">Create/reuse and map <?php echo dol_escape_htmltag($fahSelectedDefinition['label']); ?> virtual bank automatically</button>
  </form>
</div>
<?php } ?>
<?php } else { ?><div class="info" style="margin-bottom:18px;">Choose <strong>Configure</strong> for a sales channel. The module dashboard is separate and no connector configuration is selected by default.</div><?php } ?>

<?php if ($fahSelectedConnector === 'woocommerce') { ?>
<form method="POST" action="<?php echo dol_escape_htmltag($fahWooSetupAction); ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="save_api">
<table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">WooCommerce API</td></tr>
<?php
$fields = array(
    'FAH_WOO_URL' => 'WooCommerce URL',
    'FAH_WOO_CONSUMER_KEY' => 'Consumer key',
    'FAH_WOO_CONSUMER_SECRET' => 'Consumer secret',
    'FAH_SYNC_FROM_DATE' => 'Sync from date (YYYY-MM-DD)',
    'FAH_ORDER_STATUSES' => 'Order statuses',
);
foreach ($fields as $key => $label) {
    $type = ($key === 'FAH_WOO_CONSUMER_SECRET') ? 'password' : 'text';
    $defaultValue = '';
    if ($key === 'FAH_ORDER_STATUSES') $defaultValue = 'processing,completed';
    $value = isset($conf->global->$key) ? $conf->global->$key : $defaultValue;
?>
<tr><td class="titlefield"><?php echo dol_escape_htmltag($label); ?></td><td><input class="flat minwidth500" type="<?php echo $type; ?>" name="<?php echo $key; ?>" value="<?php echo dol_escape_htmltag($value); ?>"></td></tr>
<?php
}
?>
<tr><td class="titlefield">Stripe secret key</td><td><input class="flat minwidth500" type="password" name="FAH_STRIPE_SECRET_KEY" value="" placeholder="Leave blank to keep the saved key">
<br><span class="opacitymedium">Used only when Stripe or Klarna fee metadata is missing. The exact fee is read from Stripe's balance transaction.</span></td></tr>
<tr><td class="titlefield">Stripe connected account ID</td><td><input class="flat minwidth500" type="text" name="FAH_STRIPE_ACCOUNT_ID" value="<?php echo dol_escape_htmltag($conf->global->FAH_STRIPE_ACCOUNT_ID ?? ''); ?>" placeholder="Optional acct_..."></td></tr>
<tr><td>Dry run</td><td><input type="checkbox" name="FAH_DRY_RUN" value="1"<?php echo !empty($conf->global->FAH_DRY_RUN) ? ' checked' : ''; ?>> Do not write bank lines</td></tr>
</table>
<div class="center"><input class="button button-save" type="submit" value="Save API settings"></div>
</form><br>

<form method="POST" action="<?php echo dol_escape_htmltag($fahWooSetupAction); ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="save_batch_sizes">
<table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">Workflow batch sizes</td></tr>
<?php
$batchFields = array(
    'FAH_CACHE_BATCH_SIZE' => array('Full cache refresh items per batch', 1),
    'FAH_SYNC_BATCH_SIZE' => array('Sync items per batch', 10),
    'FAH_DIFF_BATCH_SIZE' => array('Difference check items per batch', 10),
);
foreach ($batchFields as $key => $settings) {
    $value = max(1, min(100, (int) ($conf->global->$key ?? $settings[1])));
?>
<tr><td class="titlefield"><?php echo dol_escape_htmltag($settings[0]); ?></td>
<td><input class="flat" type="number" min="1" max="100" name="<?php echo $key; ?>" value="<?php echo $value; ?>">
 <span class="opacitymedium">Allowed range: 1–100</span></td></tr>
<?php
}
?>
</table>
<div class="center"><input class="button button-save" type="submit" value="Save batch sizes"></div>
</form><br>

<form method="POST" action="<?php echo dol_escape_htmltag($fahWooSetupAction); ?>" style="display:inline-block;margin-right:8px;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="refresh">
<input class="button" type="submit" value="Refresh active and used payment methods from WooCommerce">
</form>
<form method="POST" action="<?php echo dol_escape_htmltag($fahWooSetupAction); ?>" style="display:inline-block;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="autosetup">
<input class="button" type="submit" value="Yes, create virtual bank accounts and map listed gateways">
</form>
<form method="POST" action="<?php echo dol_escape_htmltag($fahWooSetupAction); ?>" style="display:inline-block;margin-left:8px;" onsubmit="return confirm('Apply mapped product and bundle recipes to all recorded WooCommerce sales? Existing movement IDs prevent duplicate deductions.');">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="backfill_woo_stock">
<input class="button" type="submit" value="Apply mapped stock to already synced orders">
</form><br><span class="opacitymedium">Enable stock deduction above first. This action is safe to repeat and reports unmapped or failed lines.</span><br><br>
<?php

$gateways = $sync->getJsonConst('FAH_GATEWAYS_JSON', array());
$metaByGateway = $sync->getJsonConst('FAH_META_KEYS_JSON', array());
$map = $sync->gatewayMap();

?>
<form method="POST" action="<?php echo dol_escape_htmltag($fahWooSetupAction); ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="save_map">
<div style="overflow-x:auto;">
<table class="noborder" style="width:100%;min-width:640px;">
<tr class="liste_titre"><td colspan="7">WooCommerce payment methods mapping (active or used)</td></tr>
<tr class="liste_titre">
  <th style="white-space:nowrap;">Gateway ID</th>
  <th>Title</th>
  <th style="white-space:nowrap;">Source</th>
  <th style="white-space:nowrap;text-align:center;">Orders</th>
  <th>Dolibarr bank account</th>
  <th style="white-space:nowrap;">Fee meta key<br><span style="font-weight:normal;font-size:0.82em;opacity:.7;">e.g. _ppcp_paypal_fees</span></th>
  <th style="white-space:nowrap;">Payout meta key<br><span style="font-weight:normal;font-size:0.82em;opacity:.7;">net amount from provider, optional</span></th>
</tr>
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
<td style="white-space:nowrap;"><strong><?php echo dol_escape_htmltag($gid); ?></strong></td>
<td><?php echo dol_escape_htmltag($gateway['title'] ?? $gid); ?></td>
<td style="white-space:nowrap;"><?php echo dol_escape_htmltag($gateway['source'] ?? (!empty($gateway['enabled']) ? 'active' : 'historical')); ?></td>
<td style="text-align:center;"><?php echo (int) ($gateway['orders_count'] ?? 0); ?></td>
<td><?php echo fah_bank_select('FAH_MAP_BANK_' . $safe, $selected['bank_id'] ?? ''); ?></td>
<td><?php echo fah_meta_select('FAH_MAP_FEE_' . $safe, $keys, $selected['fee_key'] ?? '', '-- auto detect --', 'min-width:140px;max-width:220px;width:100%;'); ?></td>
<td><?php echo fah_meta_select('FAH_MAP_PAYOUT_' . $safe, $keys, $selected['payout_key'] ?? '', '-- not configured --', 'min-width:140px;max-width:220px;width:100%;'); ?></td>
</tr>
<?php
    }
}
?>
</table>
</div>
<div class="center"><input class="button button-save" type="submit" value="Save mapping"></div>
</form><br>
<?php
$bankExtraFields  = $sync->getBankAmountExtraFields();
$mappedGrossField = (string) ($conf->global->FAH_EXTRAFIELD_GROSS_CODE ?? '');
$mappedFeeField   = (string) ($conf->global->FAH_EXTRAFIELD_FEE_CODE ?? '');
$grossFieldLabel  = $bankExtraFields[$mappedGrossField] ?? 'WooCommerce gross amount';
$feeFieldLabel    = $bankExtraFields[$mappedFeeField] ?? 'WooCommerce fee amount';
?>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?connector_view=woocommerce">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">WooCommerce amount custom fields (gross and fee)</td></tr>
<tr><td colspan="2" class="opacitymedium" style="padding-bottom:8px;">
Each WooCommerce bank entry is created with <strong>net amount received</strong> (gross &minus; fee). The original gross and fee
can optionally be stored in Dolibarr bank-entry custom fields for WooCommerce reports and exports.
Create numeric custom fields yourself and map them here, or use the explicit automatic creation button below.
</td></tr>
<tr><td class="titlefield">Gross amount field</td><td>
<select class="flat minwidth300" name="FAH_EXTRAFIELD_GROSS_CODE">
<option value="">-- not mapped --</option>
<?php foreach ($bankExtraFields as $code => $fieldLabel) { ?>
<option value="<?php echo dol_escape_htmltag($code); ?>"<?php echo $code === $mappedGrossField ? ' selected' : ''; ?>><?php echo dol_escape_htmltag($fieldLabel . ' (' . $code . ')'); ?></option>
<?php } ?>
</select>
<br><span class="opacitymedium">Custom field that will receive the original WooCommerce order gross total.</span>
</td></tr>
<tr><td class="titlefield">Gross field label</td><td>
<input class="flat minwidth300" type="text" name="FAH_EXTRAFIELD_GROSS_LABEL" value="<?php echo dol_escape_htmltag($grossFieldLabel); ?>" maxlength="255">
</td></tr>
<tr><td class="titlefield">Fee amount field</td><td>
<select class="flat minwidth300" name="FAH_EXTRAFIELD_FEE_CODE">
<option value="">-- not mapped --</option>
<?php foreach ($bankExtraFields as $code => $fieldLabel) { ?>
<option value="<?php echo dol_escape_htmltag($code); ?>"<?php echo $code === $mappedFeeField ? ' selected' : ''; ?>><?php echo dol_escape_htmltag($fieldLabel . ' (' . $code . ')'); ?></option>
<?php } ?>
</select>
<br><span class="opacitymedium">Custom field that will receive the WooCommerce payment processor fee (Gebühr).</span>
</td></tr>
<tr><td class="titlefield">Fee field label</td><td>
<input class="flat minwidth300" type="text" name="FAH_EXTRAFIELD_FEE_LABEL" value="<?php echo dol_escape_htmltag($feeFieldLabel); ?>" maxlength="255">
<br><span class="opacitymedium">Saving also renames the selected fields' display labels.</span>
</td></tr>
</table>
<div class="center">
<button class="button button-save" type="submit" name="action" value="save_amount_fields">Save mapping and labels</button>
<button class="button" type="submit" name="action" value="create_amount_extrafields">Create and map missing amount fields automatically</button>
<button class="button" type="submit" name="action" value="repair_amount_extrafields">Repair existing bank entries</button>
</div>
<div class="center opacitymedium">Repair rewrites gross, fee, and invoice values on already synced WooCommerce bank entries using the current mappings.</div>
</form><br>
<?php } ?>
<?php

$fahCatalogConnector = strtolower((string) GETPOST('catalog_connector', 'alpha'));
if (!isset($fahConnectorDefinitions[$fahCatalogConnector])) $fahCatalogConnector = '';
$fahCatalog = $sync->inventory()->getCatalog($fahCatalogConnector);
$fahProducts = $sync->inventory()->getDolibarrProducts();
?>
<div style="margin:18px 0 8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
  <h2 style="margin:0;flex:1;">Product and bundle stock recipes</h2>
  <form method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="margin:0;">
    <?php if ($fahSelectedConnector !== '') { ?><input type="hidden" name="connector_view" value="<?php echo dol_escape_htmltag($fahSelectedConnector); ?>"><?php } ?>
    <label>Channel <select class="flat" name="catalog_connector" onchange="this.form.submit()"><option value="">All active catalogues</option><?php foreach ($fahConnectorDefinitions as $connectorKey => $connectorDefinition) { ?><option value="<?php echo dol_escape_htmltag($connectorKey); ?>"<?php echo $fahCatalogConnector === $connectorKey ? ' selected' : ''; ?>><?php echo dol_escape_htmltag($connectorDefinition['label']); ?></option><?php } ?></select></label>
  </form>
  <?php
  $fahRefreshConnector = $fahCatalogConnector !== '' ? $fahCatalogConnector : $fahSelectedConnector;
  $fahRefreshActions = array('woocommerce' => 'refresh_woo_catalog', 'amazon' => 'refresh_amazon_catalog', 'sumup' => 'refresh_sumup_catalog');
  if (isset($fahRefreshActions[$fahRefreshConnector])) { ?>
  <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>?catalog_connector=<?php echo dol_escape_htmltag($fahRefreshConnector); ?>" style="margin:0;">
    <input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="<?php echo dol_escape_htmltag($fahRefreshActions[$fahRefreshConnector]); ?>"><input type="hidden" name="connector_view" value="<?php echo dol_escape_htmltag($fahSelectedConnector); ?>">
    <button class="button" type="submit">Refresh <?php echo dol_escape_htmltag($fahConnectorDefinitions[$fahRefreshConnector]['label']); ?> catalogue</button>
  </form><?php } ?>
</div>
<p class="opacitymedium">Choose <strong>single or bundle recipe</strong>, then select every Dolibarr stock component, its source warehouse, and how many pieces one sold channel item consumes. The same Dolibarr product may use a different warehouse in WooCommerce, Amazon, and SumUp recipes. Leaving a component warehouse blank uses that connector's fallback warehouse.</p>
<div style="overflow-x:auto;max-height:760px;overflow-y:auto;border:1px solid #ddd;">
<table class="liste centpercent" style="min-width:1180px;">
<tr class="liste_titre"><th>Channel product</th><th>Stock behavior</th><th>Dolibarr components per one sold item</th><th></th></tr>
<?php if (empty($fahCatalog)) { ?>
<tr><td colspan="4" class="opacitymedium">No channel products discovered yet. Run the database check, refresh WooCommerce products, or sync Amazon/SumUp once.</td></tr>
<?php } else { foreach ($fahCatalog as $catalogRow) {
    $componentByIndex = array_values($catalogRow['components']);
    $recipeFormId = 'fahRecipe' . (int) $catalogRow['id'];
?>
<tr class="oddeven">
  <td style="vertical-align:top;min-width:220px;">
    <form method="POST" action="<?php echo dol_escape_htmltag($fahSetupAction); ?>" id="<?php echo $recipeFormId; ?>"><input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="save_stock_recipe"><input type="hidden" name="catalog_id" value="<?php echo (int) $catalogRow['id']; ?>"><input type="hidden" name="catalog_connector" value="<?php echo dol_escape_htmltag($fahCatalogConnector); ?>"></form>
    <strong><?php echo dol_escape_htmltag(ucfirst($catalogRow['connector'])); ?></strong><br><?php echo dol_escape_htmltag($catalogRow['label']); ?><br><span class="opacitymedium"><?php echo dol_escape_htmltag($catalogRow['sku'] !== '' ? 'SKU ' . $catalogRow['sku'] : 'ID ' . $catalogRow['external_product_id'] . ($catalogRow['external_variant_id'] !== '' ? ' / ' . $catalogRow['external_variant_id'] : '')); ?></span></td>
  <td style="vertical-align:top;min-width:185px;">
      <select class="flat" name="stock_mode" form="<?php echo $recipeFormId; ?>" style="width:100%;">
        <option value="unmapped"<?php echo $catalogRow['stock_mode'] === 'unmapped' ? ' selected' : ''; ?>>Not mapped yet</option>
        <option value="recipe"<?php echo $catalogRow['stock_mode'] === 'recipe' ? ' selected' : ''; ?>>Single / bundle recipe</option>
        <option value="ignore"<?php echo $catalogRow['stock_mode'] === 'ignore' ? ' selected' : ''; ?>>Do not change stock</option>
      </select>
      <label style="display:block;margin-top:8px;"><input type="checkbox" name="is_bundle" value="1" form="<?php echo $recipeFormId; ?>"<?php echo !empty($catalogRow['is_bundle']) ? ' checked' : ''; ?>> This channel product is a bundle / multipack</label>
      <div class="opacitymedium" style="margin-top:6px;"><?php echo !empty($catalogRow['is_bundle']) ? 'Bundle: ' . count($componentByIndex) . ' component type(s)' : (count($componentByIndex) === 1 ? 'Single product mapping' : 'No components saved'); ?></div>
  </td>
  <td style="vertical-align:top;min-width:500px;">
      <div id="components-<?php echo $recipeFormId; ?>">
      <?php $visibleComponents = max(4, count($componentByIndex) + 1); for ($componentIndex = 0; $componentIndex < $visibleComponents; $componentIndex++) {
          $savedComponent = $componentByIndex[$componentIndex] ?? array('product_id' => '', 'warehouse_id' => '', 'quantity' => '');
      ?>
      <div class="fah-component-row" style="display:grid;grid-template-columns:minmax(260px,1fr) minmax(220px,0.8fr) 90px;gap:8px;margin-bottom:5px;">
        <?php echo fah_product_select('component_product[]', $savedComponent['product_id'], $fahProducts, $recipeFormId); ?>
        <?php echo fah_warehouse_select('component_warehouse[]', $savedComponent['warehouse_id'], $fahWarehouses, $recipeFormId); ?>
        <input class="flat" type="number" min="0" step="0.0001" name="component_quantity[]" form="<?php echo $recipeFormId; ?>" value="<?php echo dol_escape_htmltag($savedComponent['quantity']); ?>" placeholder="Qty">
      </div>
      <?php } ?>
      </div><button class="button" type="button" onclick="fahAddRecipeComponent('<?php echo $recipeFormId; ?>')">+ Add component</button>
  </td>
  <td style="vertical-align:top;"><button class="button button-save" type="submit" form="<?php echo $recipeFormId; ?>">Save recipe</button></td>
</tr>
<?php }} ?>
</table>
</div>
<script>
function fahAddRecipeComponent(formId) {
  var container = document.getElementById('components-' + formId);
  if (!container) return;
  var rows = container.querySelectorAll('.fah-component-row');
  if (!rows.length) return;
  var row = rows[rows.length - 1].cloneNode(true);
  var select = row.querySelector('select');
  var selects = row.querySelectorAll('select');
  var quantity = row.querySelector('input');
  for (var i = 0; i < selects.length; i++) selects[i].value = '';
  if (quantity) quantity.value = '';
  container.appendChild(row);
}
</script>
<?php
$fahMovements = $sync->inventory()->getStockMovementLog(100);
?>
<details style="margin:10px 0 20px;"><summary style="cursor:pointer;font-weight:bold;">Recent channel stock deductions (<?php echo count($fahMovements); ?>)</summary>
<div style="overflow-x:auto;max-height:420px;overflow-y:auto;margin-top:8px;"><table class="liste centpercent">
<tr class="liste_titre"><th>Date</th><th>Channel</th><th>Order</th><th>Dolibarr product</th><th>Source warehouse</th><th>Destination</th><th>Native movement</th><th class="right">Deducted</th><th>Status</th><th>Error</th></tr>
<?php if (empty($fahMovements)) { ?><tr><td colspan="10" class="opacitymedium">No stock deductions recorded yet.</td></tr><?php } else { foreach ($fahMovements as $movementRow) { ?>
<tr class="oddeven"><td><?php echo dol_escape_htmltag((string) $movementRow->date_order); ?></td><td><?php echo dol_escape_htmltag(ucfirst((string) $movementRow->connector)); ?></td><td><?php echo dol_escape_htmltag((string) ($movementRow->external_order_number ?: $movementRow->external_order_id)); ?></td><td><?php echo dol_escape_htmltag(trim((string) $movementRow->ref . ' - ' . (string) $movementRow->product_label)); ?></td><td><?php echo dol_escape_htmltag((string) $movementRow->warehouse_ref); ?></td><td><?php echo dol_escape_htmltag((string) $movementRow->destination); ?></td><td><?php echo !empty($movementRow->fk_stock_movement) ? '#' . (int) $movementRow->fk_stock_movement : ''; ?></td><td class="right">-<?php echo price((float) $movementRow->quantity); ?></td><td><?php echo dol_escape_htmltag((string) $movementRow->status); ?></td><td><?php echo dol_escape_htmltag((string) $movementRow->error_message); ?></td></tr>
<?php }} ?>
</table></div></details>
<?php if ($fahSelectedConnector === 'woocommerce') { ?>
    <!-- Invoice data cache (debug / development) -->
<br><table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">Invoice data cache &amp; JSON viewer (debugging / development)</td></tr>
<tr><td class="titlefield">Refresh invoice cache</td><td>
<div style="margin-bottom:6px;">
<label><input type="radio" name="fahCacheRange" id="fahCacheRangeLatest" value="latest" checked onchange="document.getElementById('fahCacheLimitWrap').style.display='inline';"> Latest&nbsp;</label>
<span id="fahCacheLimitWrap"><input type="number" id="fahCacheLimit" value="50" min="1" max="9999" style="width:60px;margin:0 3px;">&nbsp;orders</span>
&nbsp;&nbsp;&nbsp;<label><input type="radio" name="fahCacheRange" value="all" onchange="document.getElementById('fahCacheLimitWrap').style.display='none';"> All synced orders</label>
</div>
<button class="button" type="button" onclick="fahSetupOpenCacheModal()">Refresh invoice cache</button>

<br><span class="opacitymedium">Fetches invoice number and PDF URL from WooCommerce for each synced order and updates the local cache. 
Also stores the full WooCommerce JSON for use with the viewer below. Use a limited range for speed.</span>

</td></tr>
<tr><td class="titlefield">View cached order JSON</td><td>
<button class="button" type="button" onclick="fahSetupOpenJsonModal()">View cached Woo JSON</button>
<br><span class="opacitymedium">Shows the raw WooCommerce order JSON stored locally after a cache refresh. Useful for debugging invoice field extraction.</span>
</td></tr>
</table>
    <!-- Cache refresh modal -->
<div id="fahSetupCacheModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:72%;max-width:860px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.25);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
      <strong>Refreshing invoice cache&hellip;</strong>
      <button type="button" onclick="fahSetupEsc('fahSetupCacheModal')" style="border:none;background:none;font-size:22px;line-height:1;cursor:pointer;color:#666;">&times;</button>
    </div>
    <div style="padding:16px 20px;overflow-y:auto;flex:1;">
      <div id="fahSetupCacheProgress" style="height:18px;background:#eee;border-radius:4px;margin-bottom:12px;"><div id="fahSetupCacheBar" style="height:100%;width:0;background:#0082c3;border-radius:4px;transition:width .3s;"></div></div>
      <div id="fahSetupCacheStatus" style="margin-bottom:10px;font-size:0.9em;color:#555;"></div>
      <ul id="fahSetupCacheLog" style="font-size:0.82em;max-height:340px;overflow-y:auto;margin:0;padding-left:18px;"></ul>
    </div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;">
      <button class="button" type="button" onclick="fahSetupEsc('fahSetupCacheModal')">Close</button>
    </div>
  </div>
</div>
    <!-- JSON viewer modal -->
<div id="fahSetupJsonModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:88%;max-width:1100px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.25);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
      <strong>Cached WooCommerce order JSON</strong>
      <button type="button" onclick="fahSetupEsc('fahSetupJsonModal')" style="border:none;background:none;font-size:22px;line-height:1;cursor:pointer;color:#666;">&times;</button>
    </div>
    <div style="padding:12px 20px;border-bottom:1px solid #eee;">
      <select id="fahSetupJsonSelect" style="width:100%;max-width:420px;" onchange="fahSetupShowCachedJson(this.value)">
        <option value="">Loading orders&hellip;</option>
      </select>
    </div>
    <div style="padding:16px 20px;overflow-y:auto;flex:1;">
      <pre id="fahSetupJsonPre" style="font-size:0.82em;margin:0;white-space:pre-wrap;word-break:break-all;"></pre>
    </div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;">
      <button class="button" type="button" onclick="fahSetupEsc('fahSetupJsonModal')">Close</button>
    </div>
  </div>
</div>
<?php } ?>

<script>
var _fahSetupAjaxUrl = <?php echo json_encode($_SERVER['PHP_SELF']); ?>;
var _fahIndexAjaxUrl = <?php echo json_encode(DOL_URL_ROOT . '/custom/financeautomationhub/index.php'); ?>;
var _fahSetupToken = <?php echo json_encode(newToken()); ?>;
var _fahSetupCacheOrders = [], _fahSetupCacheIdx = 0, _fahSetupCacheBatch = 10;
var _fahSetupCacheUpdated = 0, _fahSetupCacheErrors = 0;
function fahSetupEsc(id){document.getElementById(id).style.display="none";}
function fahEsc(s){return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");}
function fahSetupOpenCacheModal(){
  var rangeLatest=document.getElementById("fahCacheRangeLatest").checked;
  var limit=rangeLatest?parseInt(document.getElementById("fahCacheLimit").value,10):0;
  if(isNaN(limit)||limit<1)limit=rangeLatest?50:0;
  _fahSetupCacheOrders=[];_fahSetupCacheIdx=0;_fahSetupCacheUpdated=0;_fahSetupCacheErrors=0;
  document.getElementById("fahSetupCacheBar").style.width="0";
  document.getElementById("fahSetupCacheStatus").textContent="Loading order list…";
  document.getElementById("fahSetupCacheLog").innerHTML="";
  document.getElementById("fahSetupCacheModal").style.display="flex";
  var fd=new FormData();fd.append("token",_fahSetupToken);fd.append("action","setup_cache_refresh_list");
  if(limit>0)fd.append("limit",limit);
  fetch(_fahSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    if(!d.ok){document.getElementById("fahSetupCacheStatus").textContent="Error: "+d.error;return;}
    _fahSetupCacheOrders=d.orders;
    _fahSetupCacheBatch=d.batch_size||10;
    if(!_fahSetupCacheOrders.length){document.getElementById("fahSetupCacheStatus").textContent="No synced orders found.";return;}
    document.getElementById("fahSetupCacheStatus").textContent="Found "+_fahSetupCacheOrders.length+" orders. Refreshing cache…";
    fahSetupRefreshCacheBatch();
  }).catch(function(e){document.getElementById("fahSetupCacheStatus").textContent="Request failed: "+e;});
}
function fahSetupRefreshCacheBatch(){
  if(_fahSetupCacheIdx>=_fahSetupCacheOrders.length){
    var pct=Math.round(100*_fahSetupCacheIdx/_fahSetupCacheOrders.length);
    document.getElementById("fahSetupCacheBar").style.width=pct+"%";
    document.getElementById("fahSetupCacheStatus").textContent="Done. Updated: "+_fahSetupCacheUpdated+" / Errors: "+_fahSetupCacheErrors;
    return;
  }
  var slice=_fahSetupCacheOrders.slice(_fahSetupCacheIdx,_fahSetupCacheIdx+_fahSetupCacheBatch);
  var ids=slice.map(function(o){return o.id;}).join(",");
  var pct=Math.round(100*_fahSetupCacheIdx/_fahSetupCacheOrders.length);
  document.getElementById("fahSetupCacheBar").style.width=pct+"%";
  document.getElementById("fahSetupCacheStatus").textContent="Processing "+(_fahSetupCacheIdx+1)+"–"+Math.min(_fahSetupCacheIdx+_fahSetupCacheBatch,_fahSetupCacheOrders.length)+" of "+_fahSetupCacheOrders.length+"…";
  var fd=new FormData();fd.append("token",_fahSetupToken);fd.append("action","setup_cache_refresh_batch");fd.append("order_ids",ids);
  fetch(_fahSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    var ul=document.getElementById("fahSetupCacheLog");
    if(d.ok&&d.result){
      _fahSetupCacheUpdated+=d.result.updated||0;
      _fahSetupCacheErrors+=d.result.errors||0;
      (d.result.items||[]).forEach(function(item){
        var li=document.createElement("li");
        li.textContent=(item.ok?"OK":"ERR")+" #"+(item.number||item.id);
        ul.appendChild(li);
      });
    }else{_fahSetupCacheErrors+=slice.length;}
    _fahSetupCacheIdx+=_fahSetupCacheBatch;
    fahSetupRefreshCacheBatch();
  }).catch(function(){_fahSetupCacheErrors+=slice.length;_fahSetupCacheIdx+=_fahSetupCacheBatch;fahSetupRefreshCacheBatch();});
}
function fahSetupOpenJsonModal(){
  document.getElementById("fahSetupJsonPre").textContent="";
  document.getElementById("fahSetupJsonModal").style.display="flex";
  var fd=new FormData();fd.append("token",_fahSetupToken);fd.append("action","setup_cached_json_list");
  fetch(_fahSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    var sel=document.getElementById("fahSetupJsonSelect");sel.innerHTML="";
    if(!d.ok||!d.orders||!d.orders.length){
      sel.innerHTML="<option value=\"\">No cached JSON found &mdash; run Refresh invoice cache first</option>";return;
    }
    d.orders.forEach(function(o){
      var opt=document.createElement("option");
      opt.value=o.id;opt.textContent="#"+(o.number||o.id)+(o.invoice?" — "+o.invoice:"");
      sel.appendChild(opt);
    });
    fahSetupShowCachedJson(d.orders[0].id);
  }).catch(function(e){document.getElementById("fahSetupJsonPre").textContent="Request failed: "+e;});
}
function fahSetupShowCachedJson(orderId){
  if(!orderId)return;
  document.getElementById("fahSetupJsonPre").textContent="Loading…";
  var fd=new FormData();fd.append("token",_fahSetupToken);fd.append("action","setup_cached_json_item");fd.append("woo_order_id",orderId);
  fetch(_fahSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    if(!d.ok){document.getElementById("fahSetupJsonPre").textContent="Error: "+d.error;return;}
    try{document.getElementById("fahSetupJsonPre").textContent=JSON.stringify(JSON.parse(d.json),null,2);}
    catch(e){document.getElementById("fahSetupJsonPre").textContent=d.json;}
  }).catch(function(e){document.getElementById("fahSetupJsonPre").textContent="Request failed: "+e;});
}
var _fahLogAllRows = [];
function fahSetupOpenLogModal(){
  _fahLogAllRows=[];
  document.getElementById("fahLogSearch").value="";
  document.getElementById("fahLogBody").innerHTML='<tr><td colspan="12" style="text-align:center;padding:20px;color:#888;">Loading…</td></tr>';
  document.getElementById("fahLogCount").textContent="";
  document.getElementById("fahLogModal").style.display="flex";
  var fd=new FormData();fd.append("token",_fahSetupToken);fd.append("action","setup_log_list");
  fetch(_fahSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    if(!d.ok){document.getElementById("fahLogBody").innerHTML='<tr><td colspan="12">Error: '+fahEsc(d.error)+'</td></tr>';return;}
    _fahLogAllRows=d.rows||[];
    fahSetupFilterLog();
  }).catch(function(e){document.getElementById("fahLogBody").innerHTML='<tr><td colspan="12">Request failed: '+fahEsc(e)+'</td></tr>';});
}
function fahSetupFilterLog(){
  var q=(document.getElementById("fahLogSearch").value||"").toLowerCase().trim();
  var rows=q?_fahLogAllRows.filter(function(r){
    var wooR=parseFloat(r.woo_payout||0);
    var n=parseFloat(r.net||0)||(parseFloat(r.gross||0)-parseFloat(r.fee||0));
    var label=r.status==='synced'?(wooR>0?(Math.abs(n-wooR)<0.005?'matched':'unmatched'):'calculated'):r.status;
    return (r.connector+r.number+r.invoice+r.payment+r.fee_source+r.status+label+r.message).toLowerCase().indexOf(q)>=0;
  }):_fahLogAllRows;
  document.getElementById("fahLogCount").textContent=rows.length+" of "+_fahLogAllRows.length+" rows";
  var html="";
  rows.forEach(function(r,i){
    var cls=i%2===0?"impair":"pair";
    var pdf="";
    if(r.pdf_ecm)pdf='<span title="Saved locally">&#128196;</span>';
    else if(r.pdf_url)pdf='<a href="'+r.pdf_url+'" target="_blank" title="Stored URL">&#8599;</a>';
    var net=parseFloat(r.net||0);
    if(net===0)net=parseFloat(r.gross||0)-parseFloat(r.fee||0);
    var wooRaw=parseFloat(r.woo_payout||0);
    var calcPayout=parseFloat(r.gross||0)-parseFloat(r.fee||0);
    var matched=wooRaw>0&&Math.abs(net-wooRaw)<0.005;
    var unmatched=wooRaw>0&&!matched;
    var netTip=unmatched?(' title="WC payout: '+wooRaw.toFixed(2)+'  Calculated: '+calcPayout.toFixed(2)+'"'):'';
    var statusBadge='';
    if(r.status==='synced'){
      if(wooRaw>0&&matched) statusBadge='<span style="background:#28a745;color:#fff;padding:1px 7px;border-radius:3px;font-size:.82em;white-space:nowrap;">&#10003; Matched</span>';
      else if(unmatched)    statusBadge='<span style="background:#e67e22;color:#fff;padding:1px 7px;border-radius:3px;font-size:.82em;white-space:nowrap;">&#9888; Unmatched</span>';
      else                   statusBadge='<span style="background:#0082c3;color:#fff;padding:1px 7px;border-radius:3px;font-size:.82em;white-space:nowrap;">&#126; Calculated</span>';
    }else if(r.status==='error'){
      statusBadge='<span style="background:#b00020;color:#fff;padding:1px 7px;border-radius:3px;font-size:.82em;white-space:nowrap;">&#10007; Error</span>';
    }else if(r.status==='skipped'){
      statusBadge='<span style="background:#888;color:#fff;padding:1px 7px;border-radius:3px;font-size:.82em;white-space:nowrap;">&#8211; Skipped</span>';
    }else if(r.status==='dryrun'){
      statusBadge='<span style="background:#6c757d;color:#fff;padding:1px 7px;border-radius:3px;font-size:.82em;white-space:nowrap;">&#9711; Dry Run</span>';
    }else{
      statusBadge='<span style="background:#ccc;color:#333;padding:1px 7px;border-radius:3px;font-size:.82em;white-space:nowrap;">'+r.status+'</span>';
    }
    var msg=r.message||'';
    html+='<tr class="'+cls+'">';
    html+='<td style="white-space:nowrap;">'+r.date.substring(0,16)+'</td>';
    html+='<td>'+r.connector+'</td>';
    html+='<td>#'+r.number+'</td>';
    html+='<td>'+r.invoice+'</td>';
    html+='<td>'+r.payment+'</td>';
    html+='<td style="text-align:right;">'+parseFloat(r.gross||0).toFixed(2)+'</td>';
    html+='<td style="text-align:right;">'+parseFloat(r.fee||0).toFixed(2)+'</td>';
    html+='<td>'+fahEsc(r.fee_source||'')+'</td>';
    html+='<td style="text-align:right;font-weight:bold;"'+netTip+'>'+net.toFixed(2)+' '+r.currency+'</td>';
    html+='<td>'+statusBadge+'</td>';
    html+='<td>'+pdf+'</td>';
    html+='<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#c05000;" title="'+msg.replace(/"/g,'&quot;')+'">'+msg+'</td>';
    html+='</tr>';
  });
  if(!rows.length)html='<tr><td colspan="12" style="text-align:center;padding:20px;color:#888;">No rows match.</td></tr>';
  document.getElementById("fahLogBody").innerHTML=html;
}
</script>
<?php
// ─────────────────────────────────────────────────────────────────────────────

$diagData = $sync->getJsonConst('FAH_META_DIAG_JSON', array());
$diagJson = !empty($diagData) ? json_encode($diagData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
?>
<?php if ($fahSelectedConnector === 'woocommerce') { ?>
<br><table class="noborder centpercent">
<tr class="liste_titre"><td>Diagnostics</td></tr>
<tr><td>
<form method="POST" action="<?php echo dol_escape_htmltag($fahWooSetupAction); ?>" style="display:inline-block;margin-right:8px;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="diagnose_meta">
<input class="button" type="submit" value="Inspect WooCommerce order meta (last 10 orders)">
</form>
<?php
if (!empty($diagData)) {
?>
<button class="button" type="button" onclick="document.getElementById('fahDiagModal').style.display='flex';">View last results</button>
<?php
}
?>
<br><span class="opacitymedium">Fetches 10 recent orders from WooCommerce and shows every meta_data key the API returns. Use this to find where Germanized or other plugins store the invoice number.</span>
</td></tr></table>
<?php

if (!empty($diagData)) {
    $diagJsonEscaped = htmlspecialchars($diagJson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div id="fahDiagModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:88%;max-width:1100px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.25);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
      <strong>WooCommerce order meta diagnostic</strong>
      <button type="button" onclick="document.getElementById('fahDiagModal').style.display='none';" style="border:none;background:none;font-size:22px;line-height:1;cursor:pointer;color:#666;">&times;</button>
    </div>
    <div style="padding:16px 20px;overflow-y:auto;flex:1;">

      <pre id="fahDiagPre" style="font-size:0.82em;margin:0;white-space:pre-wrap;word-break:break-all;"><?php echo $diagJsonEscaped; ?></pre>

    </div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;display:flex;gap:10px;">
      <button class="button" type="button" onclick="fahDownloadDiag()">Download as JSON</button>
      <button class="button" type="button" onclick="document.getElementById('fahDiagModal').style.display='none';">Close</button>
    </div>
  </div>
</div>

<script>
var _fahDiagJson = <?php echo $diagJson; ?>;
function fahDownloadDiag(){
  var blob=new Blob([JSON.stringify(_fahDiagJson,null,2)],{type:"application/json"});
  var a=document.createElement("a");a.href=URL.createObjectURL(blob);
  a.download="fah-meta-diagnostic.json";document.body.appendChild(a);a.click();document.body.removeChild(a);
}
<?php
    if ($action === 'diagnose_meta') {
?>
document.getElementById("fahDiagModal").style.display="flex";
<?php
    }
?>
</script>
<?php
}

?>
<?php } ?>
<?php if ($fahSelectedConnector === 'woocommerce' && !empty($detectedIntegrations)): ?>
<br>
<div id="fahIntegrationPanel">
  <div class="liste_titre" style="display:flex;gap:4px;padding:8px 12px;">
    <?php foreach ($detectedIntegrations as $_intTab): ?>
    <button type="button" class="button" onclick="fahShowIntTab('<?php echo dol_escape_js($_intTab->getId()); ?>')"
      id="fah-tab-btn-<?php echo dol_escape_htmltag($_intTab->getId()); ?>"><?php echo dol_escape_htmltag($_intTab->getLabel()); ?></button>
    <?php endforeach; ?>
  </div>
  <?php foreach ($detectedIntegrations as $_intTab): ?>
  <div id="fah-tab-<?php echo dol_escape_htmltag($_intTab->getId()); ?>" class="fah-integration-tab">
    <?php $_intTab->renderSetupHtml($conf, $db, $langs, newToken(), $sync); ?>
  </div>
  <?php endforeach; ?>
</div>
<script>
function fahShowIntTab(id) {
  document.querySelectorAll('.fah-integration-tab').forEach(function(el){el.style.display='none';});
  document.querySelectorAll('[id^="fah-tab-btn-"]').forEach(function(b){b.classList.remove('buttonDelete');});
  document.getElementById('fah-tab-'+id).style.display='block';
  document.getElementById('fah-tab-btn-'+id).classList.add('buttonDelete');
}
fahShowIntTab('<?php echo dol_escape_js(key($detectedIntegrations)); ?>');
</script>
<?php endif; ?>
<?php $fahMaintenance = $sync->getMaintenanceSummary(); ?>
<br>
<table class="noborder centpercent">
  <tr class="liste_titre"><td colspan="2">Bank/Cash and test-data maintenance</td></tr>
  <tr><td class="titlefield">Module-owned resources</td><td><?php echo (int) $fahMaintenance['accounts']; ?> auto-created bank account(s), <?php echo (int) $fahMaintenance['entries']; ?> imported bank entry/entries, <?php echo (int) $fahMaintenance['documents']; ?> stored document(s), <?php echo (int) $fahMaintenance['logs']; ?> sync log row(s)</td></tr>
  <tr><td>Global bank entry reference</td><td>Highest existing: <strong><?php echo (int) $fahMaintenance['sequence']['highest']; ?></strong> &nbsp; Current next: <strong><?php echo (int) $fahMaintenance['sequence']['next']; ?></strong> &nbsp; Lowest safe next: <strong><?php echo (int) $fahMaintenance['sequence']['minimum']; ?></strong></td></tr>
  <tr><td>Set next bank entry reference</td><td>
    <form method="POST" action="<?php echo dol_escape_htmltag($fahSetupAction); ?>" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="set_bank_sequence">
      <input class="flat" type="number" min="<?php echo (int) $fahMaintenance['sequence']['minimum']; ?>" step="1" name="next_bank_reference" value="<?php echo (int) $fahMaintenance['sequence']['next']; ?>" required>
      <button class="button button-save" type="submit">Set next reference</button>
    </form>
    <div class="warning" style="margin-top:8px;">This is the global <code>llx_bank</code> sequence used by every Dolibarr Bank/Cash entry, not only this module. It cannot be set below the highest remaining entry plus one.</div>
  </td></tr>
  <tr><td>Legacy menu cleanup</td><td><form method="POST" action="<?php echo dol_escape_htmltag($fahSetupAction); ?>" style="display:inline;"><input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="cleanup_legacy_menus"><button class="button" type="submit">Remove stale Dolli Commerce Hub menus</button></form></td></tr>
</table>
<!-- Danger zone -->
<br>
<table class="noborder centpercent">
    <tr class="liste_titre"><td>Danger zone</td></tr>
    <tr><td>
        <button class="button button-delete" type="button"
                style="background:#b00020;color:#fff;border-color:#b00020;"
                onclick="fahOpenDesyncModal()">
            ⚠️ Desync: delete synced bank entries, PDFs and reset log
        </button>
        <br><span class="opacitymedium">
            Deletes bank lines stored in the Finance Automation Hub log, downloaded WooCommerce PDF files, and clears the financial sync log.
            It does not touch channel orders, manually-created Dolibarr entries, or stock movements.
        </span>
    </td></tr>
</table>

<!-- Desync confirmation + progress modal -->
<div id="fahDesyncModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.55);z-index:10000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:6px;width:600px;max-width:94%;box-shadow:0 8px 32px rgba(0,0,0,.28);">
        <div style="padding:16px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
            <strong style="color:#b00020;">⚠️ Desync — confirm deletion</strong>
            <span onclick="fahDesyncEsc()" style="cursor:pointer;font-size:22px;color:#666;">&times;</span>
        </div>
        <div id="fahDesyncConfirmStep" style="padding:18px 20px;">
            <p style="margin:0 0 12px;">This will permanently delete:</p>
            <ul style="margin:0 0 16px;padding-left:20px;line-height:1.8;">
                <li>All Dolibarr bank lines created by Finance Automation Hub</li>
                <li>All invoice PDF files downloaded to Dolibarr ECM</li>
                <li>All Finance Automation Hub financial sync log entries</li>
                <li>All WooCommerce order cache entries</li>
            </ul>
            <label style="display:block;margin:0 0 14px;"><input id="fahDeleteAccounts" type="checkbox" value="1"> Also delete empty virtual bank accounts created by this module and clear their mappings</label>
            <p style="margin:0 0 16px;color:#555;">Channel orders, stock movements, bundle recipes, and manually-created Dolibarr entries are <strong>not</strong> affected.</p>
            <p style="margin:0 0 16px;font-weight:bold;color:#b00020;">This action cannot be undone.</p>
            <div style="display:flex;gap:10px;">
                <button id="fahDesyncConfirmBtn" class="button button-delete"
                        style="background:#b00020;color:#fff;border-color:#b00020;"
                        onclick="fahRunDesync()">Yes, delete everything</button>
                <button class="button" type="button" onclick="fahDesyncEsc()">Cancel</button>
            </div>
        </div>
        <div id="fahDesyncProgressStep" style="display:none;padding:18px 20px;text-align:center;">
            <div style="font-size:2em;margin-bottom:12px;">⏳</div>
            <div style="color:#555;">Running desync — please wait…</div>
        </div>
        <div id="fahDesyncResultStep" style="display:none;padding:18px 20px;">
            <div id="fahDesyncResultIcon" style="font-size:2em;text-align:center;margin-bottom:10px;"></div>
            <div id="fahDesyncResultMsg" style="margin-bottom:14px;text-align:center;"></div>
            <table id="fahDesyncResultTable" class="noborder centpercent" style="font-size:.9em;display:none;">
                <tr><td>Bank lines deleted</td><td id="fahDrBank" style="text-align:right;font-weight:bold;"></td></tr>
                <tr><td>Bank accounts deleted</td><td id="fahDrAccounts" style="text-align:right;font-weight:bold;"></td></tr>
                <tr><td>PDF files deleted</td><td id="fahDrPdfs" style="text-align:right;font-weight:bold;"></td></tr>
                <tr><td>Log rows deleted</td><td id="fahDrLogs" style="text-align:right;font-weight:bold;"></td></tr>
            </table>
            <div style="margin-top:16px;text-align:center;">
                <button class="button" type="button" onclick="fahDesyncEsc()">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function fahDesyncEsc() {
    document.getElementById('fahDesyncModal').style.display = 'none';
    document.getElementById('fahDesyncConfirmStep').style.display  = 'block';
    document.getElementById('fahDesyncProgressStep').style.display = 'none';
    document.getElementById('fahDesyncResultStep').style.display   = 'none';
    document.getElementById('fahDesyncConfirmBtn').disabled = false;
}

function fahOpenDesyncModal() {
    fahDesyncEsc();
    document.getElementById('fahDesyncModal').style.display = 'flex';
}

function fahRunDesync() {
    document.getElementById('fahDesyncConfirmBtn').disabled = true;
    document.getElementById('fahDesyncConfirmStep').style.display  = 'none';
    document.getElementById('fahDesyncProgressStep').style.display = 'block';

    var fd = new FormData();
    fd.append('token', <?php echo json_encode(newToken()); ?>);
    fd.append('action', 'desync_ajax');
    fd.append('delete_accounts', document.getElementById('fahDeleteAccounts').checked ? '1' : '0');

    fetch(<?php echo json_encode($_SERVER['PHP_SELF']); ?>, {method: 'POST', body: fd})
        .then(function(r) { return r.json(); })
        .then(function(d) {
            document.getElementById('fahDesyncProgressStep').style.display = 'none';
            document.getElementById('fahDesyncResultStep').style.display   = 'block';
            if (d.ok) {
                document.getElementById('fahDesyncResultIcon').textContent = '✅';
                document.getElementById('fahDesyncResultMsg').textContent  = d.message || 'Desync complete.';
                var s = d.stats || {};
                document.getElementById('fahDrBank').textContent = s.bank  || 0;
                document.getElementById('fahDrAccounts').textContent = s.accounts || 0;
                document.getElementById('fahDrPdfs').textContent = s.pdfs  || 0;
                document.getElementById('fahDrLogs').textContent = s.logs  || 0;
                document.getElementById('fahDesyncResultTable').style.display = 'table';
            } else {
                document.getElementById('fahDesyncResultIcon').textContent = '❌';
                document.getElementById('fahDesyncResultMsg').style.color  = '#b00020';
                document.getElementById('fahDesyncResultMsg').textContent  = d.error || 'Desync failed.';
            }
        })
        .catch(function(e) {
            document.getElementById('fahDesyncProgressStep').style.display = 'none';
            document.getElementById('fahDesyncResultStep').style.display   = 'block';
            document.getElementById('fahDesyncResultIcon').textContent = '❌';
            document.getElementById('fahDesyncResultMsg').style.color  = '#b00020';
            document.getElementById('fahDesyncResultMsg').textContent  = 'Request failed: ' + e;
        });
}
</script>

<?php
llxFooter();
$db->close();
