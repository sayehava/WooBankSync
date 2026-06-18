<?php
$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__ . '/class/woobanksync.class.php';

$langs->loadLangs(array('banks', 'woobanksync@woobanksync'));
if (!$user->hasRight('banque', 'lire')) accessforbidden();

$action = GETPOST('action', 'aZ09');

// ── AJAX: return list of orders that have a PDF URL but no local ECM file ──
if ($action === 'pending_pdfs') {
    header('Content-Type: application/json');
    $sync = new WooBankSync($db, $conf, $langs);
    echo json_encode(array('orders' => $sync->getPendingPdfOrders()));
    exit;
}

// ── AJAX: return the full WooCommerce order JSON stored in the local cache ──
if ($action === 'cached_order_json') {
    header('Content-Type: application/json');
    if (!$user->hasRight('woobanksync', 'run') && !$user->admin) {
        echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit;
    }
    $sync = new WooBankSync($db, $conf, $langs);
    echo json_encode(array('ok' => true, 'orders' => $sync->getCachedOrderJsonRows()));
    exit;
}

// ── AJAX: return one cached WooCommerce order JSON record ──
if ($action === 'cached_order_json_item') {
    header('Content-Type: application/json');
    if (!$user->hasRight('woobanksync', 'run') && !$user->admin) {
        echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit;
    }
    $wooOrderId = GETPOST('woo_order_id', 'alphanohtml');
    if ($wooOrderId === '') {
        echo json_encode(array('ok' => false, 'error' => 'Missing order ID')); exit;
    }
    $sync = new WooBankSync($db, $conf, $langs);
    $rawJson = $sync->getCachedOrderJson($wooOrderId);
    if ($rawJson === null) {
        echo json_encode(array('ok' => false, 'error' => 'Cached JSON not found')); exit;
    }
    echo json_encode(array('ok' => true, 'json' => $rawJson));
    exit;
}

// ── AJAX: list synced orders for the paginated full-cache refresh ──
if ($action === 'full_cache_refresh_list') {
    header('Content-Type: application/json');
    if (!$user->hasRight('woobanksync', 'run') && !$user->admin) {
        echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit;
    }
    $sync = new WooBankSync($db, $conf, $langs);
    echo json_encode(array(
        'ok' => true,
        'orders' => $sync->getFullCacheRefreshOrders(),
        'germanized_enabled' => !empty($conf->global->WBS_GERMANIZED_PRO_ENABLED),
        'batch_size' => max(1, min(100, (int) ($conf->global->WBS_CACHE_BATCH_SIZE ?? 1))),
    ));
    exit;
}

// ── AJAX: refresh and merge one batch of full Woo/Germanized cache data ──
if ($action === 'full_cache_refresh_batch') {
    header('Content-Type: application/json');
    if (!$user->hasRight('woobanksync', 'run') && !$user->admin) {
        echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit;
    }
    $rawIds = GETPOST('order_ids', 'restricthtml');
    $orderIds = preg_split('/[^0-9]+/', (string) $rawIds, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($orderIds)) {
        echo json_encode(array('ok' => false, 'error' => 'No order IDs supplied')); exit;
    }
    @set_time_limit(300);
    $sync = new WooBankSync($db, $conf, $langs);
    echo json_encode(array('ok' => true, 'result' => $sync->refreshFullCacheBatch($orderIds)));
    exit;
}

// ── AJAX: synchronize one configured page of WooCommerce orders ──
if ($action === 'sync_batch') {
    header('Content-Type: application/json');
    if (!$user->hasRight('woobanksync', 'run') && !$user->admin) {
        echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit;
    }
    $page = max(1, (int) GETPOST('page', 'int'));
    $batchSize = max(1, min(100, (int) ($conf->global->WBS_SYNC_BATCH_SIZE ?? 10)));
    @set_time_limit(300);
    $sync = new WooBankSync($db, $conf, $langs);
    echo json_encode(array(
        'ok' => true,
        'page' => $page,
        'batch_size' => $batchSize,
        'result' => $sync->syncBatch($page, $batchSize),
    ));
    exit;
}

