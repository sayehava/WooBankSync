<?php

require_once __DIR__ . '/woocommerceclient.class.php';
require_once __DIR__ . '/wbsgermanizedclient.class.php';

class WooBankSync
{
    private $db;
    private $conf;
    private $langs;
    public $errors = array();
    public $pdfLog = array();

    public function __construct($db, $conf, $langs)
    {
        $this->db = $db;
        $this->conf = $conf;
        $this->langs = $langs;
    }

    public function client()
    {
        return new WbsWooCommerceClient(
            $this->getConst('WBS_WOO_URL'),
            $this->getConst('WBS_WOO_CONSUMER_KEY'),
            $this->getConst('WBS_WOO_CONSUMER_SECRET')
        );
    }

    public function sync($limitPages = 1, $perPage = 20)
    {
        $stats = array('imported' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => array());
        $client = $this->client();
        $statuses = $this->csvToArray($this->getConst('WBS_ORDER_STATUSES', 'processing,completed'));
        $fromDate = $this->getConst('WBS_SYNC_FROM_DATE');

        for ($page = 1; $page <= $limitPages; $page++) {
            $orders = $client->getOrders($statuses, $fromDate, $page, $perPage);
            if ($orders === false) {
                $stats['errors']++;
                $stats['messages'][] = !empty($client->error) ? $client->error : 'WooCommerce request failed while fetching orders.';
                break;
            }
            if (count($orders) === 0) break;

            foreach ($orders as $order) {
                $result = $this->syncOneOrder($order);
                $stats[$result['status']]++;
                if (!empty($result['message'])) $stats['messages'][] = $result['message'];
            }
        }

        $this->setConst('WBS_LAST_SYNC', dol_now(), 'chaine');
        return $stats;
    }

    public function syncBatch($page, $batchSize)
    {
        $page = max(1, (int) $page);
        $batchSize = max(1, min(100, (int) $batchSize));
        $stats = array('imported' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => array(), 'items' => array(), 'has_more' => false);
        $client = $this->client();
        $statuses = $this->csvToArray($this->getConst('WBS_ORDER_STATUSES', 'processing,completed'));
        $orders = $client->getOrders($statuses, $this->getConst('WBS_SYNC_FROM_DATE'), $page, $batchSize);
        if ($orders === false) {
            $stats['errors'] = 1;
            $stats['messages'][] = $client->error ?: 'WooCommerce request failed while fetching orders.';
            return $stats;
        }

        foreach ($orders as $order) {
            $result = $this->syncOneOrder($order);
            $stats[$result['status']]++;
            if (!empty($result['message'])) $stats['messages'][] = $result['message'];
            $stats['items'][] = array(
                'id' => (string) ($order['id'] ?? ''),
                'number' => (string) ($order['number'] ?? ($order['id'] ?? '')),
                'status' => $result['status'],
                'message' => (string) ($result['message'] ?? ''),
            );
        }
        $stats['has_more'] = count($orders) === $batchSize;
        $this->setConst('WBS_LAST_SYNC', dol_now(), 'chaine');
        return $stats;
    }

    public function syncOneOrder($order)
    {
        $orderId = (string) ($order['id'] ?? '');
        $orderNumber = isset($order['number']) ? (string) $order['number'] : $orderId;
        $paymentMethod = isset($order['payment_method']) ? trim((string) $order['payment_method']) : '';
        $transactionId = isset($order['transaction_id']) ? (string) $order['transaction_id'] : '';
        $gross = $this->normalizeAmount($order['total'] ?? 0);
        $currency = isset($order['currency']) ? (string) $order['currency'] : 'EUR';
        $dateOrder = $this->wooDateToSql($order['date_paid'] ?? ($order['date_created'] ?? null));
        $invoiceNumber = $this->extractWooInvoiceNumber($order);
        $pdfUrl = $this->extractWooInvoicePdfUrlFromOrder($order);
        $orderStatus = isset($order['status']) ? (string) $order['status'] : '';

        if ($this->isOrderSynced($orderId)) {
            return array('status' => 'skipped', 'message' => 'Skipped Woo order #' . $orderNumber . ': already synced.');
        }

        // Zero-total WooCommerce orders do not create real money movements.
        // This includes free/replacement/manual/fully-discounted orders and some refund/cancel edge cases.
        // Keep a log entry for traceability, but do not create a Dolibarr bank entry and do not count as an error.
        if ($gross <= 0) {
            $msg = 'Skipped Woo order #' . $orderNumber . ': zero order total' . ($orderStatus !== '' ? ' (status=' . $orderStatus . ')' : '') . ', no bank entry created.';
            $this->insertLog($order, 0, $gross, 0, 0, 0, 'skipped', $msg, $dateOrder, $invoiceNumber, 0);
            return array('status' => 'skipped', 'message' => $msg);
        }

        // Some WooCommerce orders can be completed/processing without a payment gateway
        // (manual admin orders, legacy/imported orders, cancelled/refunded transitions, or unpaid/manual cases).
        // Those orders must not create bank movements and should not count as sync errors.
        if ($paymentMethod === '') {
            $msg = 'Skipped Woo order #' . $orderNumber . ': empty payment method' . ($orderStatus !== '' ? ' (status=' . $orderStatus . ')' : '') . ', no bank entry created.';
            $this->insertLog($order, 0, $gross, 0, 0, 0, 'skipped', $msg, $dateOrder, $invoiceNumber, 0);
            return array('status' => 'skipped', 'message' => $msg);
        }

        $map = $this->gatewayMap();
        $mappedPaymentMethod = $paymentMethod;
        $gatewayConfig = $this->resolveGatewayConfig($paymentMethod, $map);
        if (empty($gatewayConfig) || empty($gatewayConfig['bank_id'])) {
            $this->insertLog($order, 0, $gross, 0, 0, 0, 'error', 'No Dolibarr bank account mapping for gateway: ' . $paymentMethod, $dateOrder, $invoiceNumber, 0);
            return array('status' => 'errors', 'message' => 'Order #' . $orderNumber . ': missing bank mapping for ' . $paymentMethod . '.');
        }
        if (!empty($gatewayConfig['_mapped_from'])) $mappedPaymentMethod = (string) $gatewayConfig['_mapped_from'];

        $bankId = (int) $gatewayConfig['bank_id'];
        $fee = $this->extractAmountFromConfiguredKey($order, $gatewayConfig['fee_key'] ?? '');
        if ($fee <= 0) $fee = $this->autoDetectFee($order);
        $payout = $this->extractAmountFromConfiguredKey($order, $gatewayConfig['payout_key'] ?? '');
        $calculatedPayout = max(0, $gross - $fee);
        if ($payout <= 0 || $payout > $gross || ($fee > 0 && $payout <= $fee)) $payout = $calculatedPayout;

        $dryRun = (int) $this->getConst('WBS_DRY_RUN', '0') === 1;
        $buyerName = $this->extractBuyerName($order);
        $labelBase = 'WOO - #' . $orderNumber;
        if (!empty($buyerName)) $labelBase .= ' ' . $buyerName;
        if ($this->nativeInvoiceReferenceEnabled() && !empty($invoiceNumber)) $labelBase .= ' - ' . $this->formatInvoiceReferenceForLabel($invoiceNumber);

        $this->db->begin();
        $bankLineGross = 0;
        $bankLineFee = 0;

        if (!$dryRun) {
            $bankLineGross = $this->insertBankLine($bankId, $gross, $labelBase, $dateOrder);
            if ($bankLineGross <= 0) {
                $this->db->rollback();
                $msg = 'Failed to insert gross bank line for Woo order #' . $orderNumber . ': ' . $this->db->lasterror();
                $this->insertLog($order, $bankId, $gross, $fee, 0, 0, 'error', $msg, $dateOrder, $invoiceNumber, $payout);
                return array('status' => 'errors', 'message' => $msg);
            }

            if ($fee > 0) {
                $bankLineFee = $this->insertBankLine($bankId, -1 * $fee, 'Payment fee for ' . $labelBase, $dateOrder);
                if ($bankLineFee <= 0) {
                    $this->db->rollback();
                    $msg = 'Failed to insert fee bank line for Woo order #' . $orderNumber . ': ' . $this->db->lasterror();
                    $this->insertLog($order, $bankId, $gross, $fee, $bankLineGross, 0, 'error', $msg, $dateOrder, $invoiceNumber, $payout);
                    return array('status' => 'errors', 'message' => $msg);
                }
            }
        }

        $pdfEcmFilepath = '';
        if (!$dryRun && $pdfUrl !== '' && (int) $this->getConst('WBS_PDF_DOWNLOAD_ENABLED', '0') === 1) {
            $pdfEcmFilepath = $this->downloadAndStoreInvoicePdf($orderId, $orderNumber, $invoiceNumber, $pdfUrl);
        }

        $status = $dryRun ? 'dryrun' : 'synced';
        $message = ($dryRun ? '[DRY RUN] ' : '') . 'Synced Woo order #' . $orderNumber . ' gross=' . price($gross) . ' fee=' . price($fee) . ' payout=' . price($payout) . ' gateway=' . $paymentMethod . ($mappedPaymentMethod !== $paymentMethod ? ' mapped_to=' . $mappedPaymentMethod : '');
        $this->insertLog($order, $bankId, $gross, $fee, $bankLineGross, $bankLineFee, $status, $message, $dateOrder, $invoiceNumber, $payout, $pdfUrl, $pdfEcmFilepath);
        $this->upsertOrderCache($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $pdfEcmFilepath, $order);
        $this->db->commit();

        return array('status' => 'imported', 'message' => $message);
    }

    public function refreshWooDiscovery()
    {
        $client = $this->client();
        $gateways = $client->getPaymentGateways();
        if ($gateways === false) return array(false, $client->error);

        $gatewayMap = array();
        $installedGatewayMap = array();
        foreach ($gateways as $gateway) {
            $gid = (string) ($gateway['id'] ?? '');
            if ($gid === '') continue;

            // Keep installed gateways only as a title lookup table.
            // Do not list disabled-and-never-used gateways such as WeChat, Google Pay, etc.
            $installedGatewayMap[$gid] = array(
                'id' => $gid,
                'title' => (string) ($gateway['title'] ?? $gid),
                'description' => (string) ($gateway['description'] ?? ''),
                'enabled' => !empty($gateway['enabled']) ? 1 : 0,
            );

            if (!empty($gateway['enabled'])) {
                $gatewayMap[$gid] = array(
                    'id' => $gid,
                    'title' => (string) ($gateway['title'] ?? $gid),
                    'description' => (string) ($gateway['description'] ?? ''),
                    'enabled' => 1,
                    'source' => 'active',
                    'orders_count' => 0,
                );
            }
        }

        $orders = array();
        $statuses = $this->csvToArray($this->getConst('WBS_ORDER_STATUSES', 'processing,completed'));
        for ($page = 1; $page <= 5; $page++) {
            $batch = $client->getOrders($statuses, '', $page, 100);
            if ($batch === false) return array(false, $client->error);
            if (empty($batch)) break;
            foreach ($batch as $order) $orders[] = $order;
            if (count($batch) < 100) break;
        }

        foreach ($orders as $order) {
            $gid = (string) ($order['payment_method'] ?? '');
            if ($gid === '') continue;

            if (empty($gatewayMap[$gid])) {
                $title = (string) ($order['payment_method_title'] ?? '');
                if ($title === '' && !empty($installedGatewayMap[$gid]['title'])) {
                    $title = (string) $installedGatewayMap[$gid]['title'];
                }
                if ($title === '') $title = $gid;

                $gatewayMap[$gid] = array(
                    'id' => $gid,
                    'title' => $title,
                    'description' => 'Historical gateway detected from existing WooCommerce orders.',
                    'enabled' => !empty($installedGatewayMap[$gid]['enabled']) ? 1 : 0,
                    'source' => !empty($installedGatewayMap[$gid]['enabled']) ? 'active_used' : 'historical_orders',
                    'orders_count' => 1,
                );
            } else {
                if (empty($gatewayMap[$gid]['title']) && !empty($order['payment_method_title'])) {
                    $gatewayMap[$gid]['title'] = (string) $order['payment_method_title'];
                }
                $gatewayMap[$gid]['orders_count'] = (int) ($gatewayMap[$gid]['orders_count'] ?? 0) + 1;
                if (($gatewayMap[$gid]['source'] ?? '') === 'active' && (int) $gatewayMap[$gid]['orders_count'] > 0) {
                    $gatewayMap[$gid]['source'] = 'active_used';
                }
            }
        }

        $allGateways = array_values($gatewayMap);
        usort($allGateways, static function ($a, $b) { return strcmp((string) $a['id'], (string) $b['id']); });

        $meta = $this->discoverMetaKeysByGateway($orders);
        $invoiceKeys = $this->discoverInvoiceKeys($orders);
        $invoiceAvailable = (!empty($invoiceKeys) || (int) $this->getConst('WBS_DOCUMENT_SYNC_ENABLED', '0') === 1) ? '1' : '0';

        $this->setConst('WBS_GATEWAYS_JSON', json_encode($allGateways), 'chaine');
        $this->setConst('WBS_META_KEYS_JSON', json_encode($meta), 'chaine');
        $this->setConst('WBS_WOO_INVOICE_KEYS_JSON', json_encode($invoiceKeys), 'chaine');
        $this->setConst('WBS_WOO_INVOICE_AVAILABLE', $invoiceAvailable, 'yesno');

        return array(true, 'Detected ' . count($allGateways) . ' relevant payment methods/gateways (' . count($orders) . ' recent orders scanned). Only active gateways and gateways used in existing orders are listed. Found ' . count($invoiceKeys) . ' possible invoice meta keys.');
    }

