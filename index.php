<?php
// AJAX responses must contain JSON only. Buffer from before the Dolibarr
// bootstrap so PHP notices or other incidental output cannot corrupt them.
$wbsAjaxActions = array('pending_pdfs', 'sync_batch', 'sync_connector_batch', 'difference_list', 'difference_batch', 'download_pdf_single');
$wbsRawAction = isset($_POST['action']) ? (string) $_POST['action'] : (isset($_GET['action']) ? (string) $_GET['action'] : '');
$wbsAjaxBufferBaseLevel = ob_get_level();
$wbsIsAjaxRequest = in_array($wbsRawAction, $wbsAjaxActions, true);
$wbsJsonResponseSent = false;
if ($wbsIsAjaxRequest) ob_start();

function wbs_json_response($payload, $httpStatus = 200)
{
    global $wbsAjaxBufferBaseLevel, $wbsJsonResponseSent;

    while (ob_get_level() > $wbsAjaxBufferBaseLevel) {
        if (!@ob_end_clean()) break;
    }
    $wbsJsonResponseSent = true;
    if (!headers_sent()) {
        http_response_code((int) $httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    $flags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
    $json = json_encode($payload, $flags);
    if ($json === false) {
        http_response_code(500);
        $json = '{"ok":false,"error":"Could not encode the server response as JSON."}';
    }
    echo $json;
    exit;
}

if ($wbsIsAjaxRequest) {
    register_shutdown_function(function () {
        global $wbsAjaxBufferBaseLevel, $wbsJsonResponseSent;
        if ($wbsJsonResponseSent) return;
        $error = error_get_last();

        while (ob_get_level() > $wbsAjaxBufferBaseLevel) {
            if (!@ob_end_clean()) break;
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $isFatal = $error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true);
        $message = $isFatal && isset($error['message'])
            ? 'Server error: ' . (string) $error['message']
            : 'The request ended before a response was produced. Reload the page and sign in again, then retry.';
        $flags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
        echo json_encode(array('ok' => false, 'error' => $message), $flags);
    });
}

$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__ . '/class/woobanksync.class.php';

$langs->loadLangs(array('banks', 'woobanksync@woobanksync'));
if (!$user->hasRight('woobanksync', 'read') && !$user->admin) {
    if ($wbsIsAjaxRequest) wbs_json_response(array('ok' => false, 'error' => 'Access denied. Reload the page and sign in again.'), 403);
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($wbsIsAjaxRequest) {
    try {
        // Dolibarr removes action/POST values when a CSRF token has expired.
        // Keep that protection intact and turn the otherwise-rendered HTML
        // page into an actionable JSON error for the sync dialog.
        if (GETPOST('errorcode', 'alpha') === 'InvalidToken') {
            wbs_json_response(array('ok' => false, 'error' => 'Your security token expired. Reload the page, then click Sync now again.'), 403);
        }

        // ── AJAX: return list of orders that have a PDF URL but no local ECM file ──
        if ($action === 'pending_pdfs') {
            $sync = new DolliCommerceHub($db, $conf, $langs);
            $force = (GETPOST('force', 'alphanohtml') === '1');
            wbs_json_response(array('ok' => true, 'orders' => $sync->getPendingPdfOrders($force)));
        }

        if (!$user->hasRight('woobanksync', 'run') && !$user->admin) {
            wbs_json_response(array('ok' => false, 'error' => 'Access denied. Reload the page and sign in again.'), 403);
        }

        // ── AJAX: synchronize one configured page of WooCommerce orders ──
        if ($action === 'sync_batch') {
            $page = max(1, (int) GETPOST('page', 'int'));
            $batchSize = max(1, min(100, (int) ($conf->global->WBS_SYNC_BATCH_SIZE ?? 10)));
            @set_time_limit(300);
            $sync = new DolliCommerceHub($db, $conf, $langs);
            wbs_json_response(array(
                'ok' => true,
                'page' => $page,
                'batch_size' => $batchSize,
                'result' => $sync->syncBatch($page, $batchSize),
            ));
        }

        if ($action === 'sync_connector_batch') {
            $connector = strtolower((string) GETPOST('connector', 'alpha'));
            $cursor = (string) GETPOST('cursor', 'restricthtml');
            $batchSize = max(1, min(100, (int) ($conf->global->WBS_SYNC_BATCH_SIZE ?? 10)));
            @set_time_limit(300);
            $sync = new DolliCommerceHub($db, $conf, $langs);
            list($schemaOk, $schemaMessage) = $sync->runDatabaseChecks();
            if (!$schemaOk) wbs_json_response(array('ok' => false, 'error' => $schemaMessage), 500);
            wbs_json_response(array(
                'ok' => true,
                'connector' => $connector,
                'result' => $sync->syncConnectorBatch($connector, $cursor, $batchSize),
            ));
        }

        // ── AJAX: list orders eligible for difference checking ──
        if ($action === 'difference_list') {
            $sync = new DolliCommerceHub($db, $conf, $langs);
            wbs_json_response(array(
                'ok' => true,
                'orders' => $sync->getDifferenceCheckOrders(),
                'batch_size' => max(1, min(100, (int) ($conf->global->WBS_DIFF_BATCH_SIZE ?? 10))),
            ));
        }

        // ── AJAX: check and update one configured order batch ──
        if ($action === 'difference_batch') {
            $rawIds = GETPOST('order_ids', 'restricthtml');
            $orderIds = preg_split('/[^0-9]+/', (string) $rawIds, -1, PREG_SPLIT_NO_EMPTY);
            $batchSize = max(1, min(100, (int) ($conf->global->WBS_DIFF_BATCH_SIZE ?? 10)));
            $orderIds = array_slice($orderIds, 0, $batchSize);
            if (empty($orderIds)) wbs_json_response(array('ok' => false, 'error' => 'No order IDs supplied'), 400);
            $forceDiff = (GETPOST('force', 'alphanohtml') === '1');
            @set_time_limit(300);
            $sync = new DolliCommerceHub($db, $conf, $langs);
            wbs_json_response(array('ok' => true, 'result' => $sync->resyncDifferences($orderIds, $forceDiff)));
        }

        // ── AJAX: download one PDF by order ID ──
        if ($action === 'download_pdf_single') {
            $wooOrderId = GETPOST('woo_order_id', 'alphanohtml');
            if (empty($wooOrderId)) wbs_json_response(array('ok' => false, 'error' => 'Missing order ID'), 400);
            $force = (GETPOST('force', 'alphanohtml') === '1');
            $sync = new DolliCommerceHub($db, $conf, $langs);

            // Fetch order info from log (always reliable — no cache dependency).
            $sqlLog = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'woobanksync_log'
                . ' WHERE entity=' . (int) $conf->entity . " AND connector='woocommerce' AND woo_order_id='" . $db->escape($wooOrderId) . "' LIMIT 1";
            $rLog = $db->query($sqlLog);
            $logRow = ($rLog) ? $db->fetch_object($rLog) : null;
            if (!$logRow) wbs_json_response(array('ok' => false, 'error' => 'Order not in sync log', 'log' => array()), 404);

            @set_time_limit(120);
            $result = $sync->downloadAndSavePdf(
                (string) $logRow->woo_order_id,
                (string) $logRow->woo_order_number,
                (string) ($logRow->woo_invoice_number ?? ''),
                '',
                $force
            );
            if ($result['ok']) {
                wbs_json_response(array('ok' => true, 'already' => !empty($result['already']), 'filepath' => $result['filepath'], 'log' => $result['log']));
            }
            wbs_json_response(array('ok' => false, 'error' => 'Download failed — see log for details', 'log' => $result['log']), 502);
        }

        wbs_json_response(array('ok' => false, 'error' => 'Unknown AJAX action.'), 400);
    } catch (Throwable $e) {
        wbs_json_response(array('ok' => false, 'error' => 'Server error: ' . $e->getMessage()), 500);
    }
}

// Clicking a stale pre-2.2 Bank/Cash link also repairs it immediately.
$_dchMenuMigration = new DolliCommerceHub($db, $conf, $langs);
list($_dchMenuOk, $_dchMenuMessage) = $_dchMenuMigration->cleanupLegacyBankMenu();
if (!$_dchMenuOk) setEventMessages($_dchMenuMessage, null, 'errors');

require_once __DIR__ . '/helpers/WbsIntegrationManager.php';
$_wbsManager = new WbsIntegrationManager($db, $conf);
$hasPdfIntegration = !empty($_wbsManager->getDetected());

llxHeader('', 'Dolli Commerce Hub dashboard');
?>
<?php echo load_fiche_titre('Dolli Commerce Hub dashboard', '<a href="admin/setup.php?mainmenu=woobanksync">Configuration</a>', 'generic'); ?>

<?php if (!isset($conf->global->DCH_WOOCOMMERCE_ENABLED) || !empty($conf->global->DCH_WOOCOMMERCE_ENABLED)): ?><button class="button" type="button" onclick="wbsOpenSyncModal('woocommerce','WooCommerce')" style="margin-right:6px;">Sync WooCommerce</button><?php endif; ?>
<?php if (!empty($conf->global->DCH_AMAZON_ENABLED)): ?><button class="button" type="button" onclick="wbsOpenSyncModal('amazon','Amazon')" style="margin-right:6px;">Sync Amazon</button><?php endif; ?>
<?php if (!empty($conf->global->DCH_SUMUP_ENABLED)): ?><button class="button" type="button" onclick="wbsOpenSyncModal('sumup','SumUp')" style="margin-right:6px;">Sync SumUp</button><?php endif; ?>
<a class="button" href="<?php echo DOL_URL_ROOT; ?>/custom/woobanksync/reports.php?mainmenu=woobanksync" style="margin-right:6px;">Sales analytics</a>
<?php if (!isset($conf->global->DCH_WOOCOMMERCE_ENABLED) || !empty($conf->global->DCH_WOOCOMMERCE_ENABLED)): ?>
<button class="button" type="button" onclick="wbsOpenDifferenceModal()" style="margin-right:4px;" title="Checks synced orders in configured batches and updates changed reconciliation fields.">Check &amp; update differences</button>
<label title="Force-update all bank entries even if no change is detected (re-writes labels and amounts)" style="cursor:pointer;margin-right:10px;font-size:0.9em;vertical-align:middle;"><input type="checkbox" id="wbsForceDiff" style="vertical-align:middle;margin-right:3px;">Force update all</label>
<?php endif; ?>
<?php if ($hasPdfIntegration): ?><button class="button" type="button" onclick="wbsOpenPdfModal()" title="Download missing invoice PDFs via StoreaBill API — no cache dependency">&#128196; Download past invoice PDFs</button>
 <label title="Force re-download all PDFs including those already saved (useful when download failed silently)" style="cursor:pointer;margin-left:4px;font-size:0.9em;vertical-align:middle;"><input type="checkbox" id="wbsForceDownload" style="vertical-align:middle;margin-right:3px;">Force re-download all</label><?php endif; ?>
<br>
<?php

if (!empty($conf->global->WBS_LAST_SYNC) || !empty($conf->global->DCH_AMAZON_LAST_SYNC) || !empty($conf->global->DCH_SUMUP_LAST_SYNC)) {
?>
<div class="opacitymedium" style="margin-top:6px;">
<?php if (!empty($conf->global->WBS_LAST_SYNC)): ?>WooCommerce: <?php echo dol_print_date((int) $conf->global->WBS_LAST_SYNC, 'dayhour'); ?>&nbsp;&nbsp;<?php endif; ?>
<?php if (!empty($conf->global->DCH_AMAZON_LAST_SYNC)): ?>Amazon: <?php echo dol_print_date((int) $conf->global->DCH_AMAZON_LAST_SYNC, 'dayhour'); ?>&nbsp;&nbsp;<?php endif; ?>
<?php if (!empty($conf->global->DCH_SUMUP_LAST_SYNC)): ?>SumUp: <?php echo dol_print_date((int) $conf->global->DCH_SUMUP_LAST_SYNC, 'dayhour'); ?><?php endif; ?>
</div>
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
<tr class="liste_titre"><th>Date sync</th><th>Channel</th><th>Order</th><th>Invoice</th><?php if ($hasPdfIntegration): ?><th>PDF</th><?php endif; ?><th>Payment</th><th class="right">Gross</th><th class="right">Fee</th><th>Fee source</th><th class="right">Payout</th><th>Status</th><th>Message</th></tr>
<?php
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        if ($hasPdfIntegration) {
            if (!empty($obj->pdf_ecm_filepath)) {
                $pdfLink = '<a href="' . DOL_URL_ROOT . '/document.php?modulepart=ecm&file=' . urlencode($obj->pdf_ecm_filepath) . '" target="_blank" title="Downloaded — stored in Dolibarr ECM">&#128196;&nbsp;PDF</a>';
            } elseif (!empty($obj->woo_invoice_pdf_url)) {
                $pdfLink = '<a href="' . dol_escape_htmltag($obj->woo_invoice_pdf_url) . '" target="_blank" title="Not yet downloaded — opens directly from WooCommerce">&#8599;&nbsp;PDF</a>';
            } else {
                $pdfLink = '';
            }
        }
?>
<tr class="oddeven">
<td><?php echo dol_print_date($db->jdate($obj->date_sync), 'dayhour'); ?></td>
<td><?php echo dol_escape_htmltag(ucfirst($obj->connector ?? 'woocommerce')); ?></td>
<td><?php echo dol_escape_htmltag($obj->woo_order_number); ?></td>
<td><?php echo dol_escape_htmltag($obj->woo_invoice_number ?? ''); ?></td>
<?php if ($hasPdfIntegration): ?><td><?php echo $pdfLink; ?></td><?php endif; ?>
<td><?php echo dol_escape_htmltag($obj->payment_method); ?></td>
<td class="right"><?php echo price($obj->gross_amount); ?> <?php echo dol_escape_htmltag($obj->currency); ?></td>
<td class="right"><?php echo price($obj->fee_amount); ?> <?php echo dol_escape_htmltag($obj->currency); ?></td>
<td><?php echo dol_escape_htmltag($obj->fee_source ?? ''); ?></td>
<td class="right"><?php echo price($obj->payout_amount ?? 0); ?> <?php echo dol_escape_htmltag($obj->currency); ?></td>
<td><?php echo dol_escape_htmltag($obj->sync_status); ?></td>
<td><?php echo dol_escape_htmltag($obj->sync_message); ?></td>
</tr>
<?php
    }
} else {
?>
<tr><td colspan="12"><?php echo dol_escape_htmltag($db->lasterror()); ?></td></tr>
<?php
}
?>
</table></div>

<?php if ($hasPdfIntegration): ?>
<!-- PDF download modal — only rendered when a PDF-capable integration is detected -->
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
<?php endif; ?>

<style>
@keyframes wbsSyncActive { from { background-position: 100% 0; } to { background-position: -100% 0; } }
#wbsSyncBar { background:#28a745; }
#wbsSyncBar.wbs-running { background:linear-gradient(90deg,#28a745 0%,#75d486 50%,#28a745 100%);background-size:200% 100%;animation:wbsSyncActive 1.2s linear infinite; }
</style>
<div id="wbsSyncModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10003;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:84%;max-width:900px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.28);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;"><strong id="wbsSyncTitle">&#8635; Sync orders</strong></div>
    <div style="padding:14px 20px 10px;">
      <div style="background:#e8e8e8;border-radius:4px;height:10px;overflow:hidden;"><div id="wbsSyncBar" style="width:0%;height:10px;transition:width 0.4s ease;"></div></div>
      <div id="wbsSyncStatus" style="margin-top:7px;color:#555;">Preparing&hellip;</div>
    </div>
    <div id="wbsSyncList" style="flex:1;overflow-y:auto;padding:4px 20px 12px;font-size:0.9em;"></div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;"><button id="wbsSyncClose" class="button" style="display:none;" onclick="location.reload();">Close and refresh</button> <span id="wbsSyncSummary"></span></div>
  </div>
</div>

<div id="wbsDifferenceModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:10004;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:84%;max-width:900px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,0.28);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;"><strong>&#8635; Check &amp; update differences</strong></div>
    <div style="padding:14px 20px 10px;">
      <div style="background:#e8e8e8;border-radius:4px;height:10px;overflow:hidden;"><div id="wbsDifferenceBar" style="width:0%;height:10px;background:#f0ad4e;transition:width 0.4s ease;"></div></div>
      <div id="wbsDifferenceStatus" style="margin-top:7px;color:#555;">Preparing&hellip;</div>
    </div>
    <div id="wbsDifferenceList" style="flex:1;overflow-y:auto;padding:4px 20px 12px;font-size:0.9em;"></div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;"><button id="wbsDifferenceClose" class="button" style="display:none;" onclick="location.reload();">Close and refresh</button> <span id="wbsDifferenceSummary"></span></div>
  </div>
</div>

<script>
// The current browser URL is already known to be valid and keeps working when
// Dolibarr is installed behind a proxy or in a non-standard URL directory.
var _wbsAjaxUrl = window.location.href.split('#')[0].split('?')[0];
var _wbsToken   = <?php echo json_encode(newToken()); ?>;
var _wbsSyncTotalPages = 0;
var _wbsSyncConnector = 'woocommerce';
var _wbsSyncBatchNumber = 1;

function wbsFetchJson(url, options) {
    options = options || {};
    options.credentials = 'same-origin';
    return fetch(url, options).then(function(response) {
        return response.text().then(function(text) {
            var data;
            try {
                data = JSON.parse(text);
            } catch (parseError) {
                var status = 'HTTP ' + response.status;
                var trimmed = String(text || '').trim();
                if (!trimmed) {
                    throw new Error('The server returned an empty response (' + status + '). Check the Dolibarr/PHP error log.');
                }
                var detail = trimmed
                    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
                    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
                    .replace(/<[^>]+>/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .slice(0, 300);
                throw new Error('The server returned a non-JSON response (' + status + '). ' + detail);
            }
            if (!data || typeof data !== 'object') {
                throw new Error('The server returned an invalid JSON response (HTTP ' + response.status + ').');
            }
            if (!response.ok && data.ok !== false) {
                throw new Error(data.error || 'Request failed with HTTP ' + response.status + '.');
            }
            return data;
        });
    });
}

function wbsOpenSyncModal(connector, label) {
    var bar = document.getElementById('wbsSyncBar');
    _wbsSyncConnector = connector || 'woocommerce';
    _wbsSyncBatchNumber = 1;
    document.getElementById('wbsSyncModal').style.display = 'flex';
    document.getElementById('wbsSyncTitle').textContent = '↻ Sync ' + (label || connector || 'channel') + ' orders';
    _wbsSyncTotalPages = 0;
    bar.style.width = '3%';
    bar.style.background = '';
    bar.classList.add('wbs-running');
    document.getElementById('wbsSyncList').innerHTML = '';
    document.getElementById('wbsSyncSummary').textContent = '';
    document.getElementById('wbsSyncClose').style.display = 'none';
    wbsRunSyncBatch(_wbsSyncConnector === 'woocommerce' ? '1' : '', 0, 0, 0);
}

function wbsRunSyncBatch(cursor, imported, skipped, errors) {
    document.getElementById('wbsSyncStatus').textContent = 'Processing batch ' + _wbsSyncBatchNumber + (_wbsSyncTotalPages > 0 ? ' of ' + _wbsSyncTotalPages : '') + '…';
    var body = 'action=sync_connector_batch&connector=' + encodeURIComponent(_wbsSyncConnector) + '&cursor=' + encodeURIComponent(cursor || '') + '&token=' + encodeURIComponent(_wbsToken);
    wbsFetchJson(_wbsAjaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(data) {
            if (!data.ok) throw new Error(data.error || 'Sync batch failed');
            var result = data.result || {};
            imported += result.imported || 0;
            skipped += result.skipped || 0;
            errors += result.errors || 0;
            if ((result.total_pages || 0) > 0) _wbsSyncTotalPages = result.total_pages;
            var progress;
            if (_wbsSyncTotalPages > 0) {
                progress = Math.min(100, Math.round((_wbsSyncBatchNumber / _wbsSyncTotalPages) * 100));
            } else {
                // Cursor APIs do not always reveal a total. Keep visible,
                // measured progress while the animated bar shows activity.
                progress = Math.min(90, Math.round(100 * (1 - Math.pow(0.72, _wbsSyncBatchNumber))));
            }
            document.getElementById('wbsSyncBar').style.width = progress + '%';
            var list = document.getElementById('wbsSyncList');
            (result.items || []).forEach(function(item) {
                var row = document.createElement('div');
                row.style.cssText = 'padding:7px 0;border-bottom:1px solid #eee;';
                var icon = item.status === 'imported' ? '✅' : (item.status === 'skipped' ? '⏭️' : '❌');
                row.textContent = icon + ' #' + item.number + ' — ' + item.message;
                list.appendChild(row);
            });
            if (!(result.items || []).length) {
                (result.messages || []).forEach(function(message) {
                    var row = document.createElement('div');
                    row.style.cssText = 'padding:7px 0;border-bottom:1px solid #eee;';
                    row.textContent = '❌ ' + message;
                    list.appendChild(row);
                });
            }
            list.scrollTop = list.scrollHeight;
            var hasMore = _wbsSyncTotalPages > 0 ? _wbsSyncBatchNumber < _wbsSyncTotalPages : result.has_more;
            if (hasMore && _wbsSyncBatchNumber < 500) {
                _wbsSyncBatchNumber++;
                wbsRunSyncBatch(result.next_cursor || String(_wbsSyncBatchNumber), imported, skipped, errors);
                return;
            }
            wbsFinishSync(imported, skipped, errors);
        })
        .catch(function(error) {
            errors++;
            document.getElementById('wbsSyncList').textContent += '❌ ' + error.message + '\n';
            wbsFinishSync(imported, skipped, errors, true);
        });
}

function wbsFinishSync(imported, skipped, errors, failed) {
    var bar = document.getElementById('wbsSyncBar');
    bar.classList.remove('wbs-running');
    bar.style.width = '100%';
    bar.style.background = errors > 0 ? '#dc3545' : '#28a745';
    document.getElementById('wbsSyncStatus').textContent = failed ? 'Sync failed.' : (errors > 0 ? 'Sync finished with errors.' : 'Sync completed.');
    document.getElementById('wbsSyncSummary').textContent = '✅ ' + imported + ' imported   ⏭️ ' + skipped + ' skipped   ❌ ' + errors + ' errors';
    document.getElementById('wbsSyncClose').style.display = 'inline-block';
}

function wbsOpenDifferenceModal() {
    var force = document.getElementById('wbsForceDiff').checked ? '1' : '0';
    document.getElementById('wbsDifferenceModal').style.display = 'flex';
    document.getElementById('wbsDifferenceBar').style.width = '0%';
    document.getElementById('wbsDifferenceList').innerHTML = '';
    document.getElementById('wbsDifferenceSummary').textContent = '';
    document.getElementById('wbsDifferenceClose').style.display = 'none';
    document.getElementById('wbsDifferenceStatus').textContent = 'Loading synced order list' + (force === '1' ? ' (force mode)' : '') + '…';
    wbsFetchJson(_wbsAjaxUrl + '?action=difference_list&token=' + encodeURIComponent(_wbsToken))
        .then(function(data) {
            if (!data.ok) throw new Error(data.error || 'Could not load orders');
            wbsRunDifferenceBatch(data.orders || [], data.batch_size || 10, 0, 0, 0, 0, force);
        })
        .catch(function(error) {
            document.getElementById('wbsDifferenceStatus').textContent = 'Error: ' + error.message;
            document.getElementById('wbsDifferenceClose').style.display = 'inline-block';
        });
}

function wbsRunDifferenceBatch(orders, batchSize, offset, updated, unchanged, errors, force) {
    if (offset >= orders.length) {
        document.getElementById('wbsDifferenceBar').style.width = '100%';
        document.getElementById('wbsDifferenceStatus').textContent = 'Difference check completed.';
        document.getElementById('wbsDifferenceSummary').textContent = '✅ ' + updated + ' updated   ➖ ' + unchanged + ' unchanged   ❌ ' + errors + ' errors';
        document.getElementById('wbsDifferenceClose').style.display = 'inline-block';
        return;
    }
    var batch = orders.slice(offset, offset + batchSize);
    document.getElementById('wbsDifferenceBar').style.width = Math.round((offset / orders.length) * 100) + '%';
    document.getElementById('wbsDifferenceStatus').textContent = 'Checking ' + (offset + 1) + '–' + Math.min(offset + batch.length, orders.length) + ' of ' + orders.length + '…';
    var ids = batch.map(function(order) { return order.id; }).join(',');
    var body = 'action=difference_batch&order_ids=' + encodeURIComponent(ids) + '&force=' + (force || '0') + '&token=' + encodeURIComponent(_wbsToken);
    wbsFetchJson(_wbsAjaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(data) {
            if (!data.ok) throw new Error(data.error || 'Difference batch failed');
            var result = data.result || {};
            var list = document.getElementById('wbsDifferenceList');
            (result.items || []).forEach(function(item) {
                var row = document.createElement('div');
                row.style.cssText = 'padding:7px 0;border-bottom:1px solid #eee;';
                var icon = item.status === 'updated' ? '✅' : (item.status === 'unchanged' ? '➖' : '❌');
                row.textContent = icon + ' #' + item.number + (item.changes ? ' — ' + item.changes : '');
                list.appendChild(row);
            });
            list.scrollTop = list.scrollHeight;
            wbsRunDifferenceBatch(orders, batchSize, offset + batch.length, updated + (result.updated || 0), unchanged + (result.unchanged || 0), errors + (result.errors || 0), force);
        })
        .catch(function(error) {
            errors += batch.length;
            document.getElementById('wbsDifferenceList').textContent += '❌ ' + error.message + '\n';
            wbsRunDifferenceBatch(orders, batchSize, offset + batch.length, updated, unchanged, errors, force);
        });
}

<?php if ($hasPdfIntegration): ?>
function wbsOpenPdfModal() {
    var force = document.getElementById('wbsForceDownload').checked ? '1' : '0';
    var modal = document.getElementById('wbsPdfModal');
    modal.style.display = 'flex';
    document.getElementById('wbsPdfList').innerHTML = '';
    document.getElementById('wbsPdfBar').style.width = '0%';
    document.getElementById('wbsPdfStatus').textContent = 'Fetching pending list…';
    document.getElementById('wbsPdfDoneBtn').style.display = 'none';
    document.getElementById('wbsPdfSummary').textContent = '';

    wbsFetchJson(_wbsAjaxUrl + '?action=pending_pdfs&force=' + force + '&token=' + encodeURIComponent(_wbsToken))
        .then(function(data) {
            if (!data.ok) throw new Error(data.error || 'Could not load pending PDFs');
            var orders = data.orders || [];
            if (orders.length === 0) {
                document.getElementById('wbsPdfStatus').textContent = 'No pending PDFs found. All invoices are already downloaded, or no PDF URLs are stored yet (run “Refresh full cache” first).';
                document.getElementById('wbsPdfBar').style.width = '100%';
                document.getElementById('wbsPdfDoneBtn').style.display = 'inline-block';
                return;
            }
            document.getElementById('wbsPdfStatus').textContent = '0 / ' + orders.length + (force === '1' ? ' (force mode)' : '') + ' — downloading…';
            orders.forEach(function(o) {
                var row = document.createElement('div');
                row.id = 'wbs-row-' + o.id;
                row.style.cssText = 'padding:7px 0;border-bottom:1px solid #f2f2f2;display:flex;flex-direction:column;gap:2px;';
                row.innerHTML = '<div style="display:flex;align-items:center;gap:10px;"><span id="wbs-icon-' + o.id + '" style="font-size:1.15em;min-width:1.4em;text-align:center;">⏳</span>'
                    + '<span style="flex:1;">#' + wbsEsc(o.number) + (o.invoice ? ' &ndash; ' + wbsEsc(o.invoice) : '') + '</span></div>'
                    + '<pre id="wbs-note-' + o.id + '" style="font-size:0.75em;color:#888;margin:0 0 0 1.8em;white-space:pre-wrap;word-break:break-all;"></pre>';
                document.getElementById('wbsPdfList').appendChild(row);
            });
            wbsDownloadNext(orders, 0, 0, 0, force);
        })
        .catch(function(e) {
            document.getElementById('wbsPdfStatus').textContent = 'Error fetching list: ' + e.message;
            document.getElementById('wbsPdfDoneBtn').style.display = 'inline-block';
        });
}

function wbsDownloadNext(orders, idx, ok, fail, force) {
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

    var body = 'action=download_pdf_single&woo_order_id=' + encodeURIComponent(o.id) + '&force=' + (force || '0') + '&token=' + encodeURIComponent(_wbsToken);
    wbsFetchJson(_wbsAjaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(res) {
            var icon = document.getElementById('wbs-icon-' + o.id);
            var note = document.getElementById('wbs-note-' + o.id);
            var logStr = (res.log && res.log.length) ? '\n' + res.log.join('\n') : '';
            if (res.ok) {
                icon.textContent = res.already ? '✔️' : '✅';
                note.textContent = (res.already ? 'already saved' : 'saved') + logStr;
                ok++;
            } else {
                icon.textContent = '❌';
                note.textContent = (res.error || 'failed') + logStr;
                fail++;
            }
            wbsDownloadNext(orders, idx + 1, ok, fail, force);
        })
        .catch(function(error) {
            var icon = document.getElementById('wbs-icon-' + o.id);
            var note = document.getElementById('wbs-note-' + o.id);
            if (icon) icon.textContent = '❌';
            if (note) note.textContent = error.message || 'Request failed';
            fail++;
            wbsDownloadNext(orders, idx + 1, ok, fail, force);
        });
}
<?php endif; ?>

function wbsEsc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
<?php
llxFooter();
$db->close();