// ── AJAX: list orders eligible for difference checking ──
if ($action === 'difference_list') {
    header('Content-Type: application/json');
    if (!$user->hasRight('woobanksync', 'run') && !$user->admin) {
        echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit;
    }
    $sync = new WooBankSync($db, $conf, $langs);
    echo json_encode(array(
        'ok' => true,
        'orders' => $sync->getDifferenceCheckOrders(),
        'batch_size' => max(1, min(100, (int) ($conf->global->WBS_DIFF_BATCH_SIZE ?? 10))),
    ));
    exit;
}

// ── AJAX: download one PDF by order ID from the local cache (no API call) ──
if ($action === 'download_pdf_single') {
    header('Content-Type: application/json');
    if (!$user->hasRight('woobanksync', 'run') && !$user->admin) {
        echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit;
    }
    $wooOrderId = GETPOST('woo_order_id', 'alphanohtml');
    if (empty($wooOrderId)) { echo json_encode(array('ok' => false, 'error' => 'Missing order ID')); exit; }

    $sql = 'SELECT woo_order_id, woo_order_number, woo_invoice_number, woo_invoice_pdf_url, pdf_ecm_filepath'
        . ' FROM ' . MAIN_DB_PREFIX . 'woobanksync_order_cache'
        . ' WHERE entity=' . (int) $conf->entity . " AND woo_order_id='" . $db->escape($wooOrderId) . "' LIMIT 1";
    $resql = $db->query($sql);
    if (!$resql || !($row = $db->fetch_object($resql))) {
        echo json_encode(array('ok' => false, 'error' => 'Order not in cache')); exit;
    }
    if (empty($row->woo_invoice_pdf_url)) {
        echo json_encode(array('ok' => false, 'error' => 'No PDF URL in cache for this order')); exit;
    }
    $sync = new WooBankSync($db, $conf, $langs);
    if (!empty($row->pdf_ecm_filepath) && $sync->isInvoicePdfStored((string) $row->pdf_ecm_filepath)) {
        echo json_encode(array('ok' => true, 'already' => true, 'filepath' => $row->pdf_ecm_filepath)); exit;
    }
    if (!empty($row->pdf_ecm_filepath)) {
        $sync->updateCacheEcmPath((string) $row->woo_order_id, '');
    }

    @set_time_limit(120);
    $ecmPath = $sync->downloadInvoicePdfPublic(
        (string) $row->woo_order_id,
        (string) $row->woo_order_number,
        (string) ($row->woo_invoice_number ?? ''),
        (string) $row->woo_invoice_pdf_url
    );

    if ($ecmPath !== '') {
        $sync->updateCacheEcmPath((string) $row->woo_order_id, $ecmPath);
        echo json_encode(array('ok' => true, 'filepath' => $ecmPath));
    } else {
        echo json_encode(array('ok' => false, 'error' => 'Download failed — check PDF URL and folder mapping in Setup'));
    }
    exit;
}

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
        $type = $stats['errors'] ? 'warnings' : 'mesgs';
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

$ajaxUrl = dol_escape_htmltag($_SERVER['PHP_SELF']);
$ajaxToken = newToken();

?>
<button class="button" type="button" onclick="wbsOpenSyncModal()" style="margin-right:10px;">Sync now</button>
<form method="POST" action="<?php echo $ajaxUrl; ?>" style="display:inline-block;margin-right:10px;">
<input type="hidden" name="token" value="<?php echo $ajaxToken; ?>">
<input type="hidden" name="action" value="resyncdiff">
<input class="button" type="submit" value="Check &amp; update differences" title="Re-fetches all synced orders from WooCommerce and updates Dolibarr bank entries where invoice number, buyer name or amounts differ. WooCommerce is always the source of truth.">
</form>
<button class="button" type="button" onclick="wbsOpenPdfModal()" title="Download missing invoice PDFs using URLs already stored locally — no WooCommerce API call">&#128196; Download past invoice PDFs</button>
 <button class="button" type="button" onclick="wbsOpenCacheRefreshModal()" title="Fetch all Woo order data in configured batches and merge it into the local JSON cache">&#8635; Refresh full cache</button>
 <button class="button" type="button" onclick="wbsOpenJsonModal()" title="View the full WooCommerce order responses stored in the local cache">{ } View cached Woo JSON</button>