    public function autoCreateAndMapAccounts()
    {
        $gateways = $this->getJsonConst('WBS_GATEWAYS_JSON', array());
        if (empty($gateways)) return array(false, 'No active WooCommerce gateways detected. First save API settings and click Refresh from WooCommerce.');
        $map = $this->gatewayMap();
        $created = 0;
        $existing = 0;
        $failed = array();
        foreach ($gateways as $gateway) {
            $gid = (string) $gateway['id'];
            $title = (string) (!empty($gateway['title']) ? $gateway['title'] : $gid);
            $label = $title;
            $ref = $this->makeShortBankRef($gid);
            $wasExisting = false;
            $accountId = $this->findOrCreateVirtualBankAccount($label, $ref, $wasExisting);
            if ($accountId > 0) {
                if (empty($map[$gid])) $map[$gid] = array();
                $map[$gid]['bank_id'] = $accountId;
                if (!isset($map[$gid]['fee_key'])) $map[$gid]['fee_key'] = '';
                if (!isset($map[$gid]['payout_key'])) $map[$gid]['payout_key'] = '';
                if ($wasExisting) $existing++; else $created++;
            } else {
                $failed[] = $gid . ': ' . $this->db->lasterror();
            }
        }
        $this->setConst('WBS_GATEWAY_MAP_JSON', json_encode($map), 'chaine');
        $message = 'Created ' . $created . ', reused ' . $existing . ', mapped ' . ($created + $existing) . ' WooCommerce payment methods.';
        if (!empty($failed)) return array(false, $message . ' Failed: ' . implode(' | ', $failed));
        return array(true, $message);
    }


