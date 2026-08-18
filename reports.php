<?php
$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res) die('Include of main fails');

require_once __DIR__ . '/class/fahsalesreport.class.php';
require_once __DIR__ . '/class/fahinventory.class.php';

$langs->loadLangs(array('financeautomationhub@financeautomationhub'));
if (!$user->hasRight('financeautomationhub', 'read') && !$user->admin) accessforbidden();

$inventory = new FahInventoryManager($db, $conf);
list($schemaOk, $schemaMessage) = $inventory->ensureSchema();
if (!$schemaOk) accessforbidden('Sales analytics database is not ready: ' . $schemaMessage);

$report = new FahSalesReport($db, $conf);
$filters = $report->normalizeFilters(array(
    'period' => GETPOST('period', 'alpha'),
    'year' => GETPOST('year', 'int'),
    'month' => GETPOST('month', 'int'),
    'from' => GETPOST('from', 'restricthtml'),
    'to' => GETPOST('to', 'restricthtml'),
    'connector' => GETPOST('connector', 'alpha'),
    'warehouse_id' => GETPOST('warehouse_id', 'int'),
));
$warehouses = $inventory->getWarehouses();
$summary = $report->getSummary($filters);
$products = $report->getProductRows($filters);
$inventoryProducts = $report->getInventoryProductRows($filters);
$warehouseRows = $report->getWarehouseRows($filters);
$financialRows = $report->getFinancialRows($filters);
$totals = $report->totals($summary);

if (GETPOST('action', 'alpha') === 'export') {
    $filename = 'finance-automation-sales-' . preg_replace('/[^0-9A-Za-z_-]+/', '-', strtolower($report->periodLabel($filters))) . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    $csv = function ($value) {
        $value = (string) $value;
        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    };
    fputcsv($out, array('Finance Automation Hub sales report', $report->periodLabel($filters)), ';');
    fputcsv($out, array('Platform filter', $filters['connector'] ?: 'All platforms', 'Warehouse ID filter', $filters['warehouse_id'] ?: 'All warehouses'), ';');
    fputcsv($out, array(), ';');
    fputcsv($out, array('Platform', 'Orders', 'Single items', 'Bundle items', 'Total sold items', 'Underlying inventory pieces'), ';');
    foreach ($summary as $row) fputcsv($out, array($csv(ucfirst($row['connector'])), $row['orders'], $row['single_items'], $row['bundle_items'], $row['sold_items'], $row['inventory_pieces']), ';');
    fputcsv($out, array('All selected platforms', $totals['orders'], $totals['single_items'], $totals['bundle_items'], $totals['sold_items'], $totals['inventory_pieces']), ';');
    fputcsv($out, array(), ';');
    fputcsv($out, array('Warehouse', 'Warehouse label', 'Platform', 'Orders', 'Sold channel items', 'Inventory pieces deducted'), ';');
    foreach ($warehouseRows as $row) fputcsv($out, array($csv($row['warehouse_ref']), $csv($row['warehouse_label']), $csv(ucfirst($row['connector'])), $row['orders'], $row['sold_items'], $row['inventory_pieces']), ';');
    fputcsv($out, array(), ';');
    fputcsv($out, array('Platform', 'Currency', 'Orders', 'Gross sales', 'Provider fees / costs', 'Net payout'), ';');
    foreach ($financialRows as $row) fputcsv($out, array($csv(ucfirst($row['connector'])), $csv($row['currency']), $row['orders'], $row['gross'], $row['fees'], $row['net']), ';');
    fputcsv($out, array(), ';');
    fputcsv($out, array('Dolibarr product ref', 'Dolibarr product', 'Platform', 'Orders', 'Units sold directly', 'Units inside bundles', 'Total physical units sold'), ';');
    foreach ($inventoryProducts as $row) fputcsv($out, array($csv($row['ref']), $csv($row['label']), $csv(ucfirst($row['connector'])), $row['orders'], $row['direct_units'], $row['bundle_units'], $row['total_units']), ';');
    fputcsv($out, array(), ';');
    fputcsv($out, array('Platform', 'SKU', 'Product', 'Type', 'Source', 'Orders', 'Sold items', 'Underlying inventory pieces'), ';');
    foreach ($products as $row) fputcsv($out, array($csv(ucfirst($row['connector'])), $csv($row['sku']), $csv($row['label']), $csv($row['type']), $csv($row['source_origin']), $row['orders'], $row['sold_items'], $row['inventory_pieces']), ';');
    fclose($out);
    exit;
}