<br>
<?php

if (!empty($conf->global->WBS_LAST_SYNC)) {
?>
<div class="opacitymedium" style="margin-top:6px;">Last sync: <?php echo dol_print_date((int) $conf->global->WBS_LAST_SYNC, 'dayhour'); ?></div>
<?php
}
?>
<br>
<?php

$sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'woobanksync_log WHERE entity=' . (int) $conf->entity . ' ORDER BY rowid DESC LIMIT 100';
$resql = $db->query($sql);

?>
<div class="div-table-responsive-no-min">
<table class="liste centpercent">
<tr class="liste_titre"><th>Date sync</th><th>Woo order</th><th>Invoice</th><th>PDF</th><th>Gateway</th><th class="right">Gross</th><th class="right">Fee</th><th class="right">Payout</th><th>Status</th><th>Message</th></tr>
<?php
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        if (!empty($obj->pdf_ecm_filepath)) {
            $pdfLink = '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=ecm&file=' . urlencode($obj->pdf_ecm_filepath) . '" target="_blank" title="Downloaded — stored in Dolibarr ECM">&#128196;&nbsp;PDF</a>';
        } elseif (!empty($obj->woo_invoice_pdf_url)) {
            $pdfLink = '<a href="' . dol_escape_htmltag($obj->woo_invoice_pdf_url) . '" target="_blank" title="Not yet downloaded — opens directly from WooCommerce">&#8599;&nbsp;PDF</a>';
        } else {
            $pdfLink = '';
        }
?>
<tr class="oddeven">
<td><?php echo dol_print_date($db->jdate($obj->date_sync), 'dayhour'); ?></td>
<td><?php echo dol_escape_htmltag($obj->woo_order_number); ?></td>
<td><?php echo dol_escape_htmltag($obj->woo_invoice_number ?? ''); ?></td>
<td><?php echo $pdfLink; ?></td>
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
<tr><td colspan="10"><?php echo dol_escape_htmltag($db->lasterror()); ?></td></tr>
<?php
}
?>
</table></div>
<?php

// ── PDF download modal ────────────────────────────────────────────────────────
?>
<div id="wbsPdfModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:82%;max-width:860px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.28);">

    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
      <strong>&#128196; Download past invoice PDFs</strong>
      <span id="wbsPdfClose" onclick="document.getElementById('wbsPdfModal').style.display='none';" style="cursor:pointer;font-size:22px;line-height:1;color:#666;">&times;</span>
    </div>

    <div style="padding:14px 20px 10px;">
      <div style="background:#e8e8e8;border-radius:4px;height:10px;overflow:hidden;">
        <div id="wbsPdfBar" style="width:0%;height:10px;background:#28a745;border-radius:4px;transition:width 0.4s ease;"></div>
      </div>
      <div id="wbsPdfStatus" style="margin-top:6px;font-size:0.88em;color:#555;">Preparing&hellip;</div>
    </div>

    <div id="wbsPdfList" style="flex:1;overflow-y:auto;padding:4px 20px 10px;font-size:0.9em;"></div>

    <div style="padding:12px 20px;border-top:1px solid #ddd;display:flex;gap:10px;align-items:center;">
      <button id="wbsPdfDoneBtn" class="button" style="display:none;" onclick="document.getElementById('wbsPdfModal').style.display='none';">Close</button>
      <span id="wbsPdfSummary" style="font-size:0.88em;color:#666;"></span>
    </div>

  </div>
</div>

<div id="wbsCacheRefreshModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10002;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:84%;max-width:900px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.28);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
      <strong>&#8635; Refresh full Woo data cache</strong>
      <span onclick="document.getElementById('wbsCacheRefreshModal').style.display='none';" style="cursor:pointer;font-size:22px;line-height:1;color:#666;">&times;</span>
    </div>
    <div style="padding:14px 20px 10px;">
      <div style="background:#e8e8e8;border-radius:4px;height:10px;overflow:hidden;">
        <div id="wbsCacheRefreshBar" style="width:0%;height:10px;background:#2684ff;border-radius:4px;transition:width 0.4s ease;"></div>
      </div>
      <div id="wbsCacheRefreshStatus" style="margin-top:7px;color:#555;">Preparing&hellip;</div>
      <div id="wbsCacheRefreshMode" class="opacitymedium" style="margin-top:3px;"></div>
    </div>
    <div id="wbsCacheRefreshList" style="flex:1;overflow-y:auto;padding:4px 20px 12px;font-size:0.9em;"></div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;display:flex;gap:10px;align-items:center;">
      <button id="wbsCacheRefreshClose" class="button" style="display:none;" onclick="document.getElementById('wbsCacheRefreshModal').style.display='none';">Close</button>
      <span id="wbsCacheRefreshSummary" style="color:#666;"></span>
    </div>
  </div>
