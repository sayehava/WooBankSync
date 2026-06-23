<?php
$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once __DIR__ . '/../class/woobanksync.class.php';
require_once __DIR__ . '/../class/wbsgermanizedclient.class.php';

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

function wbs_meta_select($name, $keys, $selected, $placeholder, $style = 'min-width:180px;')
{
    $html = '<select class="flat" style="' . dol_escape_htmltag($style) . '" name="' . dol_escape_htmltag($name) . '">';
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
    $keys = array('WBS_WOO_URL', 'WBS_WOO_CONSUMER_KEY', 'WBS_WOO_CONSUMER_SECRET', 'WBS_SYNC_FROM_DATE', 'WBS_ORDER_STATUSES');
    foreach ($keys as $key) wbs_set_const_safe($db, $key, GETPOST($key, 'restricthtml'), 'chaine', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_DRY_RUN', GETPOST('WBS_DRY_RUN', 'int') ? '1' : '0', 'yesno', 0, '', $conf->entity);
    setEventMessages('API settings saved.', null, 'mesgs');
}

if ($action === 'save_batch_sizes') {
    $defaults = array(
        'WBS_CACHE_BATCH_SIZE' => 1,
        'WBS_SYNC_BATCH_SIZE' => 10,
        'WBS_DIFF_BATCH_SIZE' => 10,
    );
    foreach ($defaults as $key => $default) {
        $value = max(1, min(100, (int) GETPOST($key, 'int')));
        if ($value <= 0) $value = $default;
        wbs_set_const_safe($db, $key, (string) $value, 'chaine', 0, '', $conf->entity);
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
    wbs_set_const_safe($db, 'WBS_DOCUMENT_FOLDER_ID', GETPOST('WBS_DOCUMENT_FOLDER_ID', 'int'), 'chaine', 0, '', $conf->entity);
    setEventMessages('Document settings saved.', null, 'mesgs');
}

if ($action === 'save_invoice') {
    $gzdEnabled = GETPOST('WBS_GERMANIZED_PRO_ENABLED', 'int') ? '1' : '0';
    wbs_set_const_safe($db, 'WBS_GERMANIZED_PRO_ENABLED', $gzdEnabled, 'yesno', 0, '', $conf->entity);
    $labelEnabled = ($gzdEnabled === '1' && GETPOST('WBS_DOCUMENT_SYNC_ENABLED', 'int')) ? '1' : '0';
    wbs_set_const_safe($db, 'WBS_DOCUMENT_SYNC_ENABLED', $labelEnabled, 'yesno', 0, '', $conf->entity);
    $extraEnabled = ($gzdEnabled === '1' && GETPOST('WBS_BANK_EXTRAFIELD_ENABLED', 'int')) ? '1' : '0';
    wbs_set_const_safe($db, 'WBS_BANK_EXTRAFIELD_ENABLED', $extraEnabled, 'yesno', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_BANK_EXTRAFIELD_CODE', GETPOST('WBS_BANK_EXTRAFIELD_CODE', 'aZ09'), 'chaine', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_DOCUMENT_FOLDER_ID', GETPOST('WBS_DOCUMENT_FOLDER_ID', 'int'), 'chaine', 0, '', $conf->entity);
    $pdfDownload = ($gzdEnabled === '1' && GETPOST('WBS_PDF_DOWNLOAD_ENABLED', 'int')) ? '1' : '0';
    wbs_set_const_safe($db, 'WBS_PDF_DOWNLOAD_ENABLED', $pdfDownload, 'yesno', 0, '', $conf->entity);
    setEventMessages('Invoice reference settings saved.', null, 'mesgs');
}

if ($action === 'save_amount_fields') {
    wbs_set_const_safe($db, 'WBS_EXTRAFIELD_GROSS_CODE', GETPOST('WBS_EXTRAFIELD_GROSS_CODE', 'aZ09'), 'chaine', 0, '', $conf->entity);
    wbs_set_const_safe($db, 'WBS_EXTRAFIELD_FEE_CODE', GETPOST('WBS_EXTRAFIELD_FEE_CODE', 'aZ09'), 'chaine', 0, '', $conf->entity);
    setEventMessages('Amount custom field mapping saved.', null, 'mesgs');
}

if ($action === 'create_amount_extrafields') {
    list($ok, $msg) = $sync->createAndMapAmountExtraFields();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
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
    $wooUrl = isset($conf->global->WBS_WOO_URL) ? $conf->global->WBS_WOO_URL : '';
    $wooKey = isset($conf->global->WBS_WOO_CONSUMER_KEY) ? $conf->global->WBS_WOO_CONSUMER_KEY : '';
    $wooSecret = isset($conf->global->WBS_WOO_CONSUMER_SECRET) ? $conf->global->WBS_WOO_CONSUMER_SECRET : '';

    $client = $sync->client();
    $orders = $client->getRecentOrders(10);

    if ($orders === false) {
        wbs_set_const_safe($db, 'WBS_META_DIAG_JSON', json_encode(array('error' => $client->error)), 'chaine', 0, '', $conf->entity);
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
            $gzdClient = new WbsGermanizedClient($wooUrl, $wooKey, $wooSecret);
            $diagResult['germanized_probe'] = $gzdClient->probeOrder($firstOrderId);
        }

        wbs_set_const_safe($db, 'WBS_META_DIAG_JSON', json_encode($diagResult), 'chaine', 0, '', $conf->entity);
        setEventMessages('Diagnostic complete for ' . count($orders) . ' orders. Germanized probe on order #' . $firstOrderId . '. Results shown below.', null, 'mesgs');
    }
}

if ($action === 'detect_storeabill') {
    list($ok, $msg) = $sync->detectStoreaBillFolder();
    setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
}

if ($action === 'save_storeabill') {
    $folder = trim(GETPOST('WBS_STOREABILL_FOLDER', 'restricthtml'));
    if ($folder !== '' && !preg_match('/^storeabill-[a-z0-9]+$/i', $folder)) {
        setEventMessages('Invalid folder name. Expected format: storeabill-xxxxxxxx (only lowercase letters and digits after the dash).', null, 'errors');
    } else {
        wbs_set_const_safe($db, 'WBS_STOREABILL_FOLDER', $folder, 'chaine', 0, '', $conf->entity);
        setEventMessages($folder !== '' ? 'StoreaBill folder saved: ' . $folder : 'StoreaBill folder cleared.', null, 'mesgs');
    }
}

// AJAX: test downloading a PDF — order ID uses SAB endpoint, URL uses direct fetch (no file saved)
if ($action === 'setup_test_pdf') {
    header('Content-Type: application/json');
    if (!$user->admin) { echo json_encode(array('ok' => false, 'error' => 'Access denied', 'log' => array())); exit; }
    $testUrl = trim(GETPOST('pdf_url', 'restricthtml'));
    $orderId  = trim(GETPOST('woo_order_id', 'alphanohtml'));

    if ($orderId !== '') {
        // Order ID mode: use StoreaBill endpoint — no cache lookup needed
        $content = $sync->testFetchStoreaBillPdf((int) $orderId);
        echo json_encode(array(
            'ok'             => ($content !== false),
            'mode'           => 'sab',
            'order_id'       => $orderId,
            'invoice_number' => $sync->lastSabInvoiceNumber,
            'bytes'          => ($content !== false ? strlen($content) : 0),
            'is_pdf'         => ($content !== false && substr($content, 0, 4) === '%PDF'),
            'log'            => $sync->pdfLog,
        ));
        exit;
    }

    if ($testUrl === '') {
        echo json_encode(array('ok' => false, 'error' => 'Enter a WooCommerce order ID or a direct PDF URL.', 'log' => array())); exit;
    }

    // URL mode: direct fetch with fallback strategies
    $content = $sync->testFetchPdfUrl($testUrl);
    echo json_encode(array(
        'ok'     => ($content !== false),
        'mode'   => 'url',
        'url'    => $testUrl,
        'bytes'  => ($content !== false ? strlen($content) : 0),
        'is_pdf' => ($content !== false && substr($content, 0, 4) === '%PDF'),
        'log'    => $sync->pdfLog,
    ));
    exit;
}

// AJAX: return sync log rows for the log viewer modal
if ($action === 'setup_log_list') {
    header('Content-Type: application/json');
    if (!$user->admin) { echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit; }
    $sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'woobanksync_log WHERE entity=' . (int) $conf->entity . ' ORDER BY rowid DESC LIMIT 1000';
    $resql = $db->query($sql);
    $rows = array();
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $rows[] = array(
                'id'      => (string) ($obj->woo_order_id ?? ''),
                'number'  => (string) ($obj->woo_order_number ?? ''),
                'invoice' => (string) ($obj->woo_invoice_number ?? ''),
                'payment' => (string) ($obj->payment_method ?? ''),
                'gross'   => (string) ($obj->gross_amount ?? ''),
                'fee'     => (string) ($obj->fee_amount ?? ''),
                'net'     => (string) ($obj->payout_amount ?? ''),
                'currency'=> (string) ($obj->currency ?? ''),
                'status'  => (string) ($obj->sync_status ?? ''),
                'message' => (string) ($obj->sync_message ?? ''),
                'date'    => (string) ($obj->date_sync ?? ''),
                'pdf_url' => (string) ($obj->woo_invoice_pdf_url ?? ''),
                'pdf_ecm' => (string) ($obj->pdf_ecm_filepath ?? ''),
            );
        }
    }
    echo json_encode(array('ok' => true, 'rows' => $rows));
    exit;
}