    public function getDifferenceCheckOrders()
    {
        $rows = array();
        $sql = 'SELECT woo_order_id, woo_order_number FROM ' . MAIN_DB_PREFIX . 'woobanksync_log'
            . ' WHERE entity=' . (int) $this->conf->entity
            . " AND sync_status='synced' ORDER BY rowid DESC";
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $rows[] = array('id' => (string) $obj->woo_order_id, 'number' => (string) $obj->woo_order_number);
            }
        }
        return $rows;
    }

    public function resyncDifferences(array $orderIds = array(), $force = false)
    {
        $stats = array('checked' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'messages' => array(), 'items' => array());
        $table = MAIN_DB_PREFIX . 'woobanksync_log';

        $sql = 'SELECT * FROM ' . $table . ' WHERE entity=' . (int) $this->conf->entity . " AND sync_status='synced' ORDER BY rowid DESC";
        if (!empty($orderIds)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
            $sql = 'SELECT * FROM ' . $table . ' WHERE entity=' . (int) $this->conf->entity
                . " AND sync_status='synced' AND woo_order_id IN (" . implode(',', $ids) . ') ORDER BY rowid DESC';
        }
        $resql = $this->db->query($sql);
        if (!$resql) {
            $stats['errors']++;
            $stats['messages'][] = 'Could not read sync log: ' . $this->db->lasterror();
            return $stats;
        }

        $logRows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $logRows[] = $obj;
        }

        if (empty($logRows)) {
            $stats['messages'][] = 'No synced orders in log.';
            return $stats;
        }

        $client = $this->client();
        $map = $this->gatewayMap();
        $germanizedEnabled = (int) $this->getConst('WBS_GERMANIZED_PRO_ENABLED', '0') === 1;
        $requiredFields = array('id', 'number', 'total', 'payment_method', 'billing', 'meta_data');
        if ($germanizedEnabled) $requiredFields[] = 'invoices';

        foreach (array_chunk($logRows, 50) as $chunk) {
            $ids = array_map(static function ($r) { return (int) $r->woo_order_id; }, $chunk);
            $orders = $client->getOrdersByIds($ids, $requiredFields);
            if ($orders === false) {
                $stats['errors'] += count($chunk);
                $stats['messages'][] = 'Batch fetch failed: ' . $client->error;
                continue;
            }

            $orderById = array();
            foreach ($orders as $order) {
                $orderById[(string) $order['id']] = $order;
            }

            foreach ($chunk as $logRow) {
                $stats['checked']++;
                $orderId = (string) $logRow->woo_order_id;

                if (empty($orderById[$orderId])) {
                    $stats['errors']++;
                    $stats['messages'][] = 'Order #' . $logRow->woo_order_number . ' not found in WooCommerce.';
                    $stats['items'][] = array('id' => $orderId, 'number' => (string) $logRow->woo_order_number, 'status' => 'error');
                    continue;
                }

                $order = $orderById[$orderId];
                $oldInvoiceNumber = (string) ($logRow->woo_invoice_number ?? '');
                $oldPdfUrl = (string) ($logRow->woo_invoice_pdf_url ?? '');
                $newInvoiceNumber = $germanizedEnabled ? $this->extractWooInvoiceNumber($order) : $oldInvoiceNumber;
                // Difference checking uses only data already present in the Woo order response.
                // It never calls Germanized document endpoints.
                $newPdfUrl = $germanizedEnabled ? ($oldPdfUrl !== '' ? $oldPdfUrl : $this->extractWooInvoicePdfUrlFromOrder($order)) : $oldPdfUrl;
                $newBuyerName = $this->extractBuyerName($order);
                $newGross = $this->normalizeAmount($order['total'] ?? 0);

                $paymentMethod = (string) ($order['payment_method'] ?? '');
                $gatewayConfig = $this->resolveGatewayConfig($paymentMethod, $map);
                $newFee = $this->extractAmountFromConfiguredKey($order, $gatewayConfig['fee_key'] ?? '');
                if ($newFee <= 0) $newFee = $this->autoDetectFee($order);

                $oldGross = (float) ($logRow->gross_amount ?? 0);
                $oldFee = (float) ($logRow->fee_amount ?? 0);
                $oldEcmPath = (string) ($logRow->pdf_ecm_filepath ?? '');

                $invoiceDiff = $germanizedEnabled && $newInvoiceNumber !== $oldInvoiceNumber;
                $grossDiff = abs($newGross - $oldGross) > 0.005;
                $feeDiff = abs($newFee - $oldFee) > 0.005;
                $pdfDownloadEnabled = (int) $this->getConst('WBS_PDF_DOWNLOAD_ENABLED', '0') === 1;
                $pdfMissing = $germanizedEnabled && $pdfDownloadEnabled && $newPdfUrl !== '' && $oldEcmPath === '';
                $pdfUrlChanged = $germanizedEnabled && $newPdfUrl !== $oldPdfUrl;

                // Keep only the reconciliation fields current. Full JSON refresh is a separate action.
                $this->upsertOrderCache($orderId, (string) $logRow->woo_order_number, $newInvoiceNumber, $newPdfUrl, $oldEcmPath);

                if (!$force && !$invoiceDiff && !$grossDiff && !$feeDiff && !$pdfMissing && !$pdfUrlChanged) {
                    $stats['unchanged']++;
                    $stats['items'][] = array('id' => $orderId, 'number' => (string) $logRow->woo_order_number, 'status' => 'unchanged');
                    continue;
                }
                $changed = array();
                if ($invoiceDiff) $changed[] = 'invoice_number(' . ($oldInvoiceNumber ?: 'none') . '→' . ($newInvoiceNumber ?: 'none') . ')';
                if ($grossDiff) $changed[] = 'gross(' . $oldGross . '→' . $newGross . ')';
                if ($feeDiff) $changed[] = 'fee(' . $oldFee . '→' . $newFee . ')';
                if ($pdfMissing) $changed[] = 'pdf_missing(will download)';
                if ($pdfUrlChanged && !$pdfMissing) $changed[] = 'pdf_url_changed';
                if ($force && empty($changed)) $changed[] = 'force-update';

                $ok = $this->applyOrderUpdate($logRow, $order, $newInvoiceNumber, $newBuyerName, $newGross, $newFee, $newPdfUrl);
                if ($ok) {
                    $stats['updated']++;
                    $stats['messages'][] = 'Updated #' . $logRow->woo_order_number . ': ' . implode(', ', $changed);
                    $stats['items'][] = array('id' => $orderId, 'number' => (string) $logRow->woo_order_number, 'status' => 'updated', 'changes' => implode(', ', $changed));
                } else {
                    $stats['errors']++;
                    $stats['messages'][] = 'Failed to update #' . $logRow->woo_order_number . ': ' . $this->db->lasterror();
                    $stats['items'][] = array('id' => $orderId, 'number' => (string) $logRow->woo_order_number, 'status' => 'error');
                }
            }
        }

        return $stats;
    }

    private function applyOrderUpdate($logRow, $order, $newInvoiceNumber, $newBuyerName, $newGross, $newFee, $newPdfUrl = '')
    {
        $orderNumber = (string) $logRow->woo_order_number;
        $orderId = (string) $logRow->woo_order_id;
        $dateOrder = (string) ($logRow->date_order ?? '');

        $showInvoice = $this->nativeInvoiceReferenceEnabled();
        $labelBase = 'WOO - #' . $orderNumber;
        if (!empty($newBuyerName)) $labelBase .= ' ' . $newBuyerName;
        if ($showInvoice && !empty($newInvoiceNumber)) $labelBase .= ' - ' . $this->formatInvoiceReferenceForLabel($newInvoiceNumber);
        $this->db->begin();

        if (!empty($logRow->bank_line_id_gross)) {
            $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'bank SET'
                . " label='" . $this->db->escape($labelBase) . "'"
                . ', amount=' . price2num($newGross, 'MT')
                . ' WHERE rowid=' . (int) $logRow->bank_line_id_gross;
            if (!$this->db->query($sql)) { $this->db->rollback(); return false; }
        }

        if (!empty($logRow->bank_line_id_fee)) {
            $feeLabel = 'Payment fee for ' . $labelBase;
            $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'bank SET'
                . " label='" . $this->db->escape($feeLabel) . "'"
                . ', amount=' . price2num(-1 * $newFee, 'MT')
                . ' WHERE rowid=' . (int) $logRow->bank_line_id_fee;
            if (!$this->db->query($sql)) { $this->db->rollback(); return false; }
        }

        $oldEcmPath = (string) ($logRow->pdf_ecm_filepath ?? '');
        $pdfEcmFilepath = $oldEcmPath;
        if ((int) $this->getConst('WBS_PDF_DOWNLOAD_ENABLED', '0') === 1 && $newPdfUrl !== '' && $oldEcmPath === '') {
            $downloaded = $this->downloadAndStoreInvoicePdf($orderId, $orderNumber, $newInvoiceNumber, $newPdfUrl);
            if ($downloaded !== '') $pdfEcmFilepath = $downloaded;
        }

        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'woobanksync_log SET'
            . " woo_invoice_number='" . $this->db->escape($newInvoiceNumber) . "'"
            . ', gross_amount=' . price2num($newGross, 'MT')
            . ', fee_amount=' . price2num($newFee, 'MT')
            . ", woo_invoice_pdf_url='" . $this->db->escape($newPdfUrl) . "'"
            . ", pdf_ecm_filepath='" . $this->db->escape($pdfEcmFilepath) . "'"
            . ", sync_status='synced'"
            . ", sync_message='Updated from WooCommerce on " . $this->db->escape(dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S')) . "'"
            . ' WHERE rowid=' . (int) $logRow->rowid;
        if (!$this->db->query($sql)) { $this->db->rollback(); return false; }

        $this->db->commit();
        $this->upsertOrderCache($orderId, $orderNumber, $newInvoiceNumber, $newPdfUrl, $pdfEcmFilepath);
        return true;
    }

    public function desyncAllSyncedEntries()
    {
        $table = MAIN_DB_PREFIX . 'woobanksync_log';
        $bankIds = array();

        // 1) Prefer exact bank row ids stored in our sync log.
        $resql = $this->db->query('SELECT bank_line_id_gross, bank_line_id_fee, dolibarr_bank_account_id FROM ' . $table . ' WHERE entity=' . (int) $this->conf->entity);
        $bankAccountIds = array();
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                if (!empty($obj->bank_line_id_gross)) $bankIds[] = (int) $obj->bank_line_id_gross;
                if (!empty($obj->bank_line_id_fee)) $bankIds[] = (int) $obj->bank_line_id_fee;
                if (!empty($obj->dolibarr_bank_account_id)) $bankAccountIds[] = (int) $obj->dolibarr_bank_account_id;
            }
        } else {
            return array(false, 'Could not read WooBankSync log: ' . $this->db->lasterror());
        }

        // 2) Also include mapped virtual bank accounts so older rows whose ids were not logged can be found safely.
        $map = $this->gatewayMap();
        foreach ($map as $gatewayConfig) {
            if (!empty($gatewayConfig['bank_id'])) $bankAccountIds[] = (int) $gatewayConfig['bank_id'];
        }
        $bankAccountIds = array_values(array_unique(array_filter($bankAccountIds)));

        // 3) Fallback for older module versions: find rows created by WooBankSync by label pattern.
        // This is intentionally conservative, so manually-created unrelated bank entries are not touched.
        $where = array();
        $where[] = "label LIKE '" . $this->db->escape('WOO - #%') . "'";
        $where[] = "label LIKE '" . $this->db->escape('Payment fee for WOO - #%') . "'";
        $where[] = "label LIKE '" . $this->db->escape('[DRY RUN] WOO - #%') . "'";
        if (!empty($bankAccountIds)) {
            $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'bank WHERE fk_account IN (' . implode(',', array_map('intval', $bankAccountIds)) . ') AND (' . implode(' OR ', $where) . ')';
            $res = $this->db->query($sql);
            if ($res) {
                while ($obj = $this->db->fetch_object($res)) {
                    if (!empty($obj->rowid)) $bankIds[] = (int) $obj->rowid;
                }
            }
        }

        // 4) Last safety net: rows referenced by Number/Check field and Woo labels, regardless of account id.
        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'bank WHERE (' . implode(' OR ', $where) . ')';
        $res = $this->db->query($sql);
        if ($res) {
            while ($obj = $this->db->fetch_object($res)) {
                if (!empty($obj->rowid)) $bankIds[] = (int) $obj->rowid;
            }
        }

        $bankIds = array_values(array_unique(array_filter($bankIds)));
        $this->db->begin();
        $deletedBank = 0;
        $deletedLinks = 0;
        $deletedClasses = 0;

        if (!empty($bankIds)) {
            foreach (array_chunk($bankIds, 100) as $chunk) {
                $ids = implode(',', array_map('intval', $chunk));

                // Remove optional links/classes first. Some Dolibarr installs keep references here.
                if (!empty($this->getTableColumns(MAIN_DB_PREFIX . 'bank_url'))) {
                    $count = $this->countRows(MAIN_DB_PREFIX . 'bank_url', 'fk_bank IN (' . $ids . ')');
                    if (!$this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'bank_url WHERE fk_bank IN (' . $ids . ')')) {
                        $this->db->rollback();
                        return array(false, 'Could not delete bank_url links: ' . $this->db->lasterror());
                    }
                    $deletedLinks += $count;
                }
                if (!empty($this->getTableColumns(MAIN_DB_PREFIX . 'bank_class'))) {
                    $count = $this->countRows(MAIN_DB_PREFIX . 'bank_class', 'lineid IN (' . $ids . ')');
                    if (!$this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'bank_class WHERE lineid IN (' . $ids . ')')) {
                        $this->db->rollback();
                        return array(false, 'Could not delete bank_class rows: ' . $this->db->lasterror());
                    }
                    $deletedClasses += $count;
                }

                $before = $this->countRows(MAIN_DB_PREFIX . 'bank', 'rowid IN (' . $ids . ')');
                if (!$this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'bank WHERE rowid IN (' . $ids . ')')) {
                    $this->db->rollback();
                    return array(false, 'Could not delete synced bank lines: ' . $this->db->lasterror());
                }
                $deletedBank += $before;
            }
        }

        $deletedLogs = $this->countRows($table, 'entity=' . (int) $this->conf->entity);
        $sql = 'DELETE FROM ' . $table . ' WHERE entity=' . (int) $this->conf->entity;
        if (!$this->db->query($sql)) {
            $this->db->rollback();
            return array(false, 'Could not clear WooBankSync log: ' . $this->db->lasterror());
        }
        $this->db->commit();

        return array(true, 'Desync complete. Deleted ' . (int) $deletedBank . ' bank line(s), ' . (int) $deletedLinks . ' bank link(s), ' . (int) $deletedClasses . ' bank class row(s), and ' . (int) $deletedLogs . ' WooBankSync log row(s).');
    }

    private function countRows($table, $where)
    {
        $res = $this->db->query('SELECT COUNT(*) as nb FROM ' . $table . ' WHERE ' . $where);
        if ($res && ($obj = $this->db->fetch_object($res))) return (int) $obj->nb;
        return 0;
    }

    public function runDatabaseChecks()
    {
        $messages = array();
        $prefix = MAIN_DB_PREFIX;
        $table = $prefix . 'woobanksync_log';

        $sql = "CREATE TABLE IF NOT EXISTS " . $table . " (" .
            "rowid integer AUTO_INCREMENT PRIMARY KEY," .
            "entity integer NOT NULL DEFAULT 1," .
            "woo_order_id varchar(64) NOT NULL," .
            "woo_order_number varchar(128) DEFAULT NULL," .
            "woo_transaction_id varchar(255) DEFAULT NULL," .
            "payment_method varchar(128) DEFAULT NULL," .
            "dolibarr_bank_account_id integer DEFAULT NULL," .
            "gross_amount double(24,8) DEFAULT 0," .
            "fee_amount double(24,8) DEFAULT 0," .
            "payout_amount double(24,8) DEFAULT 0," .
            "currency varchar(8) DEFAULT NULL," .
            "bank_line_id_gross integer DEFAULT NULL," .
            "bank_line_id_fee integer DEFAULT NULL," .
            "woo_invoice_number varchar(255) DEFAULT NULL," .
            "sync_status varchar(32) NOT NULL DEFAULT 'pending'," .
            "sync_message text," .
            "date_order datetime DEFAULT NULL," .
            "date_sync datetime DEFAULT NULL," .
            "tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" .
            ") ENGINE=innodb";
        if (!$this->db->query($sql)) {
            return array(false, 'Database check failed while creating log table: ' . $this->db->lasterror());
        }
        $messages[] = 'Log table is ready.';

        $columns = array(
            'payout_amount' => 'double(24,8) DEFAULT 0',
            'woo_invoice_number' => 'varchar(255) DEFAULT NULL',
            'sync_message' => 'text',
            'date_sync' => 'datetime DEFAULT NULL',
            'woo_invoice_pdf_url' => 'varchar(500) DEFAULT NULL',
            'pdf_ecm_filepath' => 'varchar(500) DEFAULT NULL',
        );
        foreach ($columns as $column => $definition) {
            $resql = $this->db->query("SHOW COLUMNS FROM " . $table . " LIKE '" . $this->db->escape($column) . "'");
            if ($resql && $this->db->num_rows($resql) == 0) {
                if (!$this->db->query("ALTER TABLE " . $table . " ADD COLUMN " . $column . " " . $definition)) {
                    return array(false, 'Database check failed while adding column ' . $column . ': ' . $this->db->lasterror());
                }
                $messages[] = 'Added column ' . $column . '.';
            }
        }

        $resql = $this->db->query("SHOW INDEX FROM " . $table . " WHERE Key_name='uk_woobanksync_entity_order'");
        if ($resql && $this->db->num_rows($resql) == 0) {
            if (!$this->db->query("ALTER TABLE " . $table . " ADD UNIQUE KEY uk_woobanksync_entity_order (entity, woo_order_id)")) {
                $messages[] = 'Unique key could not be added, maybe duplicate old rows exist: ' . $this->db->lasterror();
            } else {
                $messages[] = 'Unique key is ready.';
            }
        }

        $cacheTable = $prefix . 'woobanksync_order_cache';
        $sql = "CREATE TABLE IF NOT EXISTS " . $cacheTable . " (" .
            "rowid integer AUTO_INCREMENT PRIMARY KEY," .
            "entity integer NOT NULL DEFAULT 1," .
            "woo_order_id varchar(64) NOT NULL," .
            "woo_order_number varchar(128) DEFAULT NULL," .
            "woo_invoice_number varchar(255) DEFAULT NULL," .
            "woo_invoice_pdf_url varchar(500) DEFAULT NULL," .
            "pdf_ecm_filepath varchar(500) DEFAULT NULL," .
            "raw_order_json longtext DEFAULT NULL," .
            "date_updated datetime DEFAULT NULL," .
            "UNIQUE KEY uk_wbs_order_cache (entity, woo_order_id)" .
            ") ENGINE=innodb";
        if (!$this->db->query($sql)) {
            return array(false, 'Database check failed while creating order cache table: ' . $this->db->lasterror());
        }
        $messages[] = 'Order cache table is ready.';

        $resql = $this->db->query("SHOW COLUMNS FROM " . $cacheTable . " LIKE 'raw_order_json'");
        if ($resql && $this->db->num_rows($resql) == 0) {
            if (!$this->db->query("ALTER TABLE " . $cacheTable . " ADD COLUMN raw_order_json longtext DEFAULT NULL AFTER pdf_ecm_filepath")) {
                return array(false, 'Database check failed while adding raw_order_json: ' . $this->db->lasterror());
            }
            $messages[] = 'Added full WooCommerce order JSON cache.';
        }

        return array(true, implode(' ', $messages));
    }

    public function createDocumentFolder()
    {
        $folderId = $this->findOrCreateEcmFolder('Woo Invoices');
        if ($folderId > 0) {
            $this->setConst('WBS_DOCUMENT_FOLDER_ID', (string) $folderId, 'chaine');
        }
        return array($folderId > 0, $folderId > 0 ? 'Woo Invoices ECM folder is ready.' : 'Could not create/find ECM folder. Is Documents/ECM module enabled?');
    }

    public function getBankExtraFields()
    {
        $fields = array();
        $sql = 'SELECT name, label FROM ' . MAIN_DB_PREFIX . "extrafields WHERE elementtype='bank' AND entity IN (0," . (int) $this->conf->entity . ") AND type IN ('varchar','text') ORDER BY pos, label";
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $code = (string) $obj->name;
                if (!isset($fields[$code])) $fields[$code] = (string) $obj->label;
            }
        }
        return $fields;
    }

    public function createAndMapInvoiceBankExtraField()
    {
        $mapped = trim((string) $this->getConst('WBS_BANK_EXTRAFIELD_CODE', ''));
        if ($mapped !== '') return array(false, 'A bank-entry custom field is already mapped.');

        $code = 'woo_invoice_number';
        $fields = $this->getBankExtraFields();
        if (!isset($fields[$code])) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
            $extrafields = new ExtraFields($this->db);
            $result = $extrafields->addExtraField(
                $code,
                'WooCommerce invoice number',
                'varchar',
                100,
                '255',
                'bank',
                0,
                0,
                '',
                '',
                1,
                '',
                '1',
                'Invoice number imported from WooCommerce',
                '',
                (string) $this->conf->entity,
                '',
                '1'
            );
            if ($result <= 0) {
                $error = !empty($extrafields->error) ? $extrafields->error : $this->db->lasterror();
                return array(false, 'Could not create the bank-entry custom field: ' . $error);
            }
        }

        $this->setConst('WBS_BANK_EXTRAFIELD_CODE', $code, 'chaine');
        $this->setConst('WBS_BANK_EXTRAFIELD_ENABLED', '1', 'yesno');
        return array(true, 'Bank-entry custom field "WooCommerce invoice number" was created or reused, mapped, and enabled.');
    }

    public function saveGatewayMapFromPost()
    {
        $gateways = $this->getJsonConst('WBS_GATEWAYS_JSON', array());
        $map = array();
        foreach ($gateways as $gateway) {
            $gid = (string) $gateway['id'];
            $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $gid);
            $map[$gid] = array(
                'bank_id' => (int) GETPOST('WBS_MAP_BANK_' . $safe, 'int'),
                'fee_key' => GETPOST('WBS_MAP_FEE_' . $safe, 'restricthtml'),
                'payout_key' => GETPOST('WBS_MAP_PAYOUT_' . $safe, 'restricthtml'),
            );
        }
        $this->setConst('WBS_GATEWAY_MAP_JSON', json_encode($map), 'chaine');
    }

    public function gatewayMap()
    {
        $map = $this->getJsonConst('WBS_GATEWAY_MAP_JSON', array());
        if (!empty($map)) return $map;

        $legacy = array();
        foreach ($this->csvToArray($this->getConst('WBS_GATEWAY_PAYPAL')) as $g) $legacy[$g] = array('bank_id' => (int) $this->getConst('WBS_PAYPAL_BANK_ID'), 'fee_key' => '', 'payout_key' => '');
        foreach ($this->csvToArray($this->getConst('WBS_GATEWAY_STRIPE')) as $g) $legacy[$g] = array('bank_id' => (int) $this->getConst('WBS_STRIPE_BANK_ID'), 'fee_key' => '', 'payout_key' => '');
        foreach ($this->csvToArray($this->getConst('WBS_GATEWAY_AMAZONPAY')) as $g) $legacy[$g] = array('bank_id' => (int) $this->getConst('WBS_AMAZONPAY_BANK_ID'), 'fee_key' => '', 'payout_key' => '');
        foreach ($this->csvToArray($this->getConst('WBS_GATEWAY_BANK')) as $g) $legacy[$g] = array('bank_id' => (int) $this->getConst('WBS_DIRECT_BANK_ID'), 'fee_key' => '', 'payout_key' => '');
        return $legacy;
    }

    private function resolveGatewayConfig($paymentMethod, $map)
    {
        $paymentMethod = (string) $paymentMethod;
        if (!empty($map[$paymentMethod]) && !empty($map[$paymentMethod]['bank_id'])) {
            $map[$paymentMethod]['_mapped_from'] = $paymentMethod;
            return $map[$paymentMethod];
        }

        $aliases = $this->gatewayAliases($paymentMethod);
        foreach ($aliases as $alias) {
            if (!empty($map[$alias]) && !empty($map[$alias]['bank_id'])) {
                $map[$alias]['_mapped_from'] = $alias;
                return $map[$alias];
            }
        }

        return array();
    }

    private function gatewayAliases($paymentMethod)
    {
        $paymentMethod = strtolower((string) $paymentMethod);
        $aliases = array();

        if ($paymentMethod === 'paypal') {
            $aliases = array('ppcp-gateway', 'paypal_standard', 'paypal_express');
        } elseif ($paymentMethod === 'ppcp-gateway') {
            $aliases = array('paypal', 'paypal_standard', 'paypal_express');
        } elseif (strpos($paymentMethod, 'paypal') !== false || strpos($paymentMethod, 'ppcp') !== false) {
            $aliases = array('ppcp-gateway', 'paypal');
        } elseif (strpos($paymentMethod, 'stripe') !== false && strpos($paymentMethod, 'amazon') !== false) {
            $aliases = array('stripe_amazon_pay', 'amazon_pay', 'amazonpay');
        } elseif (strpos($paymentMethod, 'amazon') !== false) {
            $aliases = array('stripe_amazon_pay', 'amazon_pay', 'amazonpay');
        } elseif (strpos($paymentMethod, 'klarna') !== false) {
            $aliases = array('stripe_klarna', 'klarna');
        } elseif (strpos($paymentMethod, 'stripe') !== false) {
            $aliases = array('stripe');
        }

        return array_values(array_unique(array_filter($aliases, static function ($alias) use ($paymentMethod) { return $alias !== $paymentMethod; })));
    }

    private function discoverMetaKeysByGateway($orders)
    {
        $result = array();
        foreach ($orders as $order) {
            $gateway = (string) ($order['payment_method'] ?? '');
            if ($gateway === '') continue;
            if (empty($result[$gateway])) $result[$gateway] = array();
            foreach (($order['meta_data'] ?? array()) as $meta) {
                $key = (string) ($meta['key'] ?? '');
                if ($key === '') continue;
                if (!in_array($key, $result[$gateway], true)) $result[$gateway][] = $key;
            }
            sort($result[$gateway]);
        }
        return $result;
    }

    private function discoverInvoiceKeys($orders)
    {
        $keys = array();
        $needles = array('invoice', 'rechnung', 'germanized', '_wc_gzd', '_wc_gzdp', 'wcpdf', 'pdf_invoice', 'document_number');
        foreach ($orders as $order) {
            foreach (($order['meta_data'] ?? array()) as $meta) {
                $key = (string) ($meta['key'] ?? '');
                $lower = strtolower($key);
                foreach ($needles as $needle) {
                    if (strpos($lower, $needle) !== false && !in_array($key, $keys, true)) $keys[] = $key;
                }
            }
        }
        sort($keys);
        return $keys;
    }

    private function extractBuyerName($order)
    {
        $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : array();
        $first = trim((string) ($billing['first_name'] ?? ''));
        $last = trim((string) ($billing['last_name'] ?? ''));
        $company = trim((string) ($billing['company'] ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name === '') $name = $company;
        if ($name === '') $name = trim((string) ($order['customer_note'] ?? ''));
        $name = preg_replace('/\s+/', ' ', $name);
        return dol_trunc($name, 80);
    }

    private function extractWooInvoiceNumber($order)
    {
        if ((int) $this->getConst('WBS_GERMANIZED_PRO_ENABLED', '0') !== 1) return '';

        // StoreaBill / Germanized Pro embeds invoice data directly in the order REST response
        // under $order['invoices']. This is not in meta_data.
        if (!empty($order['invoices']) && is_array($order['invoices'])) {
            foreach ($order['invoices'] as $invoice) {
                if (!empty($invoice['formatted_number'])) return (string) $invoice['formatted_number'];
                if (!empty($invoice['number'])) return (string) $invoice['number'];
            }
        }

        $configuredKeys = $this->getJsonConst('WBS_WOO_INVOICE_KEYS_JSON', array());
        $defaultKeys = array(
            '_wc_gzd_invoice_number',
            '_wc_gzd_order_invoice_number',
            '_wc_gzd_document_invoice_number',
            '_wc_gzd_document_number',
            '_wc_gzd_document_data',
            '_wc_gzd_invoice',
            '_wc_gzd_invoices',
            '_wc_gzdp_invoice_number',
            '_wc_gzdp_invoice_id',
            '_wcpdf_invoice_number',
            '_wcpdf_invoice_number_data',
            '_wpo_wcpdf_invoice_number',
            '_wpo_wcpdf_invoice_number_data',
            '_bewpi_invoice_number',
            '_invoice_number',
            'invoice_number',
            'document_number'
        );
        $keys = array_unique(array_merge($configuredKeys, $defaultKeys));

        // First pass: exact known keys.
        foreach (($order['meta_data'] ?? array()) as $meta) {
            $key = (string) ($meta['key'] ?? '');
            foreach ($keys as $wanted) {
                if (strcasecmp($key, (string) $wanted) === 0) {
                    $found = $this->extractInvoiceNumberFromValue($meta['value'] ?? '', $this->invoiceMetaKeyAllowsNumericValue($key));
                    if ($found !== '') return $found;
                }
            }
        }

        // Second pass: heuristic over all invoice/document/rechnung meta keys. This catches
        // Germanized document payloads where the real number is nested in an array.
        foreach (($order['meta_data'] ?? array()) as $meta) {
            $key = strtolower((string) ($meta['key'] ?? ''));
            if ($this->looksLikeInvoiceMetaKey($key)) {
                $found = $this->extractInvoiceNumberFromValue($meta['value'] ?? '', $this->invoiceMetaKeyAllowsNumericValue($key));
                if ($found !== '') return $found;
            }
        }

        return '';
    }

    private function looksLikeInvoiceMetaKey($key)
    {
        $needles = array('invoice', 'rechnung', 'gzd_document', 'document_invoice', 'document_number', 'wcpdf');
        foreach ($needles as $needle) {
            if (strpos((string) $key, $needle) !== false) return true;
        }
        return false;
    }

    private function invoiceMetaKeyAllowsNumericValue($key)
    {
        $key = strtolower((string) $key);
        if (strpos($key, 'id') !== false && !preg_match('/number|nummer|_no(?:_|$)/', $key)) return false;
        return (bool) preg_match('/number|nummer|_no(?:_|$)/', $key);
    }

    private function extractInvoiceNumberFromValue($value, $allowNumeric = false)
    {
        if (is_object($value)) $value = (array) $value;
        if (is_scalar($value)) {
            $value = trim((string) $value);
            if ($value === '') return '';
            // Pure numeric IDs such as invoice_id/document_id are usually internal post IDs,
            // not invoice numbers. Numeric values are valid for explicit number/nummer fields.
            if (!$allowNumeric && preg_match('/^\d{1,7}$/', $value)) return '';
            return dol_trunc($value, 120);
        }
        if (!is_array($value)) return '';

        $preferred = array(
            'formatted_number',
            'number_formatted',
            'invoice_number',
            'document_number',
            'formatted_invoice_number',
            'formatted_document_number',
            'number',
            'invoiceNo',
            'invoice_no',
            'documentNo',
            'document_no'
        );

        foreach ($preferred as $field) {
            if (isset($value[$field])) {
                $found = $this->extractInvoiceNumberFromValue($value[$field], true);
                if ($found !== '') return $found;
            }
        }

        foreach ($value as $k => $v) {
            $kl = strtolower((string) $k);
            if (strpos($kl, 'id') !== false && !preg_match('/number|nummer|no/', $kl)) continue;
            if (preg_match('/number|nummer|invoice|rechnung|document|beleg/', $kl)) {
                $found = $this->extractInvoiceNumberFromValue($v, $this->invoiceMetaKeyAllowsNumericValue($kl));
                if ($found !== '') return $found;
            }
        }

        // Last resort: recursively inspect nested arrays.
        foreach ($value as $v) {
            if (is_array($v) || is_object($v)) {
                $found = $this->extractInvoiceNumberFromValue($v);
                if ($found !== '') return $found;
            }
        }
        return '';
    }

    private function extractWooInvoicePdfUrl($order)
    {
        if ((int) $this->getConst('WBS_GERMANIZED_PRO_ENABLED', '0') !== 1) return '';

        $pdfUrl = $this->extractWooInvoicePdfUrlFromOrder($order);
        if ($pdfUrl !== '') return $pdfUrl;

        // Fallback: call the Germanized document endpoint for this specific order.
        // Bulk resync intentionally does not use this fallback because it can cause
        // several HTTP requests per order and exceed the PHP request timeout.
        if (!empty($order['id'])) {
            return $this->gzdPdfUrl((int) $order['id'], $order);
        }

        return '';
    }

    private function filesystemPathToWebUrl($path)
    {
        foreach (array('/wp-content/', '/wp-includes/') as $marker) {
            $pos = strpos((string) $path, $marker);
            if ($pos !== false) {
                $wooUrl = rtrim((string) $this->getConst('WBS_WOO_URL', ''), '/');
                if ($wooUrl !== '') return $wooUrl . substr($path, $pos);
            }
        }
        return '';
    }

    public function extractPdfUrlFromLiveOrder(array $order)
    {
        $urlFields = array('download_url', 'file_url', 'pdf_url', 'url');

        foreach ((array) ($order['invoices'] ?? array()) as $invoice) {
            foreach ($urlFields as $f) {
                $v = trim((string) ($invoice[$f] ?? ''));
                if ($v !== '') return $v;
            }
            $path = trim((string) ($invoice['path'] ?? ''));
            if ($path !== '') {
                $w = $this->filesystemPathToWebUrl($path);
                if ($w !== '') return $w;
            }
        }

        foreach ((array) ($order['shipments'] ?? array()) as $shipment) {
            foreach ((array) ($shipment['invoices'] ?? array()) as $invoice) {
                foreach ($urlFields as $f) {
                    $v = trim((string) ($invoice[$f] ?? ''));
                    if ($v !== '') return $v;
                }
                $path = trim((string) ($invoice['path'] ?? ''));
                if ($path !== '') {
                    $w = $this->filesystemPathToWebUrl($path);
                    if ($w !== '') return $w;
                }
            }
        }

        return '';
    }

    private function extractWooInvoicePdfUrlFromOrder($order)
    {
        if ((int) $this->getConst('WBS_GERMANIZED_PRO_ENABLED', '0') !== 1) return '';
        return $this->extractPdfUrlFromLiveOrder($order);
    }

    private function gzdPdfUrl($orderId, $order = null)
    {
        $url = (string) $this->getConst('WBS_WOO_URL', '');
        $key = (string) $this->getConst('WBS_WOO_CONSUMER_KEY', '');
        $secret = (string) $this->getConst('WBS_WOO_CONSUMER_SECRET', '');
        if ($url === '' || $key === '' || $secret === '') return '';
        $gzd = new WbsGermanizedClient($url, $key, $secret);
        return $gzd->getInvoicePdfUrl($orderId, $order);
    }

    private function formatInvoiceReferenceForLabel($invoiceNumber)
    {
        $invoiceNumber = trim((string) $invoiceNumber);
        if (preg_match('/^\d+$/', $invoiceNumber)) return '#' . $invoiceNumber;
        return $invoiceNumber;
    }

    private function autoDetectFee($order)
    {
        $candidates = array();
        foreach (($order['meta_data'] ?? array()) as $meta) {
            $key = strtolower((string) ($meta['key'] ?? ''));
            if (strpos($key, 'fee') !== false || strpos($key, 'fees') !== false || strpos($key, 'gebuehr') !== false || strpos($key, 'gebühr') !== false) {
                $amount = abs($this->normalizeAmount($meta['value'] ?? 0));
                if ($amount > 0) $candidates[] = $amount;
            }
        }
        return !empty($candidates) ? max($candidates) : 0.0;
    }

    private function extractAmountFromConfiguredKey($order, $key)
    {
        $key = trim((string) $key);
        if ($key === '') return 0.0;
        foreach (($order['meta_data'] ?? array()) as $meta) {
            if (strcasecmp((string) ($meta['key'] ?? ''), $key) === 0) return abs($this->normalizeAmount($meta['value'] ?? 0));
        }
        return 0.0;
    }

    private function insertBankLine($bankId, $amount, $label, $dateSql)
    {
        $now = $this->sqlDateNow();
        $datev = !empty($dateSql) ? "'" . $this->db->escape($dateSql) . "'" : $now;
        $labelEsc = $this->db->escape($label);
        $amount = price2num($amount, 'MT');

        $fields = $this->getTableColumns(MAIN_DB_PREFIX . 'bank');
        $data = array();
        $this->addDataIfColumn($data, $fields, 'datec', $now, false);
        $this->addDataIfColumn($data, $fields, 'datev', $datev, false);
        $this->addDataIfColumn($data, $fields, 'dateo', $datev, false);
        $this->addDataIfColumn($data, $fields, 'amount', $amount, true);
        $this->addDataIfColumn($data, $fields, 'label', "'" . $labelEsc . "'", false);
        $this->addDataIfColumn($data, $fields, 'fk_account', (int) $bankId, true);
        $this->addDataIfColumn($data, $fields, 'rappro', 0, true);
        $this->addDataIfColumn($data, $fields, 'entity', (int) $this->conf->entity, true);
        $this->addDataIfColumn($data, $fields, 'num_chq', "''", false);

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'bank (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_values($data)) . ')';
        $res = $this->db->query($sql);
        if (!$res) return 0;
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'bank');
    }

    private function setBankInvoiceNumber($bankLineId, $invoiceNumber)
    {
        if (!$this->nativeInvoiceReferenceEnabled() || empty($invoiceNumber) || $bankLineId <= 0) return;
        $fields = $this->getTableColumns(MAIN_DB_PREFIX . 'bank');
        if (in_array('num_chq', $fields, true)) {
            $sql = 'UPDATE ' . MAIN_DB_PREFIX . "bank SET num_chq='" . $this->db->escape((string) $invoiceNumber) . "' WHERE rowid=" . (int) $bankLineId;
            $this->db->query($sql);
        }
    }

    private function nativeInvoiceReferenceEnabled()
    {
        return (int) $this->getConst('WBS_DOCUMENT_SYNC_ENABLED', '0') === 1;
    }

    private function setBankInvoiceExtraField($bankLineId, $invoiceNumber)
    {
        if ((int) $this->getConst('WBS_BANK_EXTRAFIELD_ENABLED', '0') !== 1 || empty($invoiceNumber) || $bankLineId <= 0) return true;

        $code = trim((string) $this->getConst('WBS_BANK_EXTRAFIELD_CODE', ''));
        if ($code === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $code)) return false;

        $table = MAIN_DB_PREFIX . 'bank_extrafields';
        $columns = $this->getTableColumns($table);
        if (!in_array('fk_object', $columns, true) || !in_array($code, $columns, true)) return false;

        $resql = $this->db->query('SELECT rowid FROM ' . $table . ' WHERE fk_object=' . (int) $bankLineId . ' LIMIT 1');
        if (!$resql) return false;

        $value = "'" . $this->db->escape((string) $invoiceNumber) . "'";
        if ($obj = $this->db->fetch_object($resql)) {
            $sql = 'UPDATE ' . $table . ' SET ' . $code . '=' . $value . ' WHERE rowid=' . (int) $obj->rowid;
        } else {
            $sql = 'INSERT INTO ' . $table . ' (fk_object,' . $code . ') VALUES (' . (int) $bankLineId . ',' . $value . ')';
        }
        return (bool) $this->db->query($sql);
    }

    private function findPatternInData($data, $pattern, &$found)
    {
        if (is_string($data)) {
            if ($data !== '' && preg_match($pattern, $data, $m)) {
                $found = (string) $m[1];
                return true;
            }
            return false;
        }
        if (is_array($data)) {
            foreach ($data as $val) {
                if ($this->findPatternInData($val, $pattern, $found)) return true;
            }
        }
        return false;
    }

    public function detectStoreaBillFolder()
    {
        $pattern = '#/wp-content/uploads/(storeabill-[a-z0-9]+)/#i';

        // Step 1: parse PDF URLs already stored in the local cache (zero extra HTTP calls)
        foreach (array(MAIN_DB_PREFIX . 'woobanksync_order_cache', MAIN_DB_PREFIX . 'woobanksync_log') as $table) {
            $sql = 'SELECT woo_invoice_pdf_url FROM ' . $table
                . ' WHERE entity=' . (int) $this->conf->entity
                . " AND woo_invoice_pdf_url IS NOT NULL AND woo_invoice_pdf_url != '' LIMIT 100";
            $resql = $this->db->query($sql);
            if ($resql) {
                while ($obj = $this->db->fetch_object($resql)) {
                    $url = (string) ($obj->woo_invoice_pdf_url ?? '');
                    if (preg_match($pattern, $url, $m)) {
                        $folder = (string) $m[1];
                        $this->setConst('WBS_STOREABILL_FOLDER', $folder, 'chaine');
                        return array(true, 'Detected from cached PDF URLs: ' . $folder);
                    }
                }
            }
        }

        // Step 2: probe WooCommerce — fetch recent orders and check every known URL field
        $client = $this->client();
        $recentOrders = $client->getRecentOrders(5);
        if ($recentOrders === false) {
            return array(false, 'No StoreaBill URLs in cache and WooCommerce connection failed: ' . $client->error);
        }
        if (empty($recentOrders)) {
            return array(false, 'No StoreaBill URLs in cache and no recent orders found in WooCommerce.');
        }

        $gzd = new WbsGermanizedClient(
            (string) $this->getConst('WBS_WOO_URL', ''),
            (string) $this->getConst('WBS_WOO_CONSUMER_KEY', ''),
            (string) $this->getConst('WBS_WOO_CONSUMER_SECRET', '')
        );

        foreach (array_slice($recentOrders, 0, 5) as $recent) {
            $orderId = (int) ($recent['id'] ?? 0);
            if ($orderId <= 0) continue;
            $orderNum = (string) ($recent['number'] ?? $orderId);

            // Both URL paths and absolute filesystem paths contain /uploads/storeabill-xxx/
            $scanPattern = '#/uploads/(storeabill-[a-z0-9]+)/#i';
            $found = '';

            // 2a: single-order endpoint — recursively scan every string value (invoices, attachments, meta_data, etc.)
            $fullOrder = $gzd->getFullOrder($orderId);
            if ($fullOrder !== false) {
                if ($this->findPatternInData($fullOrder, $scanPattern, $found)) {
                    $this->setConst('WBS_STOREABILL_FOLDER', $found, 'chaine');
                    return array(true, 'Detected from order #' . $orderNum . ' single-order response: ' . $found);
                }
            }

            // 2b: Germanized document endpoint — recursively scan the full document objects
            $docs = $gzd->getOrderDocuments($orderId);
            if ($docs !== false) {
                if ($this->findPatternInData($docs, $scanPattern, $found)) {
                    $this->setConst('WBS_STOREABILL_FOLDER', $found, 'chaine');
                    return array(true, 'Detected from Germanized document endpoint (order #' . $orderNum . '): ' . $found);
                }
            }
        }

        return array(false, 'Probed ' . count(array_slice($recentOrders, 0, 5)) . ' recent orders — no StoreaBill URL found in any response field. Use "Inspect WooCommerce order meta" in Diagnostics to see the raw API response. Then use the PDF download test in the Setup page to test a URL manually.');
    }

    public function testFetchPdfUrl($url)
    {
        return $this->fetchPdfContent((string) $url);
    }

    public function downloadInvoicePdfPublic($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $force = false)
    {
        return $this->downloadAndStoreInvoicePdf($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $force);
    }

    public function updateCacheEcmPath($orderId, $ecmFilepath)
    {
        $escaped = $this->db->escape($ecmFilepath);
        $oid = $this->db->escape((string) $orderId);
        $entity = (int) $this->conf->entity;
        $now = $this->sqlDateNow();
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "woobanksync_order_cache SET pdf_ecm_filepath='" . $escaped . "', date_updated=" . $now . " WHERE entity=" . $entity . " AND woo_order_id='" . $oid . "'");
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "woobanksync_log SET pdf_ecm_filepath='" . $escaped . "' WHERE entity=" . $entity . " AND woo_order_id='" . $oid . "' AND sync_status='synced'");
    }

    public function isInvoicePdfStored($ecmFilepath)
    {
        $ecmFilepath = trim((string) $ecmFilepath, '/\\');
        if ($ecmFilepath === '') return false;

        $base = !empty($this->conf->ecm->dir_output)
            ? rtrim($this->conf->ecm->dir_output, '/\\')
            : (defined('DOL_DATA_ROOT') ? rtrim(DOL_DATA_ROOT, '/\\') . '/ecm' : '');
        if ($base === '') return false;

        return is_file($base . '/' . $ecmFilepath);
    }

    public function getPendingPdfOrders($force = false)
    {
        $rows = array();
        $e = (int) $this->conf->entity;

        if ($force) {
            // Force: all synced orders from the log — live WooCommerce fetch happens per-order in download_pdf_single.
            // No JOIN with cache and no reference to dynamically-added columns to avoid column-existence errors.
            $sql = 'SELECT woo_order_id, woo_order_number'
                . " FROM " . MAIN_DB_PREFIX . 'woobanksync_log'
                . ' WHERE entity=' . $e . " AND sync_status='synced' ORDER BY rowid DESC";
        } else {
            $sql = 'SELECT woo_order_id, woo_order_number, woo_invoice_number, woo_invoice_pdf_url, pdf_ecm_filepath'
                . ' FROM ' . MAIN_DB_PREFIX . 'woobanksync_order_cache'
                . ' WHERE entity=' . $e
                . " AND woo_invoice_pdf_url IS NOT NULL AND woo_invoice_pdf_url != ''"
                . ' ORDER BY rowid DESC';
        }

        $resql = $this->db->query($sql);
        if (!$resql) return $rows;
        while ($obj = $this->db->fetch_object($resql)) {
            if (!$force) {
                $ecmFilepath = (string) ($obj->pdf_ecm_filepath ?? '');
                if ($this->isInvoicePdfStored($ecmFilepath)) continue;
                if ($ecmFilepath !== '') {
                    $this->updateCacheEcmPath((string) $obj->woo_order_id, '');
                }
            }
            $rows[] = array(
                'id' => (string) $obj->woo_order_id,
                'number' => (string) $obj->woo_order_number,
                'invoice' => (string) ($obj->woo_invoice_number ?? ''),
                'pdf_url' => (string) ($obj->woo_invoice_pdf_url ?? ''),
            );
        }
        return $rows;
    }

    public function getCachedOrderJsonRows()
    {
        $rows = array();
        $table = MAIN_DB_PREFIX . 'woobanksync_order_cache';
        if (!in_array('raw_order_json', $this->getTableColumns($table), true)) return $rows;

        $sql = 'SELECT woo_order_id, woo_order_number, woo_invoice_number, date_updated'
            . ' FROM ' . $table
            . ' WHERE entity=' . (int) $this->conf->entity
            . " AND raw_order_json IS NOT NULL AND raw_order_json != ''"
            . ' ORDER BY rowid DESC';
        $resql = $this->db->query($sql);
        if (!$resql) return $rows;

        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'id' => (string) $obj->woo_order_id,
                'number' => (string) $obj->woo_order_number,
                'invoice' => (string) ($obj->woo_invoice_number ?? ''),
                'date_updated' => (string) ($obj->date_updated ?? ''),
            );
        }
        return $rows;
    }

    public function getCachedOrderJson($orderId)
    {
        $table = MAIN_DB_PREFIX . 'woobanksync_order_cache';
        if (!in_array('raw_order_json', $this->getTableColumns($table), true)) return null;

        $sql = 'SELECT raw_order_json FROM ' . $table
            . ' WHERE entity=' . (int) $this->conf->entity
            . " AND woo_order_id='" . $this->db->escape((string) $orderId) . "'"
            . " AND raw_order_json IS NOT NULL AND raw_order_json != '' LIMIT 1";
        $resql = $this->db->query($sql);
        if (!$resql || !($obj = $this->db->fetch_object($resql))) return null;
        return (string) $obj->raw_order_json;
    }

    public function getFullCacheRefreshOrders($limit = 0)
    {
        $rows = array();
        $sql = 'SELECT woo_order_id, woo_order_number FROM ' . MAIN_DB_PREFIX . 'woobanksync_log'
            . ' WHERE entity=' . (int) $this->conf->entity
            . " AND sync_status='synced' ORDER BY rowid DESC";
        if ($limit > 0) $sql .= ' LIMIT ' . (int) $limit;
        $resql = $this->db->query($sql);
        if (!$resql) return $rows;
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'id' => (string) $obj->woo_order_id,
                'number' => (string) $obj->woo_order_number,
            );
        }
        return $rows;
    }

    public function refreshFullCacheBatch(array $orderIds, $storeJson = false)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        $batchSize = max(1, min(100, (int) $this->getConst('WBS_CACHE_BATCH_SIZE', '1')));
        $ids = array_slice($ids, 0, $batchSize);
        if (empty($ids)) return array('updated' => 0, 'errors' => 0, 'items' => array());

        $client = $this->client();
        // When storeJson=true (setup/debug mode) fetch all fields; otherwise minimal fields for speed
        $fields = $storeJson ? array() : array('id', 'number', 'billing', 'meta_data', 'invoices');
        $orders = $client->getOrdersByIds($ids, $fields);
        if ($orders === false) {
            return array('updated' => 0, 'errors' => count($ids), 'items' => array(), 'error' => $client->error);
        }

        $ordersById = array();
        foreach ($orders as $order) {
            if (!empty($order['id'])) $ordersById[(string) $order['id']] = $order;
        }

        $germanizedEnabled = (int) $this->getConst('WBS_GERMANIZED_PRO_ENABLED', '0') === 1;
        $gzd = null;
        if ($germanizedEnabled) {
            $gzd = new WbsGermanizedClient(
                (string) $this->getConst('WBS_WOO_URL', ''),
                (string) $this->getConst('WBS_WOO_CONSUMER_KEY', ''),
                (string) $this->getConst('WBS_WOO_CONSUMER_SECRET', '')
            );
        }

        $result = array('updated' => 0, 'errors' => 0, 'items' => array());
        foreach ($ids as $id) {
            $key = (string) $id;
            if (empty($ordersById[$key])) {
                $result['errors']++;
                $result['items'][] = array('id' => $key, 'ok' => false, 'message' => 'Order not returned by WooCommerce');
                continue;
            }

            $order = $ordersById[$key];
            $invoiceNumber = $this->extractWooInvoiceNumber($order);
            $pdfUrl = $this->extractWooInvoicePdfUrlFromOrder($order);

            // Only call Germanized if the invoice number is still missing after parsing order data
            if ($germanizedEnabled && $gzd !== null && $invoiceNumber === '') {
                $gzdData = $gzd->getOrderDocumentData($id);
                if (!empty($gzdData['invoice_number'])) $invoiceNumber = (string) $gzdData['invoice_number'];
                if (!empty($gzdData['invoice_pdf_url'])) $pdfUrl = (string) $gzdData['invoice_pdf_url'];
            }

            $cacheRow = $this->getOrderCacheState($key);
            if ($invoiceNumber === '') $invoiceNumber = (string) ($cacheRow['invoice_number'] ?? '');
            if ($pdfUrl === '') $pdfUrl = (string) ($cacheRow['pdf_url'] ?? '');
            $orderNumber = (string) ($order['number'] ?? ($cacheRow['order_number'] ?? $key));
            $ecmFilepath = (string) ($cacheRow['ecm_filepath'] ?? '');

            $this->upsertOrderCache($key, $orderNumber, $invoiceNumber, $pdfUrl, $ecmFilepath, $storeJson ? $order : null);

            $result['updated']++;
            $result['items'][] = array(
                'id' => $key,
                'number' => $orderNumber,
                'ok' => true,
                'germanized' => $germanizedEnabled,
            );
        }
        return $result;
    }

    private function getOrderCacheState($orderId)
    {
        $state = array();
        $sql = 'SELECT woo_order_number, woo_invoice_number, woo_invoice_pdf_url, pdf_ecm_filepath'
            . ' FROM ' . MAIN_DB_PREFIX . 'woobanksync_order_cache'
            . ' WHERE entity=' . (int) $this->conf->entity
            . " AND woo_order_id='" . $this->db->escape((string) $orderId) . "' LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && ($obj = $this->db->fetch_object($resql))) {
            $state = array(
                'order_number' => (string) $obj->woo_order_number,
                'invoice_number' => (string) ($obj->woo_invoice_number ?? ''),
                'pdf_url' => (string) ($obj->woo_invoice_pdf_url ?? ''),
                'ecm_filepath' => (string) ($obj->pdf_ecm_filepath ?? ''),
            );
        }
        return $state;
    }

    private function upsertOrderCache($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $ecmFilepath, $order = null)
    {
        $table = MAIN_DB_PREFIX . 'woobanksync_order_cache';
        $columns = $this->getTableColumns($table);
        if (empty($columns)) return;
        $e = (int) $this->conf->entity;
        $now = $this->sqlDateNow();
        $fields = array('entity', 'woo_order_id', 'woo_order_number', 'woo_invoice_number', 'woo_invoice_pdf_url', 'pdf_ecm_filepath');
        $values = array(
            (string) $e,
            "'" . $this->db->escape($orderId) . "'",
            "'" . $this->db->escape($orderNumber) . "'",
            "'" . $this->db->escape($invoiceNumber) . "'",
            "'" . $this->db->escape($pdfUrl) . "'",
            "'" . $this->db->escape($ecmFilepath) . "'",
        );
        $updates = array(
            'woo_order_number=VALUES(woo_order_number)',
            'woo_invoice_number=VALUES(woo_invoice_number)',
            'woo_invoice_pdf_url=VALUES(woo_invoice_pdf_url)',
            "pdf_ecm_filepath=IF(VALUES(pdf_ecm_filepath)!='',VALUES(pdf_ecm_filepath),pdf_ecm_filepath)",
        );

        if (in_array('raw_order_json', $columns, true)) {
            $rawJson = is_array($order) ? json_encode($order, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
            if ($rawJson === false) $rawJson = '';
            $fields[] = 'raw_order_json';
            $values[] = "'" . $this->db->escape($rawJson) . "'";
            $updates[] = "raw_order_json=IF(VALUES(raw_order_json)!='',VALUES(raw_order_json),raw_order_json)";
        }

        $fields[] = 'date_updated';
        $values[] = $now;
        $updates[] = 'date_updated=VALUES(date_updated)';
        $sql = "INSERT INTO " . $table . " (" . implode(',', $fields) . ")"
            . " VALUES (" . implode(',', $values) . ")"
            . " ON DUPLICATE KEY UPDATE " . implode(',', $updates);
        $this->db->query($sql);
    }

    private function downloadAndStoreInvoicePdf($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $force = false)
    {
        if (empty($pdfUrl)) return '';
        $folderId = (int) $this->getConst('WBS_DOCUMENT_FOLDER_ID', '0');
        if ($folderId <= 0) {
            $folderId = (int) $this->findOrCreateEcmFolder('Woo Invoices');
            if ($folderId > 0) {
                $this->setConst('WBS_DOCUMENT_FOLDER_ID', (string) $folderId, 'chaine');
            } else {
                return '';
            }
        }

        $sql = 'SELECT relpath, fullpath, filepath FROM ' . MAIN_DB_PREFIX . 'ecm_directories WHERE rowid=' . $folderId . ' AND entity=' . (int) $this->conf->entity;
        $resql = $this->db->query($sql);
        if (!$resql || !($obj = $this->db->fetch_object($resql))) return '';
        $relpath = trim((string) (!empty($obj->relpath) ? $obj->relpath : (!empty($obj->fullpath) ? $obj->fullpath : $obj->filepath)), '/');
        if ($relpath === '') return '';

        $base = !empty($this->conf->ecm->dir_output) ? rtrim($this->conf->ecm->dir_output, '/\\') : (defined('DOL_DATA_ROOT') ? DOL_DATA_ROOT . '/ecm' : '');
        if ($base === '') return '';

        $dir = $base . '/' . $relpath;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return '';

        $safe = static function ($s) { return preg_replace('/[^a-zA-Z0-9\-_]/', '-', trim((string) $s)); };
        $ts = time();
        $filename = 'woo-' . $safe($orderNumber) . ($invoiceNumber !== '' ? '-' . $safe($invoiceNumber) : '') . '-dld-' . $ts . '.pdf';
        $filepath = $dir . '/' . $filename;
        $ecmRelPath = $relpath . '/' . $filename;

        $content = $this->fetchPdfContent($pdfUrl);
        if ($content === false || strlen($content) < 64) return '';
        if (file_put_contents($filepath, $content) === false) return '';

        $this->registerEcmFile($folderId, $filename, $ecmRelPath, $orderId, $invoiceNumber);
        return $ecmRelPath;
    }

    private function fetchPdfContent($url)
    {
        $this->pdfLog = array();

        // Convert filesystem path to web URL if the stored value is not an HTTP URL
        if (!preg_match('#^https?://#i', $url)) {
            $converted = $this->filesystemPathToWebUrl($url);
            if ($converted !== '') {
                $this->pdfLog[] = '[0] filesystem path converted to web URL: ' . $converted;
                $url = $converted;
            } else {
                $this->pdfLog[] = '[0] value is not an HTTP URL and WooCommerce base URL is not configured: ' . $url;
                return false;
            }
        }

        $key = (string) $this->getConst('WBS_WOO_CONSUMER_KEY', '');
        $secret = (string) $this->getConst('WBS_WOO_CONSUMER_SECRET', '');

        // Step 1: StoreaBill static files have query strings for tracking but the bare path is public.
        $urlPath = (string) @parse_url($url, PHP_URL_PATH);
        if ($urlPath !== '' && strtolower(substr($urlPath, -4)) === '.pdf' && strpos($url, '?') !== false) {
            $cleanUrl = (string) strstr($url, '?', true);
            if ($cleanUrl !== '') {
                $this->pdfLog[] = '[1] bare URL: ' . $cleanUrl;
                $body = $this->curlGet($cleanUrl, '', '');
                if ($body !== false && substr($body, 0, 4) === '%PDF') {
                    $this->pdfLog[] = '[1] OK — got PDF (' . strlen($body) . ' bytes)';
                    return $body;
                }
                $this->pdfLog[] = '[1] failed: ' . ($body === false ? 'connection error' : substr($body, 0, 80));
            }
        }

        // Step 2: WooCommerce REST download endpoints need credentials as URL params.
        if ($key !== '' && $secret !== '' && strpos($url, '/wp-json/') !== false) {
            $sep = strpos($url, '?') !== false ? '&' : '?';
            $authUrl = $url . $sep . 'consumer_key=' . urlencode($key) . '&consumer_secret=' . urlencode($secret);
            $this->pdfLog[] = '[2] WC REST with credentials: ' . $authUrl;
            $body = $this->curlGet($authUrl, '', '');
            if ($body !== false && substr($body, 0, 4) === '%PDF') {
                $this->pdfLog[] = '[2] OK — got PDF (' . strlen($body) . ' bytes)';
                return $body;
            }
            $this->pdfLog[] = '[2] failed: ' . ($body === false ? 'connection error' : substr($body, 0, 80));
        }

        // Step 3: self-authenticated URL (order-key embedded) — try as-is.
        $this->pdfLog[] = '[3] as-is (no credentials): ' . $url;
        $body = $this->curlGet($url, '', '');
        if ($body !== false && substr($body, 0, 4) === '%PDF') {
            $this->pdfLog[] = '[3] OK — got PDF (' . strlen($body) . ' bytes)';
            return $body;
        }
        $this->pdfLog[] = '[3] failed: ' . ($body === false ? 'connection error' : substr($body, 0, 80));

        // Step 4: Basic auth header.
        if ($key !== '' && $secret !== '') {
            $this->pdfLog[] = '[4] Basic auth header: ' . $url;
            $body = $this->curlGet($url, $key, $secret);
            if ($body !== false && substr($body, 0, 4) === '%PDF') {
                $this->pdfLog[] = '[4] OK — got PDF (' . strlen($body) . ' bytes)';
                return $body;
            }
            $this->pdfLog[] = '[4] failed: ' . ($body === false ? 'connection error' : substr($body, 0, 80));
        }

        $this->pdfLog[] = 'All attempts failed for: ' . $url;
        return false;
    }

    private function curlGet($url, $key, $secret)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Dolibarr WooBankSync/1.1');
        if ($key !== '' && $secret !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $key . ':' . $secret);
        }
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode < 200 || $httpCode >= 300 || empty($body)) return false;
        return $body;
    }

    private function registerEcmFile($folderId, $filename, $relPath, $orderId, $invoiceNumber)
    {
        $fields = $this->getTableColumns(MAIN_DB_PREFIX . 'ecm_files');
        if (empty($fields)) return;
        $label = pathinfo($filename, PATHINFO_FILENAME);
        $data = array();
        $this->addDataIfColumn($data, $fields, 'label', "'" . $this->db->escape($label) . "'", false);
        $this->addDataIfColumn($data, $fields, 'filename', "'" . $this->db->escape($filename) . "'", false);
        $this->addDataIfColumn($data, $fields, 'filepath', "'" . $this->db->escape($relPath) . "'", false);
        $this->addDataIfColumn($data, $fields, 'fullpath_orig', "'" . $this->db->escape($relPath) . "'", false);
        $this->addDataIfColumn($data, $fields, 'fk_parent', (int) $folderId, true);
        $this->addDataIfColumn($data, $fields, 'entity', (int) $this->conf->entity, true);
        $this->addDataIfColumn($data, $fields, 'fk_user_c', isset($GLOBALS['user']->id) ? (int) $GLOBALS['user']->id : 0, true);
        $this->addDataIfColumn($data, $fields, 'date_c', $this->sqlDateNow(), false);
        $this->addDataIfColumn($data, $fields, 'note', "'" . $this->db->escape('WooBankSync order #' . $orderId . ($invoiceNumber !== '' ? ' / ' . $invoiceNumber : '')) . "'", false);
        $this->addDataIfColumn($data, $fields, 'keywords', "'" . $this->db->escape('woobanksync woo ' . $orderId) . "'", false);
        $this->addDataIfColumn($data, $fields, 'status', 1, true);
        $this->addDataIfColumn($data, $fields, 'position', 0, true);
        if (empty($data)) return;
        $sql = 'INSERT IGNORE INTO ' . MAIN_DB_PREFIX . 'ecm_files (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_values($data)) . ')';
        $this->db->query($sql);
    }

    private function findOrCreateEcmFolder($label)
    {
        $table = MAIN_DB_PREFIX . 'ecm_directories';
        if (empty($this->getTableColumns($table))) return 0;
        $sql = 'SELECT rowid FROM ' . $table . " WHERE entity=" . (int) $this->conf->entity . " AND label='" . $this->db->escape($label) . "' LIMIT 1";
        $res = $this->db->query($sql);
        if ($res && ($obj = $this->db->fetch_object($res))) return (int) $obj->rowid;

        $this->ensurePhysicalEcmFolder($label);
        $fields = $this->getTableColumns($table);
        $data = array();
        $relpath = $this->sanitizeEcmRelPath($label);
        $this->addDataIfColumn($data, $fields, 'label', "'" . $this->db->escape($label) . "'", false);
        $this->addDataIfColumn($data, $fields, 'description', "'WooCommerce PDF invoices / invoice references'", false);
        $this->addDataIfColumn($data, $fields, 'fullpath', "'" . $this->db->escape($relpath) . "'", false);
        $this->addDataIfColumn($data, $fields, 'relpath', "'" . $this->db->escape($relpath) . "'", false);
        $this->addDataIfColumn($data, $fields, 'filepath', "'" . $this->db->escape($relpath) . "'", false);
        $this->addDataIfColumn($data, $fields, 'cachenbofdoc', 0, true);
        $this->addDataIfColumn($data, $fields, 'fk_parent', 0, true);
        $this->addDataIfColumn($data, $fields, 'entity', (int) $this->conf->entity, true);
        $this->addDataIfColumn($data, $fields, 'date_c', $this->sqlDateNow(), false);
        $this->addDataIfColumn($data, $fields, 'fk_user_c', isset($GLOBALS['user']->id) ? (int) $GLOBALS['user']->id : 0, true);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_values($data)) . ')';
        $res = $this->db->query($sql);
        if (!$res) return 0;
        return (int) $this->db->last_insert_id($table);
    }

    private function sanitizeEcmRelPath($label)
    {
        $label = trim((string) $label);
        $label = str_replace(array('..', '/', '\\'), array('', ' ', ' '), $label);
        return $label !== '' ? $label : 'Woo Invoices';
    }

    private function ensurePhysicalEcmFolder($label)
    {
        $relpath = $this->sanitizeEcmRelPath($label);
        $base = '';
        if (!empty($this->conf->ecm->dir_output)) $base = $this->conf->ecm->dir_output;
        elseif (defined('DOL_DATA_ROOT')) $base = DOL_DATA_ROOT . '/ecm';
        if ($base === '') return false;
        $dir = rtrim($base, '/\\') . '/' . $relpath;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return is_dir($dir);
    }

    private function findOrCreateVirtualBankAccount($label, $ref, &$wasExisting = false)
    {
        $wasExisting = false;
        $table = MAIN_DB_PREFIX . 'bank_account';
        $safeRef = $this->makeUniqueSafeBankRef($ref);
        $sql = 'SELECT rowid FROM ' . $table . " WHERE entity IN (0," . (int) $this->conf->entity . ") AND (label='" . $this->db->escape($label) . "' OR ref='" . $this->db->escape($safeRef) . "') LIMIT 1";
        $res = $this->db->query($sql);
        if ($res && ($obj = $this->db->fetch_object($res))) {
            $wasExisting = true;
            if (strpos((string) $label, 'Woo ') !== 0) {
                $this->db->query('UPDATE ' . $table . " SET label='" . $this->db->escape($label) . "' WHERE rowid=" . (int) $obj->rowid . " AND label LIKE 'Woo %'");
            }
            return (int) $obj->rowid;
        }

        if (file_exists(DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php')) {
            require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
            $account = new Account($this->db);
            $account->ref = $safeRef;
            $account->label = $label;
            $account->bank = 'Virtual payment clearing account';
            $account->number = $safeRef;
            $account->account_number = $safeRef;
            $account->courant = 1;
            $account->clos = 0;
            $account->entity = (int) $this->conf->entity;
            $account->currency_code = !empty($this->conf->currency) ? $this->conf->currency : 'EUR';
            $user = isset($GLOBALS['user']) ? $GLOBALS['user'] : null;
            $id = method_exists($account, 'create') ? $account->create($user) : 0;
            if ($id > 0) return (int) $id;
        }

        $fields = $this->getTableColumns($table);
        $data = array();
        $this->addDataIfColumn($data, $fields, 'ref', "'" . $this->db->escape($safeRef) . "'", false);
        $this->addDataIfColumn($data, $fields, 'label', "'" . $this->db->escape($label) . "'", false);
        $this->addDataIfColumn($data, $fields, 'bank', "'Virtual WooCommerce clearing account'", false);
        $this->addDataIfColumn($data, $fields, 'number', "'" . $this->db->escape($safeRef) . "'", false);
        $this->addDataIfColumn($data, $fields, 'account_number', "'" . $this->db->escape($safeRef) . "'", false);
        $this->addDataIfColumn($data, $fields, 'courant', 1, true);
        $this->addDataIfColumn($data, $fields, 'clos', 0, true);
        $this->addDataIfColumn($data, $fields, 'rappro', 0, true);
        $this->addDataIfColumn($data, $fields, 'entity', (int) $this->conf->entity, true);
        $this->addDataIfColumn($data, $fields, 'datec', $this->sqlDateNow(), false);
        $this->addDataIfColumn($data, $fields, 'fk_user_author', isset($GLOBALS['user']->id) ? (int) $GLOBALS['user']->id : 0, true);
        $this->addDataIfColumn($data, $fields, 'fk_user_creat', isset($GLOBALS['user']->id) ? (int) $GLOBALS['user']->id : 0, true);
        $this->addDataIfColumn($data, $fields, 'currency_code', "'" . $this->db->escape(!empty($this->conf->currency) ? $this->conf->currency : 'EUR') . "'", false);
        $this->addDataIfColumn($data, $fields, 'fk_pays', (int) $this->getDefaultCountryId(), true);
        if (empty($data)) return 0;
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_values($data)) . ')';
        $res = $this->db->query($sql);
        if (!$res) return 0;
        return (int) $this->db->last_insert_id($table);
    }


    private function makeShortBankRef($gatewayId)
    {
        $gatewayId = strtolower((string) $gatewayId);
        $parts = preg_split('/[^a-z0-9]+/', $gatewayId, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($parts)) $parts = array('woo');

        $ref = '';
        foreach ($parts as $part) {
            $part = preg_replace('/[^a-z0-9]/', '', $part);
            if ($part === '') continue;
            $take = (strlen($part) <= 4) ? strlen($part) : 4;
            $ref .= substr($part, 0, $take);
            if (strlen($ref) >= 8) break;
        }
        if ($ref === '') $ref = 'woo';
        $ref = strtoupper(substr($ref, 0, 8));
        return $ref;
    }

    private function makeUniqueSafeBankRef($baseRef)
    {
        $baseRef = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $baseRef));
        if ($baseRef === '') $baseRef = 'WOO';
        $baseRef = substr($baseRef, 0, 8);

        $table = MAIN_DB_PREFIX . 'bank_account';
        $candidate = $baseRef;
        for ($i = 0; $i < 100; $i++) {
            if ($i > 0) {
                $suffix = (string) $i;
                $candidate = substr($baseRef, 0, max(1, 8 - strlen($suffix))) . $suffix;
            }
            $sql = 'SELECT rowid FROM ' . $table . " WHERE ref='" . $this->db->escape($candidate) . "' LIMIT 1";
            $res = $this->db->query($sql);
            if ($res && $this->db->num_rows($res) == 0) return $candidate;
        }
        return substr($baseRef, 0, 6) . mt_rand(10, 99);
    }


    private function getDefaultCountryId()
    {
        $countryCode = '';
        if (!empty($GLOBALS['conf']->global->MAIN_INFO_SOCIETE_COUNTRY)) {
            $countryCode = (string) $GLOBALS['conf']->global->MAIN_INFO_SOCIETE_COUNTRY;
        }
        if ($countryCode === '') $countryCode = 'DE';
        $countryCode = strtoupper(trim($countryCode));

        $table = MAIN_DB_PREFIX . 'c_country';
        $fields = $this->getTableColumns($table);
        if (empty($fields)) return 0;

        $where = array();
        if (in_array('code', $fields, true)) $where[] = "code='" . $this->db->escape($countryCode) . "'";
        if (in_array('code_iso', $fields, true)) $where[] = "code_iso='" . $this->db->escape($countryCode) . "'";
        if (empty($where)) return 0;

        $sql = 'SELECT rowid FROM ' . $table . ' WHERE (' . implode(' OR ', $where) . ') LIMIT 1';
        $res = $this->db->query($sql);
        if ($res && ($obj = $this->db->fetch_object($res))) return (int) $obj->rowid;

        // German Dolibarr installations commonly use DE as company country for this module use-case.
        $sql = 'SELECT rowid FROM ' . $table . " WHERE code='DE' OR code_iso='DE' LIMIT 1";
        $res = $this->db->query($sql);
        if ($res && ($obj = $this->db->fetch_object($res))) return (int) $obj->rowid;

        return 0;
    }

    private function insertLog($order, $bankId, $gross, $fee, $bankLineGross, $bankLineFee, $status, $message, $dateOrder, $invoiceNumber = '', $payoutAmount = 0, $pdfUrl = '', $pdfEcmFilepath = '')
    {
        $fields = $this->getTableColumns(MAIN_DB_PREFIX . 'woobanksync_log');
        $data = array(
            'entity' => (int) $this->conf->entity,
            'woo_order_id' => "'" . $this->db->escape((string) ($order['id'] ?? '')) . "'",
            'woo_order_number' => "'" . $this->db->escape((string) ($order['number'] ?? ($order['id'] ?? ''))) . "'",
            'woo_transaction_id' => "'" . $this->db->escape((string) ($order['transaction_id'] ?? '')) . "'",
            'payment_method' => "'" . $this->db->escape((string) ($order['payment_method'] ?? '')) . "'",
            'dolibarr_bank_account_id' => (int) $bankId,
            'gross_amount' => price2num($gross, 'MT'),
            'fee_amount' => price2num($fee, 'MT'),
            'payout_amount' => price2num($payoutAmount, 'MT'),
            'currency' => "'" . $this->db->escape((string) ($order['currency'] ?? 'EUR')) . "'",
            'bank_line_id_gross' => (int) $bankLineGross,
            'bank_line_id_fee' => (int) $bankLineFee,
            'woo_invoice_number' => "'" . $this->db->escape((string) $invoiceNumber) . "'",
            'sync_status' => "'" . $this->db->escape($status) . "'",
            'sync_message' => "'" . $this->db->escape($message) . "'",
            'date_order' => !empty($dateOrder) ? "'" . $this->db->escape($dateOrder) . "'" : 'NULL',
            'date_sync' => $this->sqlDateNow(),
            'woo_invoice_pdf_url' => "'" . $this->db->escape((string) $pdfUrl) . "'",
            'pdf_ecm_filepath' => "'" . $this->db->escape((string) $pdfEcmFilepath) . "'",
        );
        foreach (array_keys($data) as $key) if (!in_array($key, $fields, true)) unset($data[$key]);
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'woobanksync_log (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_values($data)) . ')';
        return $this->db->query($sql);
    }

    private function isOrderSynced($orderId)
    {
        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'woobanksync_log WHERE entity=' . (int) $this->conf->entity . " AND woo_order_id='" . $this->db->escape((string) $orderId) . "' AND sync_status IN ('synced','dryrun')";
        $res = $this->db->query($sql);
        return ($res && $this->db->num_rows($res) > 0);
    }

    private function addDataIfColumn(&$data, $fields, $key, $value, $numeric)
    {
        if (in_array($key, $fields, true)) $data[$key] = $numeric ? (string) $value : (string) $value;
    }

    private function getTableColumns($table)
    {
        static $cache = array();
        if (isset($cache[$table])) return $cache[$table];
        $columns = array();
        $res = $this->db->query('SHOW COLUMNS FROM ' . $table);
        if ($res) while ($obj = $this->db->fetch_object($res)) $columns[] = $obj->Field;
        $cache[$table] = $columns;
        return $columns;
    }

    private function normalizeAmount($value)
    {
        if (is_array($value)) {
            if (isset($value['amount'])) $value = $value['amount'];
            elseif (isset($value['value'])) $value = $value['value'];
            else return 0.0;
        }
        $value = str_replace(array('€', ' ', ','), array('', '', '.'), (string) $value);
        return (float) $value;
    }

    private function sqlDateNow()
    {
        return "'" . $this->db->escape(dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S')) . "'";
    }

    private function wooDateToSql($date)
    {
        if (empty($date)) return dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S');
        $ts = strtotime((string) $date);
        if ($ts === false) return dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S');
        return date('Y-m-d H:i:s', $ts);
    }


    private function setConst($name, $value, $type = 'chaine')
    {
        if (function_exists('dolibarr_set_const')) {
            dolibarr_set_const($this->db, $name, $value, $type, 0, '', $this->conf->entity);
        } else {
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "const WHERE name='" . $this->db->escape($name) . "' AND entity=" . ((int) $this->conf->entity);
            $resql = $this->db->query($sql);
            if ($resql && ($obj = $this->db->fetch_object($resql))) {
                $sql = "UPDATE " . MAIN_DB_PREFIX . "const SET value='" . $this->db->escape((string) $value) . "', type='" . $this->db->escape($type) . "', visible=0, note='' WHERE rowid=" . ((int) $obj->rowid);
            } else {
                $sql = "INSERT INTO " . MAIN_DB_PREFIX . "const (name, entity, value, type, visible, note) VALUES ('" . $this->db->escape($name) . "', " . ((int) $this->conf->entity) . ", '" . $this->db->escape((string) $value) . "', '" . $this->db->escape($type) . "', 0, '')";
            }
            $this->db->query($sql);
        }
        if (!isset($this->conf->global)) $this->conf->global = new stdClass();
        $this->conf->global->$name = $value;
    }

    private function getConst($name, $default = '')
    {
        return isset($this->conf->global->$name) ? $this->conf->global->$name : $default;
    }

    public function getJsonConst($name, $default = array())
    {
        $raw = $this->getConst($name, '');
        if ($raw === '') return $default;
        $json = json_decode($raw, true);
        return is_array($json) ? $json : $default;
    }

    private function csvToArray($csv)
    {
        $items = array_map('trim', explode(',', (string) $csv));
        return array_values(array_filter($items, static function ($item) { return $item !== ''; }));
    }
}