</div>

<div id="wbsSyncModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10003;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:84%;max-width:900px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.28);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;"><strong>&#8635; Sync WooCommerce orders</strong></div>
    <div style="padding:14px 20px 10px;">
      <div style="background:#e8e8e8;border-radius:4px;height:10px;overflow:hidden;"><div id="wbsSyncBar" style="width:0%;height:10px;background:#28a745;transition:width 0.4s ease;"></div></div>
      <div id="wbsSyncStatus" style="margin-top:7px;color:#555;">Preparing&hellip;</div>
    </div>
    <div id="wbsSyncList" style="flex:1;overflow-y:auto;padding:4px 20px 12px;font-size:0.9em;"></div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;"><button id="wbsSyncClose" class="button" style="display:none;" onclick="location.reload();">Close and refresh</button> <span id="wbsSyncSummary"></span></div>
  </div>
</div>

<div id="wbsJsonModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10001;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:90%;max-width:1100px;height:86vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.28);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
      <strong>{ } Cached WooCommerce order JSON</strong>
      <span onclick="document.getElementById('wbsJsonModal').style.display='none';" style="cursor:pointer;font-size:22px;line-height:1;color:#666;">&times;</span>
    </div>
    <div style="padding:12px 20px;border-bottom:1px solid #ddd;">
      <label for="wbsJsonOrder"><strong>Order:</strong></label>
      <select id="wbsJsonOrder" class="flat" style="min-width:320px;margin-left:8px;" onchange="wbsShowCachedJson(this.value)"></select>
      <span id="wbsJsonStatus" style="margin-left:12px;color:#666;"></span>
    </div>
    <pre id="wbsJsonContent" style="flex:1;overflow:auto;margin:0;padding:16px 20px;background:#1e1e1e;color:#d4d4d4;font:13px/1.45 monospace;white-space:pre-wrap;word-break:break-word;"></pre>
    <div style="padding:10px 20px;border-top:1px solid #ddd;text-align:right;">
      <button class="button" type="button" onclick="document.getElementById('wbsJsonModal').style.display='none';">Close</button>
    </div>
  </div>
</div>

<script>
var _wbsAjaxUrl = <?php echo json_encode($_SERVER['PHP_SELF']); ?>;
var _wbsToken   = <?php echo json_encode(newToken()); ?>;
var _wbsSyncMaxPages = <?php echo max(1, min(100, (int) ($conf->global->WBS_MANUAL_SYNC_PAGES ?? 5))); ?>;

function wbsOpenSyncModal() {
    document.getElementById('wbsSyncModal').style.display = 'flex';
    document.getElementById('wbsSyncBar').style.width = '0%';
    document.getElementById('wbsSyncList').innerHTML = '';
    document.getElementById('wbsSyncSummary').textContent = '';
    document.getElementById('wbsSyncClose').style.display = 'none';
    wbsRunSyncBatch(1, 0, 0, 0);
}