// AJAX: PDF download status per synced order
if ($action === 'setup_pdf_status') {
    header('Content-Type: application/json');
    if (!$user->admin) { echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit; }
    // SELECT * so query succeeds even if dynamic columns (woo_invoice_pdf_url, pdf_ecm_filepath) don't exist yet
    $sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'woobanksync_log'
        . ' WHERE entity=' . (int) $conf->entity . " AND sync_status='synced'"
        . ' ORDER BY rowid DESC LIMIT 500';
    $rows = array();
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $rows[] = array(
                'id'      => (string) ($obj->woo_order_id ?? ''),
                'number'  => (string) ($obj->woo_order_number ?? ''),
                'invoice' => (string) ($obj->woo_invoice_number ?? ''),
                'payment' => (string) ($obj->payment_method ?? ''),
                'date'    => (string) ($obj->date_sync ?? ''),
                'pdf_url' => (string) ($obj->woo_invoice_pdf_url ?? ''),
                'pdf_ecm' => (string) ($obj->pdf_ecm_filepath ?? ''),
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
        'germanized_enabled' => !empty($conf->global->WBS_GERMANIZED_PRO_ENABLED),
        'batch_size' => max(1, min(100, (int) ($conf->global->WBS_CACHE_BATCH_SIZE ?? 10))),
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
    list($ok, $msg, $stats) = $sync->desyncAllSyncedEntries();
    echo json_encode(array('ok' => $ok, 'message' => $msg, 'stats' => $stats ?? array()));
    exit;
}

llxHeader('', $langs->trans('WooBankSyncSetup'));
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>';
?>
<?php echo load_fiche_titre($langs->trans('WooBankSyncSetup'), $linkback, 'title_setup'); ?>
<?php

?>
<div class="center" style="margin-bottom:12px;">
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="display:inline;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="dbcheck">
<input class="button" type="submit" value="Run/update database checks without disabling module">
</form>
 &nbsp; <button class="button" type="button" onclick="wbsSetupOpenLogModal()">View sync log</button>
 &nbsp; <button class="button" type="button" onclick="wbsSetupOpenPdfModal()">&#128196; Download invoice PDFs</button>
 <label title="Force re-download all even if already saved" style="cursor:pointer;font-size:0.9em;vertical-align:middle;"><input type="checkbox" id="wbsSetupForceDownload" style="vertical-align:middle;margin-right:3px;">Force re-download all</label>
 &nbsp; <button class="button" type="button" onclick="wbsSetupOpenPdfStatusModal()" title="See which orders have PDFs saved, URL known, or missing">&#128202; PDF download status</button>
</div>
<?php

// ── PDF download modal (calls index.php AJAX — token valid for same session) ─
?>
<div id="wbsSetupPdfModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.55);z-index:9998;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:82%;max-width:860px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.28);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
      <strong>&#128196; Download past invoice PDFs</strong>
      <span onclick="wbsSetupEsc('wbsSetupPdfModal')" style="cursor:pointer;font-size:22px;color:#666;">&times;</span>
    </div>
    <div style="padding:14px 20px 10px;">
      <div style="background:#e8e8e8;border-radius:4px;height:10px;overflow:hidden;"><div id="wbsSetupPdfBar" style="width:0%;height:10px;background:#28a745;border-radius:4px;transition:width .4s;"></div></div>
      <div id="wbsSetupPdfStatus" style="margin-top:6px;font-size:.88em;color:#555;">Preparing&hellip;</div>
    </div>
    <div id="wbsSetupPdfList" style="flex:1;overflow-y:auto;padding:4px 20px 10px;font-size:.9em;"></div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;display:flex;gap:10px;align-items:center;">
      <button id="wbsSetupPdfDoneBtn" class="button" style="display:none;" onclick="wbsSetupEsc('wbsSetupPdfModal')">Close</button>
      <span id="wbsSetupPdfSummary" style="font-size:.88em;color:#666;"></span>
    </div>
  </div>
</div>
<?php

// ── Log viewer modal ──────────────────────────────────────────────────────────
?>
<div id="wbsLogModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:95%;max-width:1100px;max-height:90vh;display:flex;flex-direction:column;">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;align-items:center;gap:12px;">
      <b style="flex:1;font-size:1.1em;">Sync log</b>
      <input type="text" id="wbsLogSearch" placeholder="Search order #, invoice, payment, status…" class="flat" style="width:300px;" oninput="wbsSetupFilterLog()">
      <span id="wbsLogCount" style="font-size:0.85em;color:#888;white-space:nowrap;"></span>
      <button class="button" type="button" onclick="wbsSetupEsc('wbsLogModal')">Close</button>
    </div>
    <div style="overflow:auto;flex:1;">
      <table class="liste centpercent" id="wbsLogTable" style="font-size:0.82em;">
        <thead><tr class="liste_titre">
          <th>Date sync</th><th>Order #</th><th>Invoice #</th><th>Payment</th>
          <th class="right">Gross</th><th class="right">Fee</th><th class="right">Net</th><th>Status</th><th>PDF</th><th>Message</th>
        </tr></thead>
        <tbody id="wbsLogBody"><tr><td colspan="10" style="text-align:center;padding:20px;color:#888;">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>
</div>
<?php

// ── PDF download status modal ─────────────────────────────────────────────────
?>
<div id="wbsPdfStatusModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:95%;max-width:1100px;max-height:90vh;display:flex;flex-direction:column;">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <b style="flex:1;font-size:1.1em;">&#128202; PDF download status</b>
      <input type="text" id="wbsPdfStatusSearch" placeholder="Search order #, invoice, payment…" class="flat" style="width:260px;" oninput="wbsSetupFilterPdfStatus()">
      <label style="font-size:0.85em;cursor:pointer;white-space:nowrap;"><input type="checkbox" id="wbsPdfStatusMissingOnly" onchange="wbsSetupFilterPdfStatus()"> Missing only</label>
      <span id="wbsPdfStatusCount" style="font-size:0.85em;color:#888;white-space:nowrap;"></span>
      <button class="button" type="button" onclick="wbsSetupEsc('wbsPdfStatusModal')">Close</button>
    </div>
    <div style="overflow:auto;flex:1;">
      <table class="liste centpercent" style="font-size:0.82em;">
        <thead><tr class="liste_titre">
          <th>Date sync</th><th>Order #</th><th>Invoice #</th><th>Payment</th><th style="text-align:center;">PDF status</th><th>ECM path / URL</th>
        </tr></thead>
        <tbody id="wbsPdfStatusBody"><tr><td colspan="6" style="text-align:center;padding:20px;color:#888;">Loading…</td></tr></tbody>
      </table>
    </div>
    <div style="padding:10px 20px;border-top:1px solid #ddd;font-size:0.82em;color:#666;">
      ✅ = PDF saved locally in Dolibarr ECM &nbsp;&nbsp; &#128196; = URL known, not yet downloaded &nbsp;&nbsp; ❌ = No PDF info (run Download invoice PDFs with Force re-download)
    </div>
  </div>
</div>
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
);
foreach ($fields as $key => $label) {
    $type = ($key === 'WBS_WOO_CONSUMER_SECRET') ? 'password' : 'text';
    $defaultValue = '';
    if ($key === 'WBS_ORDER_STATUSES') $defaultValue = 'processing,completed';
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
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="save_batch_sizes">
<table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">Workflow batch sizes</td></tr>
<?php
$batchFields = array(
    'WBS_CACHE_BATCH_SIZE' => array('Full cache refresh items per batch', 1),
    'WBS_SYNC_BATCH_SIZE' => array('Sync items per batch', 10),
    'WBS_DIFF_BATCH_SIZE' => array('Difference check items per batch', 10),
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
<div style="overflow-x:auto;">
<table class="noborder" style="width:100%;min-width:600px;">
<tr class="liste_titre"><td colspan="6">WooCommerce payment methods mapping (active or used)</td></tr>
<tr class="liste_titre">
  <th style="white-space:nowrap;">Gateway ID</th>
  <th>Title</th>
  <th style="white-space:nowrap;">Source</th>
  <th style="white-space:nowrap;text-align:center;">Orders</th>
  <th>Dolibarr bank account</th>
  <th style="white-space:nowrap;">Provider fee meta key<br><span style="font-weight:normal;font-size:0.85em;opacity:.7;">e.g. _ppcp_paypal_fees — leave blank for auto-detect</span></th>
</tr>
<?php
if (empty($gateways)) {
?>
<tr><td colspan="6" class="opacitymedium">No payment methods detected yet. Save API settings, then click Refresh.</td></tr>
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
<td><?php echo wbs_bank_select('WBS_MAP_BANK_' . $safe, $selected['bank_id'] ?? ''); ?></td>
<td><?php echo wbs_meta_select('WBS_MAP_FEE_' . $safe, $keys, $selected['fee_key'] ?? '', '-- auto detect --', 'min-width:160px;max-width:260px;width:100%;'); ?></td>
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

$gzdEnabled = !empty($conf->global->WBS_GERMANIZED_PRO_ENABLED);
$labelEnabled = !empty($conf->global->WBS_DOCUMENT_SYNC_ENABLED);
$extraEnabled = !empty($conf->global->WBS_BANK_EXTRAFIELD_ENABLED);
$pdfDownloadEnabled = !empty($conf->global->WBS_PDF_DOWNLOAD_ENABLED);
$mappedBankExtraField = (string) ($conf->global->WBS_BANK_EXTRAFIELD_CODE ?? '');
$mappedFolderId = (string) ($conf->global->WBS_DOCUMENT_FOLDER_ID ?? '');
$bankExtraFields = $sync->getBankExtraFields();
?>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="save_invoice">
<table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">WooCommerce invoice reference</td></tr>
<tr><td class="titlefield">Germanized Pro integration</td><td>
<label><input type="checkbox" id="wbsGzdToggle" name="WBS_GERMANIZED_PRO_ENABLED" value="1"<?php echo $gzdEnabled ? ' checked' : ''; ?> onchange="document.getElementById('wbsGzdSub').style.display=this.checked?'table-row-group':'none';">
 Enable Germanized Pro invoice extraction</label>
<br><span class="opacitymedium">Reads invoice number from <code>invoices[0].formatted_number</code> in the WooCommerce order response (StoreaBill / Germanized Pro).</span>
</td></tr></table>
<table class="noborder centpercent" id="wbsGzdSub" style="<?php echo $gzdEnabled ? '' : 'display:none;'; ?>">
<tr><td class="titlefield">Add invoice number to label</td><td>
<label><input type="checkbox" name="WBS_DOCUMENT_SYNC_ENABLED" value="1"<?php echo $labelEnabled ? ' checked' : ''; ?>>
 Append invoice number to bank entry label (title)</label>
</td></tr>
<tr><td class="titlefield">Store in a custom field</td><td>
<label><input type="checkbox" name="WBS_BANK_EXTRAFIELD_ENABLED" value="1"<?php echo $extraEnabled ? ' checked' : ''; ?>>
 Also write the invoice number into a mapped bank-entry custom field</label>
</td></tr>
<tr><td class="titlefield">Bank-entry custom field</td><td>
<select class="flat minwidth300" name="WBS_BANK_EXTRAFIELD_CODE"><option value="">-- not mapped --</option>
<?php
foreach ($bankExtraFields as $code => $fieldLabel) {
?>
<option value="<?php echo dol_escape_htmltag($code); ?>"<?php echo $code === $mappedBankExtraField ? ' selected' : ''; ?>><?php echo dol_escape_htmltag($fieldLabel . ' (' . $code . ')'); ?></option>
<?php
}
?>
</select>
<br><span class="opacitymedium">Select an existing Dolibarr bank-entry custom field, or use the button below to create and map one automatically.</span>
</td></tr>
<tr><td class="titlefield">Download PDF invoices during sync</td><td>
<label><input type="checkbox" name="WBS_PDF_DOWNLOAD_ENABLED" value="1"<?php echo $pdfDownloadEnabled ? ' checked' : ''; ?>>
 Automatically download and save invoice PDFs to the mapped Dolibarr folder during sync</label>
<br><span class="opacitymedium">Requires a mapped folder below. Each synced order will trigger one extra HTTP request to fetch the PDF. A clickable download link will appear in the sync log.</span>
</td></tr>
<tr><td class="titlefield">Invoice PDF document folder</td><td>
<?php echo wbs_ecm_folder_select('WBS_DOCUMENT_FOLDER_ID', $mappedFolderId); ?>
<br><span class="opacitymedium">ECM folder where Woo invoice PDFs will be stored. Select an existing folder, or use the button below to create and map one automatically.</span>
</td></tr>
</table>
<div class="center"><input class="button button-save" type="submit" value="Save invoice settings"></div>
</form>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="center" style="margin-top:6px;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="create_invoice_extrafield">
<input class="button" type="submit" value="Create invoice-number custom field and map it automatically"<?php echo $mappedBankExtraField !== '' ? ' disabled' : ''; ?>>
<?php
if ($mappedBankExtraField !== '') {
?>
<br><span class="opacitymedium">Already mapped. Clear the field above and save to create a new one.</span>
<?php
}
?>
</form>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="center" style="margin-top:4px;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="createdocs">
<input class="button" type="submit" value="Create Woo Invoices folder if missing and map it automatically"<?php echo $mappedFolderId !== '' ? ' disabled' : ''; ?>>
<?php
if ($mappedFolderId !== '') {
?>
<br><span class="opacitymedium">Already mapped. Clear the folder above and save to create a new one.</span>
<?php
}
?>
</form>
<?php


$mappedGrossField = (string) ($conf->global->WBS_EXTRAFIELD_GROSS_CODE ?? '');
$mappedFeeField   = (string) ($conf->global->WBS_EXTRAFIELD_FEE_CODE ?? '');
?>
<br>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="save_amount_fields">
<table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">Amount custom fields (gross and fee)</td></tr>
<tr><td colspan="2" class="opacitymedium" style="padding-bottom:8px;">
Each bank entry is created with <strong>net amount received</strong> (gross &minus; fee). The original gross and fee
can optionally be stored in Dolibarr bank-entry custom fields so they are visible in reports and exports.
</td></tr>
<tr><td class="titlefield">Gross amount field</td><td>
<select class="flat minwidth300" name="WBS_EXTRAFIELD_GROSS_CODE">
<option value="">-- not mapped --</option>
<?php foreach ($bankExtraFields as $code => $fieldLabel) { ?>
<option value="<?php echo dol_escape_htmltag($code); ?>"<?php echo $code === $mappedGrossField ? ' selected' : ''; ?>><?php echo dol_escape_htmltag($fieldLabel . ' (' . $code . ')'); ?></option>
<?php } ?>
</select>
<br><span class="opacitymedium">Custom field that will receive the original WooCommerce order gross total.</span>
</td></tr>
<tr><td class="titlefield">Fee amount field</td><td>
<select class="flat minwidth300" name="WBS_EXTRAFIELD_FEE_CODE">
<option value="">-- not mapped --</option>
<?php foreach ($bankExtraFields as $code => $fieldLabel) { ?>
<option value="<?php echo dol_escape_htmltag($code); ?>"<?php echo $code === $mappedFeeField ? ' selected' : ''; ?>><?php echo dol_escape_htmltag($fieldLabel . ' (' . $code . ')'); ?></option>
<?php } ?>
</select>
<br><span class="opacitymedium">Custom field that will receive the payment processor fee (Gebühr).</span>
</td></tr>
</table>
<div class="center"><input class="button button-save" type="submit" value="Save amount field mapping"></div>
</form>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="center" style="margin-top:6px;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="create_amount_extrafields">
<input class="button" type="submit" value="Create and map missing amount custom fields automatically">
</form>
<br>
<?php

if ($gzdEnabled) {
    $storeabillFolder = (string) ($conf->global->WBS_STOREABILL_FOLDER ?? '');
?>
<br><table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">StoreaBill PDF directory</td></tr>
<tr><td class="titlefield">Detected folder</td><td>
<?php
    if ($storeabillFolder !== '') {
?>
<code><?php echo dol_escape_htmltag($storeabillFolder); ?></code> <span style="color:green;">&#10003; detected</span>
<?php
    } else {
?>
<span class="opacitymedium">Not detected yet.</span>
<?php
    }
?>
</td></tr>
<tr><td class="titlefield">Auto-detect</td><td>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="display:inline-block;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="detect_storeabill">
<input class="button" type="submit" value="Detect StoreaBill folder">
</form>
<br><span class="opacitymedium">Reads PDF URLs stored in the local cache. If none found, probes the Germanized document endpoint for one recent order. Run Refresh full cache first if you have no PDF URLs yet.</span>
</td></tr>
<tr><td class="titlefield">Manual override</td><td>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="display:inline-block;" onsubmit="return confirm('Changing the StoreaBill folder name manually may break PDF downloads if the value does not match your WooCommerce installation. Continue?');">
<input type="hidden" name="token" value="<?php echo newToken(); ?>">
<input type="hidden" name="action" value="save_storeabill">
<input class="flat" type="text" name="WBS_STOREABILL_FOLDER" value="<?php echo dol_escape_htmltag($storeabillFolder); ?>" placeholder="storeabill-xxxxxxxx" style="width:220px;">
 <input class="button" type="submit" value="Save">
</form>
<br><span style="color:#c05000;font-size:0.88em;">&#9888; Warning: changing this manually may break PDF downloads if the folder name does not match your WooCommerce installation.</span>
</td></tr>
</table>
<?php
}

// ─── Invoice data cache (debug / development) ────────────────────────────
?>
<br><table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">Invoice data cache &amp; JSON viewer (debugging / development)</td></tr>
<tr><td class="titlefield">Refresh invoice cache</td><td>
<div style="margin-bottom:6px;">
<label><input type="radio" name="wbsCacheRange" id="wbsCacheRangeLatest" value="latest" checked onchange="document.getElementById('wbsCacheLimitWrap').style.display='inline';"> Latest&nbsp;</label>
<span id="wbsCacheLimitWrap"><input type="number" id="wbsCacheLimit" value="50" min="1" max="9999" style="width:60px;margin:0 3px;">&nbsp;orders</span>
&nbsp;&nbsp;&nbsp;<label><input type="radio" name="wbsCacheRange" value="all" onchange="document.getElementById('wbsCacheLimitWrap').style.display='none';"> All synced orders</label>
</div>
<button class="button" type="button" onclick="wbsSetupOpenCacheModal()">Refresh invoice cache</button>
<?php
?>
<br><span class="opacitymedium">Fetches invoice number and PDF URL from WooCommerce for each synced order and updates the local cache. 
Also stores the full WooCommerce JSON for use with the viewer below. Use a limited range for speed.</span>
<?php
?>
</td></tr>
<tr><td class="titlefield">View cached order JSON</td><td>
<button class="button" type="button" onclick="wbsSetupOpenJsonModal()">View cached Woo JSON</button>
<br><span class="opacitymedium">Shows the raw WooCommerce order JSON stored locally after a cache refresh. Useful for debugging invoice field extraction.</span>
</td></tr>
</table>
<?php

// Cache refresh modal
?>
<div id="wbsSetupCacheModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:72%;max-width:860px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.25);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
      <strong>Refreshing invoice cache&hellip;</strong>
      <button type="button" onclick="wbsSetupEsc('wbsSetupCacheModal')" style="border:none;background:none;font-size:22px;line-height:1;cursor:pointer;color:#666;">&times;</button>
    </div>
    <div style="padding:16px 20px;overflow-y:auto;flex:1;">
      <div id="wbsSetupCacheProgress" style="height:18px;background:#eee;border-radius:4px;margin-bottom:12px;"><div id="wbsSetupCacheBar" style="height:100%;width:0;background:#0082c3;border-radius:4px;transition:width .3s;"></div></div>
      <div id="wbsSetupCacheStatus" style="margin-bottom:10px;font-size:0.9em;color:#555;"></div>
      <ul id="wbsSetupCacheLog" style="font-size:0.82em;max-height:340px;overflow-y:auto;margin:0;padding-left:18px;"></ul>
    </div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;">
      <button class="button" type="button" onclick="wbsSetupEsc('wbsSetupCacheModal')">Close</button>
    </div>
  </div>
</div>
<?php

// JSON viewer modal
?>
<div id="wbsSetupJsonModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:88%;max-width:1100px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.25);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
      <strong>Cached WooCommerce order JSON</strong>
      <button type="button" onclick="wbsSetupEsc('wbsSetupJsonModal')" style="border:none;background:none;font-size:22px;line-height:1;cursor:pointer;color:#666;">&times;</button>
    </div>
    <div style="padding:12px 20px;border-bottom:1px solid #eee;">
      <select id="wbsSetupJsonSelect" style="width:100%;max-width:420px;" onchange="wbsSetupShowCachedJson(this.value)">
        <option value="">Loading orders&hellip;</option>
      </select>
    </div>
    <div style="padding:16px 20px;overflow-y:auto;flex:1;">
      <pre id="wbsSetupJsonPre" style="font-size:0.82em;margin:0;white-space:pre-wrap;word-break:break-all;"></pre>
    </div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;">
      <button class="button" type="button" onclick="wbsSetupEsc('wbsSetupJsonModal')">Close</button>
    </div>
  </div>
</div>
<?php

?>
<script>
var _wbsSetupAjaxUrl = <?php echo json_encode($_SERVER['PHP_SELF']); ?>;
var _wbsIndexAjaxUrl = <?php echo json_encode(DOL_URL_ROOT . '/custom/woobanksync/index.php'); ?>;
var _wbsSetupToken = <?php echo json_encode(newToken()); ?>;
var _wbsSetupCacheOrders = [], _wbsSetupCacheIdx = 0, _wbsSetupCacheBatch = 10;
var _wbsSetupCacheUpdated = 0, _wbsSetupCacheErrors = 0;
function wbsSetupEsc(id){document.getElementById(id).style.display="none";}
function wbsEsc(s){return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");}
function wbsSetupOpenPdfModal(){
  var force=document.getElementById("wbsSetupForceDownload").checked?"1":"0";
  document.getElementById("wbsSetupPdfModal").style.display="flex";
  document.getElementById("wbsSetupPdfList").innerHTML="";
  document.getElementById("wbsSetupPdfBar").style.width="0%";
  document.getElementById("wbsSetupPdfStatus").textContent="Fetching pending list…";
  document.getElementById("wbsSetupPdfDoneBtn").style.display="none";
  document.getElementById("wbsSetupPdfSummary").textContent="";
  fetch(_wbsIndexAjaxUrl+"?action=pending_pdfs&force="+force+"&token="+encodeURIComponent(_wbsSetupToken))
    .then(function(r){return r.json();}).then(function(data){
      var orders=data.orders||[];
      if(!orders.length){
        document.getElementById("wbsSetupPdfStatus").textContent="No pending PDFs found. Use Force re-download all to re-fetch from WooCommerce.";
        document.getElementById("wbsSetupPdfBar").style.width="100%";
        document.getElementById("wbsSetupPdfDoneBtn").style.display="inline-block";
        return;
      }
      document.getElementById("wbsSetupPdfStatus").textContent="0 / "+orders.length+(force==="1"?" (force mode)":"")+" — downloading…";
      orders.forEach(function(o){
        var row=document.createElement("div");
        row.id="wbss-row-"+o.id;
        row.style.cssText="padding:7px 0;border-bottom:1px solid #f2f2f2;display:flex;flex-direction:column;gap:2px;";
        row.innerHTML='<div style="display:flex;align-items:center;gap:10px;"><span id="wbss-icon-'+o.id+'" style="font-size:1.15em;min-width:1.4em;text-align:center;">⏳</span><span style="flex:1;">#'+wbsEsc(o.number)+(o.invoice?" &ndash; "+wbsEsc(o.invoice):"")+'</span></div><pre id="wbss-note-'+o.id+'" style="font-size:.75em;color:#888;margin:0 0 0 1.8em;white-space:pre-wrap;word-break:break-all;"></pre>';
        document.getElementById("wbsSetupPdfList").appendChild(row);
      });
      wbsSetupDownloadNext(orders,0,0,0,force);
    }).catch(function(e){
      document.getElementById("wbsSetupPdfStatus").textContent="Error: "+e.message;
      document.getElementById("wbsSetupPdfDoneBtn").style.display="inline-block";
    });
}
function wbsSetupDownloadNext(orders,idx,ok,fail,force){
  var total=orders.length;
  if(idx>=total){
    document.getElementById("wbsSetupPdfBar").style.width="100%";
    document.getElementById("wbsSetupPdfStatus").textContent="Done.";
    document.getElementById("wbsSetupPdfDoneBtn").style.display="inline-block";
    document.getElementById("wbsSetupPdfSummary").textContent="✅ "+ok+" downloaded   ❌ "+fail+" failed";
    return;
  }
  var o=orders[idx];
  document.getElementById("wbsSetupPdfBar").style.width=Math.round(idx/total*100)+"%";
  document.getElementById("wbsSetupPdfStatus").textContent=(idx+1)+" / "+total+" — downloading #"+wbsEsc(o.number)+"…";
  var el=document.getElementById("wbss-row-"+o.id);if(el)el.scrollIntoView({block:"nearest",behavior:"smooth"});
  var body="action=download_pdf_single&woo_order_id="+encodeURIComponent(o.id)+"&force="+(force||"0")+"&token="+encodeURIComponent(_wbsSetupToken);
  fetch(_wbsIndexAjaxUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:body})
    .then(function(r){return r.json();}).then(function(res){
      var icon=document.getElementById("wbss-icon-"+o.id);
      var note=document.getElementById("wbss-note-"+o.id);
      var logStr=(res.log&&res.log.length)?"\n"+res.log.join("\n"):"";
      if(res.ok){icon.textContent=res.already?"✔️":"✅";note.textContent=(res.already?"already saved":"saved")+logStr;ok++;}
      else{icon.textContent="❌";note.textContent=(res.error||"failed")+logStr;fail++;}
      wbsSetupDownloadNext(orders,idx+1,ok,fail,force);
    }).catch(function(){
      var icon=document.getElementById("wbss-icon-"+o.id);if(icon)icon.textContent="❌";fail++;
      wbsSetupDownloadNext(orders,idx+1,ok,fail,force);
    });
}
function wbsSetupOpenCacheModal(){
  var rangeLatest=document.getElementById("wbsCacheRangeLatest").checked;
  var limit=rangeLatest?parseInt(document.getElementById("wbsCacheLimit").value,10):0;
  if(isNaN(limit)||limit<1)limit=rangeLatest?50:0;
  _wbsSetupCacheOrders=[];_wbsSetupCacheIdx=0;_wbsSetupCacheUpdated=0;_wbsSetupCacheErrors=0;
  document.getElementById("wbsSetupCacheBar").style.width="0";
  document.getElementById("wbsSetupCacheStatus").textContent="Loading order list…";
  document.getElementById("wbsSetupCacheLog").innerHTML="";
  document.getElementById("wbsSetupCacheModal").style.display="flex";
  var fd=new FormData();fd.append("token",_wbsSetupToken);fd.append("action","setup_cache_refresh_list");
  if(limit>0)fd.append("limit",limit);
  fetch(_wbsSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    if(!d.ok){document.getElementById("wbsSetupCacheStatus").textContent="Error: "+d.error;return;}
    _wbsSetupCacheOrders=d.orders;
    _wbsSetupCacheBatch=d.batch_size||10;
    if(!_wbsSetupCacheOrders.length){document.getElementById("wbsSetupCacheStatus").textContent="No synced orders found.";return;}
    document.getElementById("wbsSetupCacheStatus").textContent="Found "+_wbsSetupCacheOrders.length+" orders. Refreshing cache…";
    wbsSetupRefreshCacheBatch();
  }).catch(function(e){document.getElementById("wbsSetupCacheStatus").textContent="Request failed: "+e;});
}
function wbsSetupRefreshCacheBatch(){
  if(_wbsSetupCacheIdx>=_wbsSetupCacheOrders.length){
    var pct=Math.round(100*_wbsSetupCacheIdx/_wbsSetupCacheOrders.length);
    document.getElementById("wbsSetupCacheBar").style.width=pct+"%";
    document.getElementById("wbsSetupCacheStatus").textContent="Done. Updated: "+_wbsSetupCacheUpdated+" / Errors: "+_wbsSetupCacheErrors;
    return;
  }
  var slice=_wbsSetupCacheOrders.slice(_wbsSetupCacheIdx,_wbsSetupCacheIdx+_wbsSetupCacheBatch);
  var ids=slice.map(function(o){return o.id;}).join(",");
  var pct=Math.round(100*_wbsSetupCacheIdx/_wbsSetupCacheOrders.length);
  document.getElementById("wbsSetupCacheBar").style.width=pct+"%";
  document.getElementById("wbsSetupCacheStatus").textContent="Processing "+(_wbsSetupCacheIdx+1)+"–"+Math.min(_wbsSetupCacheIdx+_wbsSetupCacheBatch,_wbsSetupCacheOrders.length)+" of "+_wbsSetupCacheOrders.length+"…";
  var fd=new FormData();fd.append("token",_wbsSetupToken);fd.append("action","setup_cache_refresh_batch");fd.append("order_ids",ids);
  fetch(_wbsSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    var ul=document.getElementById("wbsSetupCacheLog");
    if(d.ok&&d.result){
      _wbsSetupCacheUpdated+=d.result.updated||0;
      _wbsSetupCacheErrors+=d.result.errors||0;
      (d.result.items||[]).forEach(function(item){
        var li=document.createElement("li");
        li.textContent=(item.ok?"OK":"ERR")+" #"+(item.number||item.id);
        ul.appendChild(li);
      });
    }else{_wbsSetupCacheErrors+=slice.length;}
    _wbsSetupCacheIdx+=_wbsSetupCacheBatch;
    wbsSetupRefreshCacheBatch();
  }).catch(function(){_wbsSetupCacheErrors+=slice.length;_wbsSetupCacheIdx+=_wbsSetupCacheBatch;wbsSetupRefreshCacheBatch();});
}
function wbsSetupOpenJsonModal(){
  document.getElementById("wbsSetupJsonPre").textContent="";
  document.getElementById("wbsSetupJsonModal").style.display="flex";
  var fd=new FormData();fd.append("token",_wbsSetupToken);fd.append("action","setup_cached_json_list");
  fetch(_wbsSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    var sel=document.getElementById("wbsSetupJsonSelect");sel.innerHTML="";
    if(!d.ok||!d.orders||!d.orders.length){
      sel.innerHTML="<option value=\"\">No cached JSON found &mdash; run Refresh invoice cache first</option>";return;
    }
    d.orders.forEach(function(o){
      var opt=document.createElement("option");
      opt.value=o.id;opt.textContent="#"+(o.number||o.id)+(o.invoice?" — "+o.invoice:"");
      sel.appendChild(opt);
    });
    wbsSetupShowCachedJson(d.orders[0].id);
  }).catch(function(e){document.getElementById("wbsSetupJsonPre").textContent="Request failed: "+e;});
}
function wbsSetupShowCachedJson(orderId){
  if(!orderId)return;
  document.getElementById("wbsSetupJsonPre").textContent="Loading…";
  var fd=new FormData();fd.append("token",_wbsSetupToken);fd.append("action","setup_cached_json_item");fd.append("woo_order_id",orderId);
  fetch(_wbsSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    if(!d.ok){document.getElementById("wbsSetupJsonPre").textContent="Error: "+d.error;return;}
    try{document.getElementById("wbsSetupJsonPre").textContent=JSON.stringify(JSON.parse(d.json),null,2);}
    catch(e){document.getElementById("wbsSetupJsonPre").textContent=d.json;}
  }).catch(function(e){document.getElementById("wbsSetupJsonPre").textContent="Request failed: "+e;});
}
var _wbsLogAllRows = [];
function wbsSetupOpenLogModal(){
  _wbsLogAllRows=[];
  document.getElementById("wbsLogSearch").value="";
  document.getElementById("wbsLogBody").innerHTML='<tr><td colspan="9" style="text-align:center;padding:20px;color:#888;">Loading…</td></tr>';
  document.getElementById("wbsLogCount").textContent="";
  document.getElementById("wbsLogModal").style.display="flex";
  var fd=new FormData();fd.append("token",_wbsSetupToken);fd.append("action","setup_log_list");
  fetch(_wbsSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    if(!d.ok){document.getElementById("wbsLogBody").innerHTML='<tr><td colspan="9">Error: '+d.error+'</td></tr>';return;}
    _wbsLogAllRows=d.rows||[];
    wbsSetupFilterLog();
  }).catch(function(e){document.getElementById("wbsLogBody").innerHTML='<tr><td colspan="9">Request failed: '+e+'</td></tr>';});
}
function wbsSetupFilterLog(){
  var q=(document.getElementById("wbsLogSearch").value||"").toLowerCase().trim();
  var rows=q?_wbsLogAllRows.filter(function(r){
    return (r.number+r.invoice+r.payment+r.status+r.message).toLowerCase().indexOf(q)>=0;
  }):_wbsLogAllRows;
  document.getElementById("wbsLogCount").textContent=rows.length+" of "+_wbsLogAllRows.length+" rows";
  var html="";
  rows.forEach(function(r,i){
    var cls=i%2===0?"impair":"pair";
    var statusCol=r.status==="synced"?"color:#090":"color:#c00";
    var pdf="";
    if(r.pdf_ecm)pdf='<span title="Saved locally">&#128196;</span>';
    else if(r.pdf_url)pdf='<a href="'+r.pdf_url+'" target="_blank" title="Stored URL">&#8599;</a>';
    var net=parseFloat(r.net||0);
    var netStr=net!==0?net.toFixed(2):( (parseFloat(r.gross||0)-parseFloat(r.fee||0)).toFixed(2) );
    html+='<tr class="'+cls+'">';
    html+='<td style="white-space:nowrap;">'+r.date.substring(0,16)+'</td>';
    html+='<td>#'+r.number+'</td>';
    html+='<td>'+r.invoice+'</td>';
    html+='<td>'+r.payment+'</td>';
    html+='<td style="text-align:right;">'+parseFloat(r.gross||0).toFixed(2)+'</td>';
    html+='<td style="text-align:right;">'+parseFloat(r.fee||0).toFixed(2)+'</td>';
    html+='<td style="text-align:right;font-weight:bold;">'+netStr+' '+r.currency+'</td>';
    html+='<td style="'+statusCol+'">'+r.status+'</td>';
    html+='<td>'+pdf+'</td>';
    html+='<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+r.message.replace(/"/g,'&quot;')+'">'+r.message+'</td>';
    html+='</tr>';
  });
  if(!rows.length)html='<tr><td colspan="10" style="text-align:center;padding:20px;color:#888;">No rows match.</td></tr>';
  document.getElementById("wbsLogBody").innerHTML=html;
}
var _wbsPdfStatusAllRows=[];
function wbsSetupOpenPdfStatusModal(){
  _wbsPdfStatusAllRows=[];
  document.getElementById("wbsPdfStatusBody").innerHTML='<tr><td colspan="6" style="text-align:center;padding:20px;color:#888;">Loading…</td></tr>';
  document.getElementById("wbsPdfStatusSearch").value="";
  document.getElementById("wbsPdfStatusMissingOnly").checked=false;
  document.getElementById("wbsPdfStatusCount").textContent="";
  document.getElementById("wbsPdfStatusModal").style.display="flex";
  var fd=new FormData();fd.append("token",_wbsSetupToken);fd.append("action","setup_pdf_status");
  fetch(_wbsSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    if(!d.ok){document.getElementById("wbsPdfStatusBody").innerHTML='<tr><td colspan="6">Error: '+d.error+'</td></tr>';return;}
    _wbsPdfStatusAllRows=d.rows||[];
    wbsSetupFilterPdfStatus();
  }).catch(function(e){document.getElementById("wbsPdfStatusBody").innerHTML='<tr><td colspan="6">Request failed: '+e+'</td></tr>';});
}
function wbsSetupFilterPdfStatus(){
  var q=(document.getElementById("wbsPdfStatusSearch").value||"").toLowerCase().trim();
  var missingOnly=document.getElementById("wbsPdfStatusMissingOnly").checked;
  var rows=_wbsPdfStatusAllRows.filter(function(r){
    if(missingOnly&&(r.pdf_ecm||r.pdf_url))return false;
    if(q&&(r.number+r.invoice+r.payment).toLowerCase().indexOf(q)<0)return false;
    return true;
  });
  var saved=_wbsPdfStatusAllRows.filter(function(r){return!!r.pdf_ecm;}).length;
  var urlOnly=_wbsPdfStatusAllRows.filter(function(r){return!r.pdf_ecm&&!!r.pdf_url;}).length;
  var missing=_wbsPdfStatusAllRows.length-saved-urlOnly;
  document.getElementById("wbsPdfStatusCount").textContent=rows.length+" shown  ✅ "+saved+" saved  📄 "+urlOnly+" URL only  ❌ "+missing+" missing";
  var html="";
  rows.forEach(function(r,i){
    var cls=i%2===0?"impair":"pair";
    var icon,detail;
    if(r.pdf_ecm){icon='<span title="Saved in Dolibarr ECM" style="color:#090;">✅</span>';detail='<span style="color:#090;font-size:.85em;">'+wbsEsc(r.pdf_ecm)+'</span>';}
    else if(r.pdf_url){icon='<span title="URL known, not yet downloaded" style="color:#e67e22;">&#128196;</span>';detail='<a href="'+wbsEsc(r.pdf_url)+'" target="_blank" style="font-size:.85em;word-break:break-all;">'+wbsEsc(r.pdf_url)+'</a>';}
    else{icon='<span title="No PDF info — use Download invoice PDFs with Force re-download" style="color:#c00;">❌</span>';detail='<span style="color:#aaa;font-size:.85em;">—</span>';}
    html+='<tr class="'+cls+'">';
    html+='<td style="white-space:nowrap;">'+r.date.substring(0,16)+'</td>';
    html+='<td>#'+wbsEsc(r.number)+'</td>';
    html+='<td>'+wbsEsc(r.invoice)+'</td>';
    html+='<td>'+wbsEsc(r.payment)+'</td>';
    html+='<td style="text-align:center;">'+icon+'</td>';
    html+='<td>'+detail+'</td>';
    html+='</tr>';
  });
  if(!rows.length)html='<tr><td colspan="6" style="text-align:center;padding:20px;color:#888;">No rows match.</td></tr>';
  document.getElementById("wbsPdfStatusBody").innerHTML=html;
}
</script>
<?php
// ─────────────────────────────────────────────────────────────────────────────

