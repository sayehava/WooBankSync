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
    $force = (GETPOST('force', 'alphanohtml') === '1');
    echo json_encode(array('orders' => $sync->getPendingPdfOrders($force)));
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

// ── AJAX: check and update one configured order batch ──
if ($action === 'difference_batch') {
    header('Content-Type: application/json');
    if (!$user->hasRight('woobanksync', 'run') && !$user->admin) {
        echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit;
    }
    $rawIds = GETPOST('order_ids', 'restricthtml');
    $orderIds = preg_split('/[^0-9]+/', (string) $rawIds, -1, PREG_SPLIT_NO_EMPTY);
    $batchSize = max(1, min(100, (int) ($conf->global->WBS_DIFF_BATCH_SIZE ?? 10)));
    $orderIds = array_slice($orderIds, 0, $batchSize);
    if (empty($orderIds)) {
        echo json_encode(array('ok' => false, 'error' => 'No order IDs supplied')); exit;
    }
    @set_time_limit(300);
    $sync = new WooBankSync($db, $conf, $langs);
    echo json_encode(array('ok' => true, 'result' => $sync->resyncDifferences($orderIds)));
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
    $force = (GETPOST('force', 'alphanohtml') === '1');
    if (!$force && !empty($row->pdf_ecm_filepath) && $sync->isInvoicePdfStored((string) $row->pdf_ecm_filepath)) {
        echo json_encode(array('ok' => true, 'already' => true, 'filepath' => $row->pdf_ecm_filepath, 'log' => array())); exit;
    }
    if (!$force && !empty($row->pdf_ecm_filepath)) {
        $sync->updateCacheEcmPath((string) $row->woo_order_id, '');
    }

    @set_time_limit(120);
    $ecmPath = $sync->downloadInvoicePdfPublic(
        (string) $row->woo_order_id,
        (string) $row->woo_order_number,
        (string) ($row->woo_invoice_number ?? ''),
        (string) $row->woo_invoice_pdf_url,
        $force
    );

    if ($ecmPath !== '') {
        $sync->updateCacheEcmPath((string) $row->woo_order_id, $ecmPath);
        echo json_encode(array('ok' => true, 'filepath' => $ecmPath, 'log' => $sync->pdfLog));
    } else {
        echo json_encode(array('ok' => false, 'error' => 'Download failed — check PDF URL and folder mapping in Setup', 'log' => $sync->pdfLog));
    }
    exit;
}

llxHeader('', $langs->trans('WooBankSync'));
?>
<?php echo load_fiche_titre($langs->trans('WooBankSync'), '<a href="admin/setup.php">Setup</a>', 'bank'); ?>
<?php

?>
<button class="button" type="button" onclick="wbsOpenSyncModal()" style="margin-right:10px;">Sync now</button>
<button class="button" type="button" onclick="wbsOpenDifferenceModal()" style="margin-right:10px;" title="Checks synced orders in configured batches and updates changed reconciliation fields.">Check &amp; update differences</button>
<button class="button" type="button" onclick="wbsOpenPdfModal()" title="Download missing invoice PDFs using URLs already stored locally — no WooCommerce API call">&#128196; Download past invoice PDFs</button>
 <label title="Force re-download all PDFs including those already saved (useful when download failed silently)" style="cursor:pointer;margin-left:4px;font-size:0.9em;vertical-align:middle;"><input type="checkbox" id="wbsForceDownload" style="vertical-align:middle;margin-right:3px;">Force re-download all</label>
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

function wbsOpenDifferenceModal() {
    document.getElementById('wbsDifferenceModal').style.display = 'flex';
    document.getElementById('wbsDifferenceBar').style.width = '0%';
    document.getElementById('wbsDifferenceList').innerHTML = '';
    document.getElementById('wbsDifferenceSummary').textContent = '';
    document.getElementById('wbsDifferenceClose').style.display = 'none';
    document.getElementById('wbsDifferenceStatus').textContent = 'Loading synced order list…';
    fetch(_wbsAjaxUrl + '?action=difference_list&token=' + encodeURIComponent(_wbsToken))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) throw new Error(data.error || 'Could not load orders');
            wbsRunDifferenceBatch(data.orders || [], data.batch_size || 10, 0, 0, 0, 0);
        })
        .catch(function(error) {
            document.getElementById('wbsDifferenceStatus').textContent = 'Error: ' + error.message;
            document.getElementById('wbsDifferenceClose').style.display = 'inline-block';
        });
}

function wbsRunDifferenceBatch(orders, batchSize, offset, updated, unchanged, errors) {
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
    var body = 'action=difference_batch&order_ids=' + encodeURIComponent(ids) + '&token=' + encodeURIComponent(_wbsToken);
    fetch(_wbsAjaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(r) { return r.json(); })
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
            wbsRunDifferenceBatch(orders, batchSize, offset + batch.length, updated + (result.updated || 0), unchanged + (result.unchanged || 0), errors + (result.errors || 0));
        })
        .catch(function(error) {
            errors += batch.length;
            document.getElementById('wbsDifferenceList').textContent += '❌ ' + error.message + '\n';
            wbsRunDifferenceBatch(orders, batchSize, offset + batch.length, updated, unchanged, errors);
        });
}

function wbsOpenPdfModal() {
    var force = document.getElementById('wbsForceDownload').checked ? '1' : '0';
    var modal = document.getElementById('wbsPdfModal');
    modal.style.display = 'flex';
    document.getElementById('wbsPdfList').innerHTML = '';
    document.getElementById('wbsPdfBar').style.width = '0%';
    document.getElementById('wbsPdfStatus').textContent = 'Fetching pending list…';
    document.getElementById('wbsPdfDoneBtn').style.display = 'none';
    document.getElementById('wbsPdfSummary').textContent = '';

    fetch(_wbsAjaxUrl + '?action=pending_pdfs&force=' + force + '&token=' + encodeURIComponent(_wbsToken))
        .then(function(r) { return r.json(); })
        .then(function(data) {
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
    fetch(_wbsAjaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(r) { return r.json(); })
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
        .catch(function() {
            var icon = document.getElementById('wbs-icon-' + o.id);
            icon.textContent = '❌';
            fail++;
            wbsDownloadNext(orders, idx + 1, ok, fail, force);
        });
}

function wbsEsc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
<?php
llxFooter();
$db->close();