function wbsRunSyncBatch(page, imported, skipped, errors) {
    document.getElementById('wbsSyncStatus').textContent = 'Processing batch ' + page + ' of at most ' + _wbsSyncMaxPages + '…';
    document.getElementById('wbsSyncBar').style.width = Math.round(((page - 1) / _wbsSyncMaxPages) * 100) + '%';
    var body = 'action=sync_batch&page=' + page + '&token=' + encodeURIComponent(_wbsToken);
    fetch(_wbsAjaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) throw new Error(data.error || 'Sync batch failed');
            var result = data.result || {};
            imported += result.imported || 0;
            skipped += result.skipped || 0;
            errors += result.errors || 0;
            var list = document.getElementById('wbsSyncList');
            (result.items || []).forEach(function(item) {
                var row = document.createElement('div');
                row.style.cssText = 'padding:7px 0;border-bottom:1px solid #eee;';
                var icon = item.status === 'imported' ? '✅' : (item.status === 'skipped' ? '⏭️' : '❌');
                row.textContent = icon + ' #' + item.number + ' — ' + item.message;
                list.appendChild(row);
            });
            list.scrollTop = list.scrollHeight;
            if (result.has_more && page < _wbsSyncMaxPages) {
                wbsRunSyncBatch(page + 1, imported, skipped, errors);
                return;
            }
            wbsFinishSync(imported, skipped, errors);
        })
        .catch(function(error) {
            errors++;
            document.getElementById('wbsSyncList').textContent += '❌ ' + error.message + '\n';
            wbsFinishSync(imported, skipped, errors);
        });
}

function wbsFinishSync(imported, skipped, errors) {
    document.getElementById('wbsSyncBar').style.width = '100%';
    document.getElementById('wbsSyncStatus').textContent = 'Sync completed.';
    document.getElementById('wbsSyncSummary').textContent = '✅ ' + imported + ' imported   ⏭️ ' + skipped + ' skipped   ❌ ' + errors + ' errors';
    document.getElementById('wbsSyncClose').style.display = 'inline-block';
}

function wbsOpenCacheRefreshModal() {
    var modal = document.getElementById('wbsCacheRefreshModal');
    modal.style.display = 'flex';
    document.getElementById('wbsCacheRefreshBar').style.width = '0%';
    document.getElementById('wbsCacheRefreshStatus').textContent = 'Loading synced order list…';
    document.getElementById('wbsCacheRefreshMode').textContent = '';
    document.getElementById('wbsCacheRefreshList').innerHTML = '';
    document.getElementById('wbsCacheRefreshSummary').textContent = '';
    document.getElementById('wbsCacheRefreshClose').style.display = 'none';

    fetch(_wbsAjaxUrl + '?action=full_cache_refresh_list&token=' + encodeURIComponent(_wbsToken))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) throw new Error(data.error || 'Could not load order list');
            var orders = data.orders || [];
            var batchSize = data.batch_size || 1;
            document.getElementById('wbsCacheRefreshMode').textContent = data.germanized_enabled
                ? 'Germanized integration is enabled: Woo order data and Germanized documents will be merged.'
                : 'Germanized integration is disabled: only WooCommerce order data will be requested.';
            if (orders.length === 0) {
                document.getElementById('wbsCacheRefreshStatus').textContent = 'No synced orders found.';
                document.getElementById('wbsCacheRefreshClose').style.display = 'inline-block';
                return;
            }
            wbsRefreshCacheBatch(orders, batchSize, 0, 0, 0);
        })
        .catch(function(error) {
            document.getElementById('wbsCacheRefreshStatus').textContent = 'Error: ' + error.message;
            document.getElementById('wbsCacheRefreshClose').style.display = 'inline-block';
        });
}