$diagData = $sync->getJsonConst('WBS_META_DIAG_JSON', array());
$diagJson = !empty($diagData) ? json_encode($diagData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
?>
<br><table class="noborder centpercent">
<tr class="liste_titre"><td>Diagnostics</td></tr>
<tr><td>
<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="display:inline-block;margin-right:8px;">
<input type="hidden" name="token" value="<?php echo newToken(); ?>"><input type="hidden" name="action" value="diagnose_meta">
<input class="button" type="submit" value="Inspect WooCommerce order meta (last 10 orders)">
</form>
<?php
if (!empty($diagData)) {
?>
<button class="button" type="button" onclick="document.getElementById('wbsDiagModal').style.display='flex';">View last results</button>
<?php
}
?>
<br><span class="opacitymedium">Fetches 10 recent orders from WooCommerce and shows every meta_data key the API returns. Use this to find where Germanized or other plugins store the invoice number.</span>
</td></tr></table>
<?php

if (!empty($diagData)) {
    $diagJsonEscaped = htmlspecialchars($diagJson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div id="wbsDiagModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:88%;max-width:1100px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.25);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
      <strong>WooCommerce order meta diagnostic</strong>
      <button type="button" onclick="document.getElementById('wbsDiagModal').style.display='none';" style="border:none;background:none;font-size:22px;line-height:1;cursor:pointer;color:#666;">&times;</button>
    </div>
    <div style="padding:16px 20px;overflow-y:auto;flex:1;">
<?php
?>
      <pre id="wbsDiagPre" style="font-size:0.82em;margin:0;white-space:pre-wrap;word-break:break-all;"><?php echo $diagJsonEscaped; ?></pre>
<?php
?>
    </div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;display:flex;gap:10px;">
      <button class="button" type="button" onclick="wbsDownloadDiag()">Download as JSON</button>
      <button class="button" type="button" onclick="document.getElementById('wbsDiagModal').style.display='none';">Close</button>
    </div>
  </div>
</div>
<?php
?>
<script>
var _wbsDiagJson = <?php echo $diagJson; ?>;
function wbsDownloadDiag(){
  var blob=new Blob([JSON.stringify(_wbsDiagJson,null,2)],{type:"application/json"});
  var a=document.createElement("a");a.href=URL.createObjectURL(blob);
  a.download="wbs-meta-diagnostic.json";document.body.appendChild(a);a.click();document.body.removeChild(a);
}
<?php
    if ($action === 'diagnose_meta') {
?>
document.getElementById("wbsDiagModal").style.display="flex";
<?php
    }
?>
</script>
<?php
}

?>
<br><table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">PDF download test</td></tr>
<tr><td class="titlefield">Order ID or PDF URL</td><td>
<input type="text" id="wbsTestPdfInput" class="flat" placeholder="WooCommerce order ID (e.g. 30955)  OR  https://... direct PDF URL" style="width:500px;max-width:100%;">
 <button class="button" type="button" onclick="wbsSetupTestPdf()">Test download attempt</button>
<br><span class="opacitymedium">Order ID → calls the StoreaBill API endpoint directly (no cache required). URL → tries direct fetch strategies. Does not save any file.</span>
</td></tr>
<tr><td class="titlefield" style="vertical-align:top;padding-top:6px;">Attempt log</td><td>
<pre id="wbsTestPdfLog" style="font-size:0.82em;background:#f5f5f5;border:1px solid #ddd;padding:10px;min-height:80px;white-space:pre-wrap;word-break:break-all;margin:0;border-radius:4px;"></pre>
</td></tr></table>
<script>
function wbsSetupTestPdf(){
  var val=document.getElementById("wbsTestPdfInput").value.trim();
  if(!val){document.getElementById("wbsTestPdfLog").textContent="Enter an order ID or PDF URL first.";return;}
  document.getElementById("wbsTestPdfLog").textContent="Testing…";
  var fd=new FormData();fd.append("token",_wbsSetupToken);fd.append("action","setup_test_pdf");
  if(/^https?:\/\//.test(val)){fd.append("pdf_url",val);}else{fd.append("woo_order_id",val);}
  fetch(_wbsSetupAjaxUrl,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(d){
    var lines=[];
    lines.push("Result: "+(d.ok?"OK":"FAILED"));
    if(d.mode==="sab"){lines.push("Mode: StoreaBill API endpoint (no cache used)");}
    else if(d.mode==="url"){lines.push("Mode: direct URL fetch");}
    if(d.invoice_number)lines.push("Invoice number from SAB: "+d.invoice_number);
    if(d.url)lines.push("URL tested: "+d.url);
    if(d.bytes)lines.push("Bytes received: "+d.bytes);
    if(d.is_pdf!==undefined)lines.push("Valid PDF header: "+(d.is_pdf?"YES":"NO — response is not a PDF"));
    if(d.error)lines.push("Error: "+d.error);
    if(d.log&&d.log.length){lines.push("","=== Attempt log ===");d.log.forEach(function(l){lines.push(l);});}
    document.getElementById("wbsTestPdfLog").textContent=lines.join("\n");
  }).catch(function(e){document.getElementById("wbsTestPdfLog").textContent="Request failed: "+e;});
}
</script>
<?php

?>
<!-- Danger zone -->
<br>
<table class="noborder centpercent">
    <tr class="liste_titre"><td>Danger zone</td></tr>
    <tr><td>
        <button class="button button-delete" type="button"
                style="background:#b00020;color:#fff;border-color:#b00020;"
                onclick="wbsOpenDesyncModal()">
            ⚠️ Desync: delete synced bank entries, PDFs and reset log
        </button>
        <br><span class="opacitymedium">
            Deletes bank lines stored in the WooBankSync log, downloaded PDF files, and clears the sync log.
            Does not touch WooCommerce orders or manually-created Dolibarr entries.
        </span>
    </td></tr>
</table>

<!-- Desync confirmation + progress modal -->
<div id="wbsDesyncModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.55);z-index:10000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:6px;width:600px;max-width:94%;box-shadow:0 8px 32px rgba(0,0,0,.28);">
        <div style="padding:16px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
            <strong style="color:#b00020;">⚠️ Desync — confirm deletion</strong>
            <span onclick="wbsDesyncEsc()" style="cursor:pointer;font-size:22px;color:#666;">&times;</span>
        </div>
        <div id="wbsDesyncConfirmStep" style="padding:18px 20px;">
            <p style="margin:0 0 12px;">This will permanently delete:</p>
            <ul style="margin:0 0 16px;padding-left:20px;line-height:1.8;">
                <li>All Dolibarr bank lines created by WooBankSync</li>
                <li>All invoice PDF files downloaded to Dolibarr ECM</li>
                <li>All WooBankSync sync log entries</li>
                <li>All WooBankSync order cache entries</li>
            </ul>
            <p style="margin:0 0 16px;color:#555;">WooCommerce orders and manually-created Dolibarr entries are <strong>not</strong> affected.</p>
            <p style="margin:0 0 16px;font-weight:bold;color:#b00020;">This action cannot be undone.</p>
            <div style="display:flex;gap:10px;">
                <button id="wbsDesyncConfirmBtn" class="button button-delete"
                        style="background:#b00020;color:#fff;border-color:#b00020;"
                        onclick="wbsRunDesync()">Yes, delete everything</button>
                <button class="button" type="button" onclick="wbsDesyncEsc()">Cancel</button>
            </div>
        </div>
        <div id="wbsDesyncProgressStep" style="display:none;padding:18px 20px;text-align:center;">
            <div style="font-size:2em;margin-bottom:12px;">⏳</div>
            <div style="color:#555;">Running desync — please wait…</div>
        </div>
        <div id="wbsDesyncResultStep" style="display:none;padding:18px 20px;">
            <div id="wbsDesyncResultIcon" style="font-size:2em;text-align:center;margin-bottom:10px;"></div>
            <div id="wbsDesyncResultMsg" style="margin-bottom:14px;text-align:center;"></div>
            <table id="wbsDesyncResultTable" class="noborder centpercent" style="font-size:.9em;display:none;">
                <tr><td>Bank lines deleted</td><td id="wbsDrBank" style="text-align:right;font-weight:bold;"></td></tr>
                <tr><td>PDF files deleted</td><td id="wbsDrPdfs" style="text-align:right;font-weight:bold;"></td></tr>
                <tr><td>Log rows deleted</td><td id="wbsDrLogs" style="text-align:right;font-weight:bold;"></td></tr>
            </table>
            <div style="margin-top:16px;text-align:center;">
                <button class="button" type="button" onclick="wbsDesyncEsc()">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function wbsDesyncEsc() {
    document.getElementById('wbsDesyncModal').style.display = 'none';
    document.getElementById('wbsDesyncConfirmStep').style.display  = 'block';
    document.getElementById('wbsDesyncProgressStep').style.display = 'none';
    document.getElementById('wbsDesyncResultStep').style.display   = 'none';
    document.getElementById('wbsDesyncConfirmBtn').disabled = false;
}

function wbsOpenDesyncModal() {
    wbsDesyncEsc();
    document.getElementById('wbsDesyncModal').style.display = 'flex';
}

function wbsRunDesync() {
    document.getElementById('wbsDesyncConfirmBtn').disabled = true;
    document.getElementById('wbsDesyncConfirmStep').style.display  = 'none';
    document.getElementById('wbsDesyncProgressStep').style.display = 'block';

    var fd = new FormData();
    fd.append('token', <?php echo json_encode(newToken()); ?>);
    fd.append('action', 'desync_ajax');

    fetch(<?php echo json_encode($_SERVER['PHP_SELF']); ?>, {method: 'POST', body: fd})
        .then(function(r) { return r.json(); })
        .then(function(d) {
            document.getElementById('wbsDesyncProgressStep').style.display = 'none';
            document.getElementById('wbsDesyncResultStep').style.display   = 'block';
            if (d.ok) {
                document.getElementById('wbsDesyncResultIcon').textContent = '✅';
                document.getElementById('wbsDesyncResultMsg').textContent  = 'Desync complete.';
                var s = d.stats || {};
                document.getElementById('wbsDrBank').textContent = s.bank  || 0;
                document.getElementById('wbsDrPdfs').textContent = s.pdfs  || 0;
                document.getElementById('wbsDrLogs').textContent = s.logs  || 0;
                document.getElementById('wbsDesyncResultTable').style.display = 'table';
            } else {
                document.getElementById('wbsDesyncResultIcon').textContent = '❌';
                document.getElementById('wbsDesyncResultMsg').style.color  = '#b00020';
                document.getElementById('wbsDesyncResultMsg').textContent  = d.error || 'Desync failed.';
            }
        })
        .catch(function(e) {
            document.getElementById('wbsDesyncProgressStep').style.display = 'none';
            document.getElementById('wbsDesyncResultStep').style.display   = 'block';
            document.getElementById('wbsDesyncResultIcon').textContent = '❌';
            document.getElementById('wbsDesyncResultMsg').style.color  = '#b00020';
            document.getElementById('wbsDesyncResultMsg').textContent  = 'Request failed: ' + e;
        });
}
</script>

<?php
llxFooter();
$db->close();