llxHeader('', 'Finance Automation Hub sales analytics');
echo load_fiche_titre('Sales analytics', '<a href="' . DOL_URL_ROOT . '/custom/financeautomationhub/index.php?mainmenu=financeautomationhub">Back to dashboard</a>', 'chart');
$query = http_build_query(array_merge($filters, array('action' => 'export', 'mainmenu' => 'financeautomationhub')));
?>
<form method="GET" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="border:1px solid #ddd;border-radius:6px;padding:12px;margin-bottom:15px;display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
  <input type="hidden" name="mainmenu" value="financeautomationhub">
  <label>Sales channel<br><select class="flat" name="connector"><option value="">All channels</option><?php foreach (array('woocommerce' => 'WooCommerce', 'amazon' => 'Amazon', 'sumup' => 'SumUp') as $key => $label) { ?><option value="<?php echo $key; ?>"<?php echo $filters['connector'] === $key ? ' selected' : ''; ?>><?php echo $label; ?></option><?php } ?></select></label>
  <label>Source warehouse<br><select class="flat minwidth200" name="warehouse_id"><option value="0">All warehouses</option><?php foreach ($warehouses as $warehouse) { $warehouseLabel = trim($warehouse['ref'] . ($warehouse['label'] !== '' ? ' - ' . $warehouse['label'] : '')); ?><option value="<?php echo (int) $warehouse['id']; ?>"<?php echo $filters['warehouse_id'] === (int) $warehouse['id'] ? ' selected' : ''; ?>><?php echo dol_escape_htmltag($warehouseLabel); ?></option><?php } ?></select></label>
  <label>Period<br><select class="flat" name="period" id="fahPeriod" onchange="fahReportPeriod()"><option value="month"<?php echo $filters['period'] === 'month' ? ' selected' : ''; ?>>Month</option><option value="year"<?php echo $filters['period'] === 'year' ? ' selected' : ''; ?>>Year</option><option value="range"<?php echo $filters['period'] === 'range' ? ' selected' : ''; ?>>Date range</option><option value="all"<?php echo $filters['period'] === 'all' ? ' selected' : ''; ?>>All dates</option></select></label>
  <label class="fah-month-field">Month<br><select class="flat" name="month"><?php for ($m = 1; $m <= 12; $m++) { ?><option value="<?php echo $m; ?>"<?php echo $filters['month'] === $m ? ' selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option><?php } ?></select></label>
  <label class="fah-year-field">Year<br><input class="flat" type="number" min="2000" max="2100" name="year" value="<?php echo (int) $filters['year']; ?>" style="width:90px;"></label>
  <label class="fah-range-field">From<br><input class="flat" type="date" name="from" value="<?php echo dol_escape_htmltag($filters['from']); ?>"></label>
  <label class="fah-range-field">To<br><input class="flat" type="date" name="to" value="<?php echo dol_escape_htmltag($filters['to']); ?>"></label>
  <button class="button" type="submit">Apply filters</button>
  <a class="button" href="<?php echo dol_escape_htmltag($_SERVER['PHP_SELF'] . '?' . $query); ?>">Export for Excel (CSV)</a>
</form>
<p class="opacitymedium">Period: <?php echo dol_escape_htmltag($report->periodLabel($filters)); ?>. Channel and warehouse filters can be combined. Warehouse attribution uses the applied stock movement, so shared warehouses remain distinguishable by sales channel.</p>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-bottom:18px;">
<?php foreach (array('single_items' => 'Single items', 'bundle_items' => 'Bundle items', 'sold_items' => 'Total sold items', 'inventory_pieces' => 'Inventory pieces') as $key => $label) { ?>
<div style="border:1px solid #ddd;border-radius:6px;padding:14px;"><div class="opacitymedium"><?php echo $label; ?></div><div style="font-size:2em;font-weight:bold;"><?php echo price($totals[$key]); ?></div></div>
<?php } ?>
</div>

<h2>Channel totals</h2>
<div class="div-table-responsive-no-min"><table class="liste centpercent"><tr class="liste_titre"><th>Channel</th><th class="right">Orders</th><th class="right">Singles</th><th class="right">Bundles</th><th class="right">Total sold</th><th class="right">Inventory pieces</th></tr>
<?php if (empty($summary)) { ?><tr><td colspan="6" class="opacitymedium">No sales lines are recorded for these filters. Run sync again to populate analytics for already-synced orders.</td></tr><?php } else { foreach ($summary as $row) { ?><tr class="oddeven"><td><strong><?php echo dol_escape_htmltag(ucfirst($row['connector'])); ?></strong></td><td class="right"><?php echo (int) $row['orders']; ?></td><td class="right"><?php echo price($row['single_items']); ?></td><td class="right"><?php echo price($row['bundle_items']); ?></td><td class="right"><?php echo price($row['sold_items']); ?></td><td class="right"><?php echo price($row['inventory_pieces']); ?></td></tr><?php }} ?>
</table></div>

<h2>Physical Dolibarr items sold</h2>
<p class="opacitymedium">Bundle recipes are expanded here. Direct units plus units contained in bundles gives the real quantity sold for each Dolibarr stock item, independently of whether its stock backfill has run.</p>
<div class="div-table-responsive-no-min"><table class="liste centpercent"><tr class="liste_titre"><th>Dolibarr ref</th><th>Product</th><th>Channel</th><th class="right">Orders</th><th class="right">Direct units</th><th class="right">Units in bundles</th><th class="right">Total physical units</th></tr>
<?php if (empty($inventoryProducts)) { ?><tr><td colspan="7" class="opacitymedium">No mapped product recipes have recorded sales for these filters.</td></tr><?php } else { foreach ($inventoryProducts as $row) { ?><tr class="oddeven"><td><strong><?php echo dol_escape_htmltag($row['ref']); ?></strong></td><td><?php echo dol_escape_htmltag($row['label']); ?></td><td><?php echo dol_escape_htmltag(ucfirst($row['connector'])); ?></td><td class="right"><?php echo (int) $row['orders']; ?></td><td class="right"><?php echo price($row['direct_units']); ?></td><td class="right"><?php echo price($row['bundle_units']); ?></td><td class="right"><strong><?php echo price($row['total_units']); ?></strong></td></tr><?php }} ?>
</table></div>

<h2>Warehouse × sales channel</h2>
<p class="opacitymedium">These rows show actual applied stock deductions. A mixed bundle supplied by two warehouses appears once under each warehouse; its inventory pieces are split by warehouse.</p>
<div class="div-table-responsive-no-min"><table class="liste centpercent"><tr class="liste_titre"><th>Warehouse</th><th>Location</th><th>Channel</th><th class="right">Orders</th><th class="right">Sold channel items</th><th class="right">Inventory pieces deducted</th></tr>
<?php if (empty($warehouseRows)) { ?><tr><td colspan="6" class="opacitymedium">No applied stock movements were found for these filters.</td></tr><?php } else { foreach ($warehouseRows as $row) { ?><tr class="oddeven"><td><strong><?php echo dol_escape_htmltag($row['warehouse_ref']); ?></strong></td><td><?php echo dol_escape_htmltag($row['warehouse_label']); ?></td><td><?php echo dol_escape_htmltag(ucfirst($row['connector'])); ?></td><td class="right"><?php echo (int) $row['orders']; ?></td><td class="right"><?php echo price($row['sold_items']); ?></td><td class="right"><?php echo price($row['inventory_pieces']); ?></td></tr><?php }} ?>
</table></div>

<h2>Sales and provider costs</h2>
<p class="opacitymedium">Fees are recorded from WooCommerce payment metadata, Amazon Finances transactions, or SumUp transaction details. Values are kept separate by currency.</p>
<div class="div-table-responsive-no-min"><table class="liste centpercent"><tr class="liste_titre"><th>Channel</th><th>Currency</th><th class="right">Orders</th><th class="right">Gross sales</th><th class="right">Provider fees / costs</th><th class="right">Net payout</th></tr>
<?php if (empty($financialRows)) { ?><tr><td colspan="6" class="opacitymedium">No finalized financial records were found for these filters.</td></tr><?php } else { foreach ($financialRows as $row) { ?><tr class="oddeven"><td><strong><?php echo dol_escape_htmltag(ucfirst($row['connector'])); ?></strong></td><td><?php echo dol_escape_htmltag($row['currency']); ?></td><td class="right"><?php echo (int) $row['orders']; ?></td><td class="right"><?php echo price($row['gross']); ?></td><td class="right"><?php echo price($row['fees']); ?></td><td class="right"><?php echo price($row['net']); ?></td></tr><?php }} ?>
</table></div>

<h2>Products sold</h2>
<div class="div-table-responsive-no-min"><table class="liste centpercent"><tr class="liste_titre"><th>Channel</th><th>SKU</th><th>Product</th><th>Type</th><th>Handled by</th><th class="right">Orders</th><th class="right">Sold items</th><th class="right">Inventory pieces</th></tr>
<?php if (empty($products)) { ?><tr><td colspan="8" class="opacitymedium">No product sales found.</td></tr><?php } else { foreach ($products as $row) { ?><tr class="oddeven"><td><?php echo dol_escape_htmltag(ucfirst($row['connector'])); ?></td><td><?php echo dol_escape_htmltag($row['sku']); ?></td><td><?php echo dol_escape_htmltag($row['label']); ?></td><td><?php echo dol_escape_htmltag($row['type']); ?></td><td><?php echo $row['source_origin'] === 'dolibarr_pos' ? 'Dolibarr POS integration' : 'Finance Automation Hub'; ?></td><td class="right"><?php echo (int) $row['orders']; ?></td><td class="right"><?php echo price($row['sold_items']); ?></td><td class="right"><?php echo price($row['inventory_pieces']); ?></td></tr><?php }} ?>
</table></div>
<script>
function fahReportPeriod() {
  var value = document.getElementById('fahPeriod').value;
  document.querySelectorAll('.fah-month-field').forEach(function(el) { el.style.display = value === 'month' ? '' : 'none'; });
  document.querySelectorAll('.fah-year-field').forEach(function(el) { el.style.display = value === 'month' || value === 'year' ? '' : 'none'; });
  document.querySelectorAll('.fah-range-field').forEach(function(el) { el.style.display = value === 'range' ? '' : 'none'; });
}
fahReportPeriod();
</script>
<?php llxFooter(); $db->close();