function wbsRefreshCacheBatch(orders, batchSize, offset, updated, errors) {
    var total = orders.length;
    if (offset >= total) {
        document.getElementById('wbsCacheRefreshBar').style.width = '100%';
        document.getElementById('wbsCacheRefreshStatus').textContent = 'Full cache refresh completed.';
        document.getElementById('wbsCacheRefreshSummary').textContent = '✅ ' + updated + ' updated   ❌ ' + errors + ' errors';
        document.getElementById('wbsCacheRefreshClose').style.display = 'inline-block';
        return;
    }

    var batch = orders.slice(offset, offset + batchSize);
    var batchNumber = Math.floor(offset / batchSize) + 1;
    var batchCount = Math.ceil(total / batchSize);
    document.getElementById('wbsCacheRefreshBar').style.width = Math.round((offset / total) * 100) + '%';
    document.getElementById('wbsCacheRefreshStatus').textContent = 'Batch ' + batchNumber + ' / ' + batchCount + ' — refreshing orders ' + (offset + 1) + '–' + Math.min(offset + batch.length, total) + ' of ' + total + '…';

    var list = document.getElementById('wbsCacheRefreshList');
    list.innerHTML = '';
    batch.forEach(function(order) {
        var row = document.createElement('div');
        row.id = 'wbs-cache-row-' + order.id;
        row.style.cssText = 'padding:8px 0;border-bottom:1px solid #eee;display:flex;gap:10px;';
        row.innerHTML = '<span id="wbs-cache-icon-' + order.id + '">⏳</span><span>#' + wbsEsc(order.number) + '</span><span id="wbs-cache-note-' + order.id + '" class="opacitymedium"></span>';
        list.appendChild(row);
    });

    var ids = batch.map(function(order) { return order.id; }).join(',');
    var body = 'action=full_cache_refresh_batch&order_ids=' + encodeURIComponent(ids) + '&token=' + encodeURIComponent(_wbsToken);
    fetch(_wbsAjaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) throw new Error(data.error || 'Batch failed');
            var result = data.result || {};
            if (result.error) throw new Error(result.error);
            (result.items || []).forEach(function(item) {
                var icon = document.getElementById('wbs-cache-icon-' + item.id);
                var note = document.getElementById('wbs-cache-note-' + item.id);
                if (icon) icon.textContent = item.ok ? '✅' : '❌';
                if (note) note.textContent = item.ok ? (item.germanized ? 'Woo + Germanized merged' : 'Woo data merged') : (item.message || 'failed');
            });
            wbsRefreshCacheBatch(orders, batchSize, offset + batch.length, updated + (result.updated || 0), errors + (result.errors || 0));
        })
        .catch(function(error) {
            batch.forEach(function(order) {
                var icon = document.getElementById('wbs-cache-icon-' + order.id);
                var note = document.getElementById('wbs-cache-note-' + order.id);
                if (icon) icon.textContent = '❌';
                if (note) note.textContent = error.message;
            });
            wbsRefreshCacheBatch(orders, batchSize, offset + batch.length, updated, errors + batch.length);
        });
}

function wbsOpenJsonModal() {
    var modal = document.getElementById('wbsJsonModal');
    var select = document.getElementById('wbsJsonOrder');
    modal.style.display = 'flex';
    select.innerHTML = '';
    select.disabled = true;
    document.getElementById('wbsJsonStatus').textContent = 'Loading cache…';
    document.getElementById('wbsJsonContent').textContent = '';

    fetch(_wbsAjaxUrl + '?action=cached_order_json&token=' + encodeURIComponent(_wbsToken))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) throw new Error(data.error || 'Could not read cache');
            var orders = data.orders || [];
            if (orders.length === 0) {
                document.getElementById('wbsJsonStatus').textContent = 'No full order JSON is cached yet.';
                document.getElementById('wbsJsonContent').textContent = 'Run/update database checks in Setup, then use Refresh full cache to populate the JSON cache.';
                return;
            }
            orders.forEach(function(order) {
                var option = document.createElement('option');
                option.value = order.id;
                option.textContent = '#' + order.number + (order.invoice ? ' — ' + order.invoice : '') + (order.date_updated ? ' — ' + order.date_updated : '');
                select.appendChild(option);
            });
            select.disabled = false;
            document.getElementById('wbsJsonStatus').textContent = orders.length + ' cached orders';
            wbsShowCachedJson(select.value);
        })
        .catch(function(error) {
            document.getElementById('wbsJsonStatus').textContent = 'Error';
            document.getElementById('wbsJsonContent').textContent = error.message;
        });
}

function wbsShowCachedJson(orderId) {
    if (!orderId) return;
    document.getElementById('wbsJsonContent').textContent = 'Loading order JSON…';
    fetch(_wbsAjaxUrl + '?action=cached_order_json_item&woo_order_id=' + encodeURIComponent(orderId) + '&token=' + encodeURIComponent(_wbsToken))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) throw new Error(data.error || 'Could not read cached JSON');
            var output = data.json || '';
            try {
                output = JSON.stringify(JSON.parse(output), null, 2);
            } catch (e) {
                // Keep the stored value visible even if it is not valid JSON.
            }
            document.getElementById('wbsJsonContent').textContent = output;
        })
        .catch(function(error) {
            document.getElementById('wbsJsonContent').textContent = error.message;
        });
}

function wbsOpenPdfModal() {
    var modal = document.getElementById('wbsPdfModal');
    modal.style.display = 'flex';
    document.getElementById('wbsPdfList').innerHTML = '';
    document.getElementById('wbsPdfBar').style.width = '0%';
    document.getElementById('wbsPdfStatus').textContent = 'Fetching pending list…';
    document.getElementById('wbsPdfDoneBtn').style.display = 'none';
    document.getElementById('wbsPdfSummary').textContent = '';

    fetch(_wbsAjaxUrl + '?action=pending_pdfs&token=' + encodeURIComponent(_wbsToken))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var orders = data.orders || [];
            if (orders.length === 0) {
                document.getElementById('wbsPdfStatus').textContent = 'No pending PDFs found. All invoices are already downloaded, or no PDF URLs are stored yet (run “Refresh full cache” first).';
                document.getElementById('wbsPdfBar').style.width = '100%';
                document.getElementById('wbsPdfDoneBtn').style.display = 'inline-block';
                return;
            }
            document.getElementById('wbsPdfStatus').textContent = '0 / ' + orders.length + ' downloaded';
            orders.forEach(function(o) {
                var row = document.createElement('div');
                row.id = 'wbs-row-' + o.id;
                row.style.cssText = 'padding:7px 0;border-bottom:1px solid #f2f2f2;display:flex;align-items:center;gap:10px;';
                row.innerHTML = '<span id="wbs-icon-' + o.id + '" style="font-size:1.15em;min-width:1.4em;text-align:center;">⏳</span>'
                    + '<span style="flex:1;">#' + wbsEsc(o.number) + (o.invoice ? ' &ndash; ' + wbsEsc(o.invoice) : '') + '</span>'
                    + '<span id="wbs-note-' + o.id + '" style="font-size:0.82em;color:#888;"></span>';
                document.getElementById('wbsPdfList').appendChild(row);
            });
            wbsDownloadNext(orders, 0, 0, 0);
        })
        .catch(function(e) {
            document.getElementById('wbsPdfStatus').textContent = 'Error fetching list: ' + e.message;
            document.getElementById('wbsPdfDoneBtn').style.display = 'inline-block';
        });
}

function wbsDownloadNext(orders, idx, ok, fail) {
    var total = orders.length;
    if (idx >= total) {
        document.getElementById('wbsPdfBar').style.width = '100%';
        document.getElementById('wbsPdfStatus').textContent = 'Done.';
        document.getElementById('wbsPdfDoneBtn').style.display = 'inline-block';
        document.getElementById('wbsPdfSummary').textContent = '✅ ' + ok + ' downloaded   ❌ ' + fail + ' failed';
        return;
    }
    var o = orders[idx];
    var pct = Math.round((idx / total) * 100);
    document.getElementById('wbsPdfBar').style.width = pct + '%';
    document.getElementById('wbsPdfStatus').textContent = (idx + 1) + ' / ' + total + '  —  downloading #' + wbsEsc(o.number) + '…';
    var row = document.getElementById('wbs-row-' + o.id);
    if (row) row.scrollIntoView({block: 'nearest', behavior: 'smooth'});

    var body = 'action=download_pdf_single&woo_order_id=' + encodeURIComponent(o.id) + '&token=' + encodeURIComponent(_wbsToken);
    fetch(_wbsAjaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(r) { return r.json(); })
        .then(function(res) {
            var icon = document.getElementById('wbs-icon-' + o.id);
            var note = document.getElementById('wbs-note-' + o.id);
            if (res.ok) {
                icon.textContent = res.already ? '✔️' : '✅';
                note.textContent = res.already ? 'already saved' : 'saved';
                ok++;
            } else {
                icon.textContent = '❌';
                note.textContent = res.error || 'failed';
                fail++;
            }
            wbsDownloadNext(orders, idx + 1, ok, fail);
        })
        .catch(function() {
            var icon = document.getElementById('wbs-icon-' + o.id);
            icon.textContent = '❌';
            fail++;
            wbsDownloadNext(orders, idx + 1, ok, fail);
        });
}

function wbsEsc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
<?php
llxFooter();
$db->close();
