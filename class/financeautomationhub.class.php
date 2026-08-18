<?php

require_once __DIR__ . '/fahwoocommerceclient.class.php';

class FinanceAutomationHub
{
    private $db;
    private $conf;
    private $langs;
    private $integrationManager = null;
    private $inventoryManager = null;
    public $errors = array();
    public $pdfLog = array();
    public $lastSabInvoiceNumber = '';

    public function __construct($db, $conf, $langs)
    {
        $this->db = $db;
        $this->conf = $conf;
        $this->langs = $langs;
    }

    private function integrationManager()
    {
        if ($this->integrationManager === null) {
            require_once __DIR__ . '/../helpers/FahIntegrationManager.php';
            $this->integrationManager = new FahIntegrationManager($this->db, $this->conf);
        }
        return $this->integrationManager;
    }

    private function integrations()
    {
        return $this->integrationManager()->getDetected();
    }

    public function inventory()
    {
        if ($this->inventoryManager === null) {
            require_once __DIR__ . '/fahinventory.class.php';
            $this->inventoryManager = new FahInventoryManager($this->db, $this->conf);
        }
        return $this->inventoryManager;
    }

    public function client()
    {
        return new FahWooCommerceClient(
            $this->getConst('FAH_WOO_URL'),
            $this->getConst('FAH_WOO_CONSUMER_KEY'),
            $this->getConst('FAH_WOO_CONSUMER_SECRET')
        );
    }

    public function sync($limitPages = 1, $perPage = 20)
    {
        $stats = array('imported' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => array());
        $client = $this->client();
        $statuses = $this->csvToArray($this->getConst('FAH_ORDER_STATUSES', 'processing,completed'));
        $fromDate = $this->getConst('FAH_SYNC_FROM_DATE');

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

        $this->setConst('FAH_LAST_SYNC', dol_now(), 'chaine');
        return $stats;
    }

    public function syncBatch($page, $batchSize)
    {
        $page = max(1, (int) $page);
        $batchSize = max(1, min(100, (int) $batchSize));
        $stats = array(
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'messages' => array(),
            'items' => array(),
            'has_more' => false,
            'total_orders' => 0,
            'total_pages' => 0,
        );
        list($schemaOk, $schemaMessage) = $this->inventory()->ensureSchema();
        if (!$schemaOk) {
            $stats['errors'] = 1;
            $stats['messages'][] = 'Stock recipe database check failed: ' . $schemaMessage;
            return $stats;
        }
        if (!$this->inventory()->connectorEnabled('woocommerce')) {
            $stats['errors'] = 1;
            $stats['messages'][] = 'WooCommerce connector is disabled in setup.';
            return $stats;
        }
        $client = $this->client();
        $statuses = $this->csvToArray($this->getConst('FAH_ORDER_STATUSES', 'processing,completed'));
        $orders = $client->getOrders($statuses, $this->getConst('FAH_SYNC_FROM_DATE'), $page, $batchSize);
        if ($orders === false) {
            $stats['errors'] = 1;
            $stats['messages'][] = $client->error ?: 'WooCommerce request failed while fetching orders.';
            return $stats;
        }
        $stats['total_orders'] = (int) $client->lastTotalItems;
        $stats['total_pages'] = (int) $client->lastTotalPages;

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
        $this->setConst('FAH_LAST_SYNC', dol_now(), 'chaine');
        return $stats;
    }

    public function syncConnectorBatch($connector, $cursor = '', $batchSize = 50)
    {
        $connector = strtolower(trim((string) $connector));
        if ($connector === 'woocommerce') {
            $page = max(1, (int) $cursor);
            $stats = $this->syncBatch($page, $batchSize);
            $stats['next_cursor'] = !empty($stats['has_more']) ? (string) ($page + 1) : '';
            return $stats;
        }

        $stats = array(
            'imported' => 0, 'skipped' => 0, 'errors' => 0,
            'messages' => array(), 'items' => array(),
            'has_more' => false, 'next_cursor' => '', 'total_orders' => 0, 'total_pages' => 0,
        );
        if (!in_array($connector, array('amazon', 'sumup'), true)) {
            $stats['errors'] = 1;
            $stats['messages'][] = 'Unknown sales connector.';
            return $stats;
        }
        if (!$this->inventory()->connectorEnabled($connector)) {
            $stats['errors'] = 1;
            $stats['messages'][] = ucfirst($connector) . ' connector is disabled in setup.';
            return $stats;
        }

        $batchSize = max(1, min(100, (int) $batchSize));
        if ($connector === 'amazon') {
            require_once __DIR__ . '/fahamazonclient.class.php';
            $client = new FahAmazonClient(
                $this->getConst('FAH_AMAZON_LWA_CLIENT_ID'),
                $this->getConst('FAH_AMAZON_LWA_CLIENT_SECRET'),
                $this->getConst('FAH_AMAZON_REFRESH_TOKEN'),
                $this->getConst('FAH_AMAZON_SELLER_ID'),
                $this->getConst('FAH_AMAZON_MARKETPLACE_IDS'),
                $this->getConst('FAH_AMAZON_REGION', 'eu')
            );
            $response = $client->getOrders($this->getConst('FAH_AMAZON_SYNC_FROM_DATE'), (string) $cursor, $batchSize);
            if ($response === false) {
                $stats['errors'] = 1;
                $stats['messages'][] = $client->error ?: 'Amazon request failed.';
                return $stats;
            }
            $orders = $response['orders'];
            if ((int) $this->getConst('FAH_AMAZON_FINANCE_ENABLED', '1') === 1) {
                foreach ($orders as $orderIndex => $amazonOrder) {
                    $financials = $client->getOrderFinancials((string) ($amazonOrder['id'] ?? ''));
                    if ($financials === false) {
                        $orders[$orderIndex]['_fah_finance_available'] = false;
                        $orders[$orderIndex]['_fah_finance_error'] = $client->error;
                    } elseif (!empty($financials['available'])) {
                        $orders[$orderIndex]['fee'] = (float) $financials['fee'];
                        $orders[$orderIndex]['payout'] = (float) $financials['payout'];
                        $orders[$orderIndex]['_fah_fee_source'] = 'Amazon Finances API';
                        $orders[$orderIndex]['_fah_finance_available'] = true;
                        if (!empty($financials['currency'])) $orders[$orderIndex]['currency'] = (string) $financials['currency'];
                    } else {
                        $orders[$orderIndex]['_fah_finance_available'] = false;
                    }
                }
            }
            $stats['next_cursor'] = (string) $response['next_token'];
            $stats['has_more'] = $stats['next_cursor'] !== '';
        } else {
            require_once __DIR__ . '/fahsumupclient.class.php';
            $client = new FahSumUpClient($this->getConst('FAH_SUMUP_ACCESS_TOKEN'), $this->getConst('FAH_SUMUP_MERCHANT_CODE'));
            $response = $client->getTransactions($this->getConst('FAH_SUMUP_SYNC_FROM_DATE'), (string) $cursor, $batchSize);
            if ($response === false) {
                $stats['errors'] = 1;
                $stats['messages'][] = $client->error ?: 'SumUp request failed.';
                return $stats;
            }
            $orders = $response['orders'];
            $stats['next_cursor'] = !empty($response['has_more']) ? (string) $response['next_cursor'] : '';
            $stats['has_more'] = !empty($response['has_more']);
        }

        foreach ($orders as $order) {
            $result = $this->syncOneOrder($order, $connector);
            $status = isset($stats[$result['status']]) ? $result['status'] : 'errors';
            $stats[$status]++;
            if (!empty($result['message'])) $stats['messages'][] = $result['message'];
            $stats['items'][] = array(
                'id' => (string) ($order['id'] ?? ''),
                'number' => (string) ($order['number'] ?? ($order['id'] ?? '')),
                'status' => $status,
                'message' => (string) ($result['message'] ?? ''),
            );
        }
        $this->setConst('FAH_' . strtoupper($connector) . '_LAST_SYNC', dol_now(), 'chaine');
        return $stats;
    }

    public function refreshAmazonCatalog($maxPages = 500)
    {
        list($schemaOk, $schemaMessage) = $this->inventory()->ensureSchema();
        if (!$schemaOk) return array(false, $schemaMessage);
        require_once __DIR__ . '/fahamazonclient.class.php';
        $client = new FahAmazonClient(
            $this->getConst('FAH_AMAZON_LWA_CLIENT_ID'),
            $this->getConst('FAH_AMAZON_LWA_CLIENT_SECRET'),
            $this->getConst('FAH_AMAZON_REFRESH_TOKEN'),
            $this->getConst('FAH_AMAZON_SELLER_ID'),
            $this->getConst('FAH_AMAZON_MARKETPLACE_IDS'),
            $this->getConst('FAH_AMAZON_REGION', 'eu')
        );
        $token = '';
        $count = 0;
        for ($page = 0; $page < max(1, (int) $maxPages); $page++) {
            $response = $client->getListings($token, 20);
            if ($response === false) return array(false, $client->error);
            foreach ($response['products'] as $product) {
                $this->inventory()->upsertCatalogProduct('amazon', $product['product_id'], '', $product['sku'], $product['name']);
                $count++;
            }
            $token = (string) $response['next_token'];
            if ($token === '') break;
        }
        return array(true, 'Amazon catalogue refreshed: ' . $count . ' listings.');
    }

    public function refreshSumUpCatalog($maxPages = 500)
    {
        list($schemaOk, $schemaMessage) = $this->inventory()->ensureSchema();
        if (!$schemaOk) return array(false, $schemaMessage);
        require_once __DIR__ . '/fahsumupclient.class.php';
        $client = new FahSumUpClient($this->getConst('FAH_SUMUP_ACCESS_TOKEN'), $this->getConst('FAH_SUMUP_MERCHANT_CODE'));
        $cursor = '';
        $count = 0;
        for ($page = 0; $page < max(1, (int) $maxPages); $page++) {
            $response = $client->getTransactions($this->getConst('FAH_SUMUP_SYNC_FROM_DATE'), $cursor, 100);
            if ($response === false) return array(false, $client->error);
            foreach ($response['orders'] as $order) {
                $this->inventory()->learnOrderProducts('sumup', $order);
                $count += count((array) ($order['line_items'] ?? array()));
            }
            $cursor = (string) $response['next_cursor'];
            if (empty($response['has_more']) || $cursor === '') break;
        }
        return array(true, 'SumUp catalogue refreshed from ' . $count . ' transaction product lines.');
    }

    public function syncOneOrder($order, $connector = 'woocommerce')
    {
        $connector = strtolower(trim((string) $connector));
        if (!in_array($connector, array('woocommerce', 'amazon', 'sumup'), true)) $connector = 'woocommerce';
        $channelLabel = $connector === 'woocommerce' ? 'WooCommerce' : ($connector === 'sumup' ? 'SumUp' : 'Amazon');
        $order['_fah_connector'] = $connector;
        $orderId = (string) ($order['id'] ?? '');
        $orderNumber = isset($order['number']) ? (string) $order['number'] : $orderId;
        $paymentMethod = isset($order['payment_method']) ? trim((string) $order['payment_method']) : '';
        $transactionId = isset($order['transaction_id']) ? (string) $order['transaction_id'] : '';
        $gross = $this->normalizeAmount($order['total'] ?? 0);
        $currency = isset($order['currency']) ? (string) $order['currency'] : 'EUR';
        $dateOrder = $this->wooDateToSql($order['date_paid'] ?? ($order['date_created'] ?? null));
        $invoiceNumber = '';
        $pdfUrl = '';
        if ($connector === 'woocommerce') {
            foreach ($this->integrations() as $integration) {
                if ($invoiceNumber === '') $invoiceNumber = $integration->extractInvoiceNumber($order);
                if ($pdfUrl === '') $pdfUrl = $integration->extractPdfUrl($order);
            }
        }
        $orderStatus = isset($order['status']) ? (string) $order['status'] : '';
        if ($connector === 'sumup' && $this->isSumUpPosOwnedTransaction($order)) {
            $salesRecorded = $this->inventory()->recordOrderSales('sumup', $order, 'dolibarr_pos');
            if ($this->isOrderSynced($orderId, 'sumup')) {
                return array('status' => 'skipped', 'message' => 'Skipped SumUp transaction #' . $orderNumber . ': already recorded; POS duplicate protection remains active.');
            }
            $msg = 'Skipped SumUp transaction #' . $orderNumber . ': the configured Dolibarr POS integration owns this sale, so no duplicate bank or stock movement was created.' . ($salesRecorded ? '' : ' The sales analytics ledger could not be updated.');
            $status = $salesRecorded ? 'skipped' : 'error';
            $posFee = $this->normalizeAmount($order['fee'] ?? 0);
            $this->insertLog($order, 0, $gross, $posFee, 0, 0, $status, $msg, $dateOrder, '', max(0.0, $gross - $posFee));
            return array('status' => $salesRecorded ? 'skipped' : 'errors', 'message' => $msg);
        }
        $stockResult = $this->inventory()->processOrder($connector, $order);
        $stockMessage = $this->formatStockResult($stockResult);

        if ($this->isOrderSynced($orderId, $connector)) {
            if ($connector !== 'woocommerce' && !empty($order['_fah_fee_source'])) $this->updateSyncedConnectorFinancials($order, $connector);
            return array('status' => 'skipped', 'message' => 'Skipped ' . $channelLabel . ' order #' . $orderNumber . ': already synced.' . $stockMessage);
        }

        // Zero-total WooCommerce orders do not create real money movements.
        // This includes free/replacement/manual/fully-discounted orders and some refund/cancel edge cases.
        // Keep a log entry for traceability, but do not create a Dolibarr bank entry and do not count as an error.
        if ($gross <= 0) {
            $msg = 'Skipped ' . $channelLabel . ' order #' . $orderNumber . ': zero order total' . ($orderStatus !== '' ? ' (status=' . $orderStatus . ')' : '') . ', no bank entry created.' . $stockMessage;
            $this->insertLog($order, 0, $gross, 0, 0, 0, 'skipped', $msg, $dateOrder, $invoiceNumber, 0);
            return array('status' => 'skipped', 'message' => $msg);
        }

        // Some WooCommerce orders can be completed/processing without a payment gateway
        // (manual admin orders, legacy/imported orders, cancelled/refunded transitions, or unpaid/manual cases).
        // Those orders must not create bank movements and should not count as sync errors.
        if ($paymentMethod === '') {
            $msg = 'Skipped ' . $channelLabel . ' order #' . $orderNumber . ': empty payment method' . ($orderStatus !== '' ? ' (status=' . $orderStatus . ')' : '') . ', no bank entry created.' . $stockMessage;
            $this->insertLog($order, 0, $gross, 0, 0, 0, 'skipped', $msg, $dateOrder, $invoiceNumber, 0);
            return array('status' => 'skipped', 'message' => $msg);
        }

        $mappedPaymentMethod = $paymentMethod;
        if ($connector === 'woocommerce') {
            $map = $this->gatewayMap();
            $gatewayConfig = $this->resolveGatewayConfig($paymentMethod, $map);
        } else {
            $channelMap = $this->channelFinanceMap($connector);
            $gatewayConfig = !empty($channelMap[$paymentMethod]) ? $channelMap[$paymentMethod] : (!empty($channelMap[$connector]) ? $channelMap[$connector] : array());
            $gatewayConfig['fee_key'] = '';
            $gatewayConfig['payout_key'] = '';
        }
        $fee = $connector === 'woocommerce'
            ? $this->resolveWooFee($order, $gatewayConfig)
            : $this->normalizeAmount($order['fee'] ?? 0);
        if ($connector === 'woocommerce' && $fee <= 0 && !empty($order['_fah_fee_error'])) {
            $message = $channelLabel . ' order #' . $orderNumber . ': ' . $order['_fah_fee_error'] . ' No bank entry was created with an incorrect zero fee.' . $stockMessage;
            $this->insertLog($order, 0, $gross, 0, 0, 0, 'pending_finance', $message, $dateOrder, $invoiceNumber, 0);
            return array('status' => 'skipped', 'message' => $message);
        }
        $calculatedPayout = max(0.0, $gross - $fee);
        $providerPayout = $connector === 'woocommerce'
            ? $this->extractPayoutAmountFromConfiguredKey($order, $gatewayConfig['payout_key'] ?? '')
            : $this->normalizeAmount($order['payout'] ?? 0);

        if ($connector === 'amazon' && (int) $this->getConst('FAH_AMAZON_FINANCE_ENABLED', '1') === 1 && empty($order['_fah_finance_available'])) {
            $detail = !empty($order['_fah_finance_error']) ? ' ' . (string) $order['_fah_finance_error'] : ' Amazon notes that financial events may take up to 48 hours to appear.';
            $message = 'Amazon order #' . $orderNumber . ': stock and sales were processed, but the exact fee/proceeds are finance pending; no bank entry was created.' . $detail . $stockMessage;
            $this->insertLog($order, 0, $gross, 0, 0, 0, 'pending_finance', $message, $dateOrder, '', 0);
            return array('status' => 'skipped', 'message' => $message);
        }
        if (empty($gatewayConfig) || empty($gatewayConfig['bank_id'])) {
            if ($connector !== 'woocommerce') {
                $message = $channelLabel . ' order #' . $orderNumber . ': product/stock data processed; no optional bank account is mapped.' . $stockMessage;
                $this->insertLog($order, 0, $gross, $fee, 0, 0, 'skipped', $message, $dateOrder, $invoiceNumber, $providerPayout > 0 ? $providerPayout : $calculatedPayout);
                return array('status' => 'skipped', 'message' => $message);
            }
            $this->insertLog($order, 0, $gross, 0, 0, 0, 'error', 'No Dolibarr bank account mapping for gateway: ' . $paymentMethod, $dateOrder, $invoiceNumber, 0);
            return array('status' => 'errors', 'message' => $channelLabel . ' order #' . $orderNumber . ': missing bank mapping.' . $stockMessage);
        }
        if (!empty($gatewayConfig['_mapped_from'])) $mappedPaymentMethod = (string) $gatewayConfig['_mapped_from'];

        $bankId = (int) $gatewayConfig['bank_id'];
        // Read the raw payout amount the provider stored in WooCommerce meta.
        // Uses extractPayoutFromValue() so serialized PayPal structures (net_amount.value) are handled.
        $wooPayoutRaw = $providerPayout;

        // Sanity-check: reject values that are obviously wrong (equals fee, exceeds gross, negative).
        $wooPayoutSuspicious = $wooPayoutRaw > 0 && (
            $wooPayoutRaw > $gross ||
            ($fee > 0 && abs($wooPayoutRaw - $fee) < 0.005)
        );

        if ($wooPayoutRaw > 0 && !$wooPayoutSuspicious) {
            $payout = $wooPayoutRaw;
        } else {
            $payout = $calculatedPayout;
            if ($wooPayoutSuspicious) $wooPayoutRaw = 0.0; // do not store suspicious raw value
        }

        // Payout match status: used to generate the log message and drive UI colour coding.
        // 'ok'        — WC payout available and matches calculated (green)
        // 'mismatch'  — WC payout available but differs from calculated (amber)
        // 'no_source' — no payout meta key configured; calculated value used (neutral)
        if ($wooPayoutRaw > 0) {
            $payoutMatch = (abs($wooPayoutRaw - $calculatedPayout) < 0.005) ? 'ok' : 'mismatch';
        } else {
            $payoutMatch = 'no_source';
        }

        $dryRun = (int) $this->getConst('FAH_DRY_RUN', '0') === 1;
        $buyerName = $this->extractBuyerName($order);
        $labelBase = strtoupper($connector === 'woocommerce' ? 'WOO' : $connector) . ' - #' . $orderNumber;
        if (!empty($buyerName)) $labelBase .= ' ' . $buyerName;
        if ($this->nativeInvoiceReferenceEnabled() && !empty($invoiceNumber)) $labelBase .= ' - ' . $this->formatInvoiceReferenceForLabel($invoiceNumber);

        $this->db->begin();
        $bankLineId = 0;

        if (!$dryRun) {
            $bankLineId = $this->insertBankLine($bankId, $payout, $labelBase, $dateOrder);
            if ($bankLineId <= 0) {
                $this->db->rollback();
                $msg = 'Failed to insert bank line for ' . $channelLabel . ' order #' . $orderNumber . ': ' . $this->db->lasterror();
                $this->insertLog($order, $bankId, $gross, $fee, 0, 0, 'error', $msg, $dateOrder, $invoiceNumber, $payout);
                return array('status' => 'errors', 'message' => $msg);
            }
            if ($connector === 'woocommerce') {
                list($fieldsOk, $fieldsMessage) = $this->writeBankAmountExtraFields($bankLineId, $gross, $fee);
                if ($fieldsOk) $fieldsOk = $this->setBankInvoiceNumber($bankLineId, $invoiceNumber) && $this->setBankInvoiceExtraField($bankLineId, $invoiceNumber);
                if (!$fieldsOk) {
                    $this->db->rollback();
                    $msg = 'Could not write bank-entry custom fields for WooCommerce order #' . $orderNumber . ($fieldsMessage !== '' ? ': ' . $fieldsMessage : '.');
                    $this->insertLog($order, $bankId, $gross, $fee, 0, 0, 'error', $msg, $dateOrder, $invoiceNumber, $payout);
                    return array('status' => 'errors', 'message' => $msg);
                }
            }
        }

        $pdfEcmFilepath = '';
        if (!$dryRun) {
            if ($connector === 'woocommerce') {
                foreach ($this->integrations() as $integration) {
                    $result = $integration->tryDownloadPdf($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $this);
                    if ($result['ok']) { $pdfEcmFilepath = $result['filepath']; break; }
                }
            }
        }

        $status = $dryRun ? 'dryrun' : 'synced';
        if ($dryRun) {
            $message = '[DRY RUN] gross=' . price2num($gross, 'MT') . ' fee=' . price2num($fee, 'MT') . ' net=' . price2num($payout, 'MT');
        } elseif ($payoutMatch === 'mismatch') {
            $message = 'Payout mismatch: ' . $channelLabel . '=' . price2num($wooPayoutRaw, 'MT') . ' calculated=' . price2num($calculatedPayout, 'MT') . ' — using provider value';
        } else {
            $message = '';
        }
        $this->insertLog($order, $bankId, $gross, $fee, $bankLineId, 0, $status, $message, $dateOrder, $invoiceNumber, $payout, $pdfUrl, $pdfEcmFilepath, $wooPayoutRaw);
        if ($connector === 'woocommerce') $this->upsertOrderCache($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $pdfEcmFilepath, $order);
        $this->db->commit();

        $returnMsg = 'Synced ' . $channelLabel . ' order #' . $orderNumber . ' gross=' . price2num($gross, 'MT') . ' fee=' . price2num($fee, 'MT') . ' net=' . price2num($payout, 'MT');
        if ($payoutMatch === 'mismatch') $returnMsg .= ' [payout mismatch]';
        $returnMsg .= $stockMessage;
        return array('status' => 'imported', 'message' => $returnMsg);
    }

    private function formatStockResult(array $result)
    {
        $applied = (int) ($result['applied'] ?? 0);
        $already = (int) ($result['already'] ?? 0);
        $ignored = (int) ($result['ignored'] ?? 0);
        $unmapped = (int) ($result['unmapped'] ?? 0);
        $errors = (int) ($result['errors'] ?? 0);
        if ($applied + $already + $ignored + $unmapped + $errors === 0) return '';

        $parts = array();
        if ($applied > 0) $parts[] = $applied . ' deducted';
        if ($already > 0) $parts[] = $already . ' already applied';
        if ($ignored > 0) $parts[] = $ignored . ' ignored or disabled';
        if ($unmapped > 0) $parts[] = $unmapped . ' unmapped';
        if ($errors > 0) $parts[] = $errors . ' failed';
        $message = ' [stock: ' . implode(', ', $parts) . ']';
        if (!empty($result['messages'])) $message .= ' ' . implode(' | ', array_slice($result['messages'], 0, 3));
        return $message;
    }

    private function updateSyncedConnectorFinancials(array $order, $connector)
    {
        $orderId = (string) ($order['id'] ?? '');
        $fee = $this->normalizeAmount($order['fee'] ?? 0);
        $gross = $this->normalizeAmount($order['total'] ?? 0);
        $payout = $this->normalizeAmount($order['payout'] ?? 0);
        if ($payout <= 0) $payout = max(0.0, $gross - $fee);
        $resql = $this->db->query('SELECT rowid, bank_line_id_gross FROM ' . MAIN_DB_PREFIX . 'fah_sync_log WHERE entity=' . (int) $this->conf->entity
            . " AND connector='" . $this->db->escape($connector) . "' AND woo_order_id='" . $this->db->escape($orderId) . "' LIMIT 1");
        $row = $resql ? $this->db->fetch_object($resql) : null;
        if (!$row) return false;
        if (!empty($row->bank_line_id_gross)) {
            $this->db->query('UPDATE ' . MAIN_DB_PREFIX . 'bank SET amount=' . price2num($payout, 'MT') . ' WHERE rowid=' . (int) $row->bank_line_id_gross);
        }
        $source = (string) ($order['_fah_fee_source'] ?? ucfirst($connector) . ' API');
        return $this->db->query('UPDATE ' . MAIN_DB_PREFIX . 'fah_sync_log SET fee_amount=' . price2num($fee, 'MT')
            . ', payout_amount=' . price2num($payout, 'MT')
            . ", fee_source='" . $this->db->escape($source) . "', sync_message='Financial costs refreshed from " . $this->db->escape($source) . "'"
            . ' WHERE rowid=' . (int) $row->rowid);
    }

    private function isSumUpPosOwnedTransaction(array $order)
    {
        $mode = strtolower(trim((string) $this->getConst('FAH_SUMUP_POS_DUPLICATE_MODE', 'off')));
        if ($mode === 'all') return true;
        if ($mode !== 'reference') return false;

        $prefixes = $this->csvToArray($this->getConst('FAH_SUMUP_POS_REFERENCE_PREFIXES', ''));
        if (empty($prefixes)) return false;
        $references = (array) ($order['_fah_source_references'] ?? array());
        $references[] = (string) ($order['number'] ?? '');
        $references[] = (string) ($order['transaction_id'] ?? '');
        foreach ($references as $reference) {
            $reference = strtolower(trim((string) $reference));
            if ($reference === '') continue;
            foreach ($prefixes as $prefix) {
                $prefix = strtolower(trim((string) $prefix));
                if ($prefix !== '' && strpos($reference, $prefix) === 0) return true;
            }
        }
        return false;
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
        $statuses = $this->csvToArray($this->getConst('FAH_ORDER_STATUSES', 'processing,completed'));
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
        $invoiceAvailable = (!empty($invoiceKeys) || (int) $this->getConst('FAH_DOCUMENT_SYNC_ENABLED', '0') === 1) ? '1' : '0';

        $this->setConst('FAH_GATEWAYS_JSON', json_encode($allGateways), 'chaine');
        $this->setConst('FAH_META_KEYS_JSON', json_encode($meta), 'chaine');
        $this->setConst('FAH_WOO_INVOICE_KEYS_JSON', json_encode($invoiceKeys), 'chaine');
        $this->setConst('FAH_WOO_INVOICE_AVAILABLE', $invoiceAvailable, 'yesno');

        return array(true, 'Detected ' . count($allGateways) . ' relevant payment methods/gateways (' . count($orders) . ' recent orders scanned). Only active gateways and gateways used in existing orders are listed. Found ' . count($invoiceKeys) . ' possible invoice meta keys.');
    }

    public function refreshWooCatalog($maxPages = 100)
    {
        list($schemaOk, $schemaMessage) = $this->inventory()->ensureSchema();
        if (!$schemaOk) return array(false, $schemaMessage, array());

        $client = $this->client();
        $stats = array('products' => 0, 'variations' => 0, 'errors' => 0);
        for ($page = 1; $page <= max(1, (int) $maxPages); $page++) {
            $products = $client->getProducts($page, 100);
            if ($products === false) return array(false, $client->error, $stats);
            $productTotalPages = (int) $client->lastTotalPages;
            foreach ($products as $product) {
                $productId = (string) ($product['id'] ?? '');
                if ($productId === '') continue;
                $this->inventory()->upsertCatalogProduct(
                    'woocommerce',
                    $productId,
                    '',
                    (string) ($product['sku'] ?? ''),
                    (string) ($product['name'] ?? ('Product ' . $productId))
                );
                $stats['products']++;

                if (!empty($product['variations']) && is_array($product['variations'])) {
                    for ($variationPage = 1; $variationPage <= 100; $variationPage++) {
                        $variations = $client->getProductVariations((int) $productId, $variationPage, 100);
                        if ($variations === false) {
                            $stats['errors']++;
                            break;
                        }
                        foreach ($variations as $variation) {
                            $variationId = (string) ($variation['id'] ?? '');
                            if ($variationId === '') continue;
                            $attributes = array();
                            foreach (($variation['attributes'] ?? array()) as $attribute) {
                                $option = trim((string) ($attribute['option'] ?? ''));
                                if ($option !== '') $attributes[] = $option;
                            }
                            $label = (string) ($product['name'] ?? ('Product ' . $productId));
                            if (!empty($attributes)) $label .= ' — ' . implode(' / ', $attributes);
                            $this->inventory()->upsertCatalogProduct('woocommerce', $productId, $variationId, (string) ($variation['sku'] ?? ''), $label);
                            $stats['variations']++;
                        }
                        if (count($variations) < 100) break;
                    }
                }
            }
            if (count($products) < 100 || ($productTotalPages > 0 && $page >= $productTotalPages)) break;
        }
        return array(true, 'WooCommerce catalogue refreshed: ' . $stats['products'] . ' products and ' . $stats['variations'] . ' variations.', $stats);
    }

    public function backfillWooStock($limit = 2000)
    {
        if (!$this->inventory()->stockEnabled('woocommerce')) return array(false, 'Enable WooCommerce stock deduction before applying past sales.');
        $sql = 'SELECT woo_order_id FROM ' . MAIN_DB_PREFIX . 'fah_sync_log WHERE entity=' . (int) $this->conf->entity
            . $this->logConnectorCondition('woocommerce') . " AND sync_status IN ('synced','dryrun','pending_finance') ORDER BY rowid ASC LIMIT " . max(1, min(10000, (int) $limit));
        $resql = $this->db->query($sql);
        if (!$resql) return array(false, 'Could not read synced WooCommerce orders: ' . $this->db->lasterror());
        $ids = array();
        while ($row = $this->db->fetch_object($resql)) if ((int) $row->woo_order_id > 0) $ids[] = (int) $row->woo_order_id;
        if (empty($ids)) return array(true, 'No synced WooCommerce orders need a stock backfill.');

        $totals = array('applied' => 0, 'already' => 0, 'ignored' => 0, 'unmapped' => 0, 'errors' => 0, 'missing' => 0);
        $client = $this->client();
        foreach (array_chunk(array_values(array_unique($ids)), 50) as $chunk) {
            $orders = $client->getOrdersByIds($chunk, array('id', 'number', 'date_created', 'date_paid', 'line_items'));
            if ($orders === false) return array(false, 'WooCommerce stock backfill failed: ' . $client->error);
            $found = array();
            foreach ($orders as $order) {
                $found[(int) ($order['id'] ?? 0)] = true;
                $result = $this->inventory()->processOrder('woocommerce', $order);
                foreach (array('applied', 'already', 'ignored', 'unmapped', 'errors') as $key) $totals[$key] += (int) ($result[$key] ?? 0);
            }
            foreach ($chunk as $id) if (empty($found[$id])) $totals['missing']++;
        }
        $message = 'WooCommerce stock backfill complete: ' . $totals['applied'] . ' deductions applied, ' . $totals['already'] . ' already applied, '
            . $totals['unmapped'] . ' unmapped, ' . $totals['ignored'] . ' ignored, ' . $totals['errors'] . ' failed, ' . $totals['missing'] . ' missing orders.';
        return array($totals['errors'] + $totals['missing'] === 0, $message);
    }

    public function autoCreateAndMapAccounts()
    {
        $gateways = $this->getJsonConst('FAH_GATEWAYS_JSON', array());
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
        $this->setConst('FAH_GATEWAY_MAP_JSON', json_encode($map), 'chaine');
        $message = 'Created ' . $created . ', reused ' . $existing . ', mapped ' . ($created + $existing) . ' WooCommerce payment methods.';
        if (!empty($failed)) return array(false, $message . ' Failed: ' . implode(' | ', $failed));
        return array(true, $message);
    }


    public function getDifferenceCheckOrders()
    {
        $rows = array();
        $sql = 'SELECT woo_order_id, woo_order_number FROM ' . MAIN_DB_PREFIX . 'fah_sync_log'
            . ' WHERE entity=' . (int) $this->conf->entity
            . $this->logConnectorCondition('woocommerce')
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
        $table = MAIN_DB_PREFIX . 'fah_sync_log';

        $sql = 'SELECT * FROM ' . $table . ' WHERE entity=' . (int) $this->conf->entity . $this->logConnectorCondition('woocommerce') . " AND sync_status='synced' ORDER BY rowid DESC";
        if (!empty($orderIds)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
            $sql = 'SELECT * FROM ' . $table . ' WHERE entity=' . (int) $this->conf->entity
                . $this->logConnectorCondition('woocommerce')
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
        $hasIntegrations = !empty($this->integrations());
        $requiredFields = array('id', 'number', 'total', 'payment_method', 'billing', 'meta_data');
        if ($hasIntegrations) $requiredFields[] = 'invoices';

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
                $newInvoiceNumber = $oldInvoiceNumber;
                $newPdfUrl = $oldPdfUrl;
                // Difference checking uses only data already present in the Woo order response.
                // It never calls document endpoints (no extra HTTP).
                foreach ($this->integrations() as $integration) {
                    if ($newInvoiceNumber === '') $newInvoiceNumber = $integration->extractInvoiceNumber($order);
                    if ($newPdfUrl === '') $newPdfUrl = $integration->extractPdfUrl($order);
                }
                $newBuyerName = $this->extractBuyerName($order);
                $newGross = $this->normalizeAmount($order['total'] ?? 0);

                $paymentMethod = (string) ($order['payment_method'] ?? '');
                $gatewayConfig = $this->resolveGatewayConfig($paymentMethod, $map);
                $newFee = $this->resolveWooFee($order, $gatewayConfig);
                if ($newFee <= 0 && !empty($order['_fah_fee_error'])) {
                    $stats['errors']++;
                    $stats['messages'][] = 'Order #' . $logRow->woo_order_number . ': ' . $order['_fah_fee_error'];
                    $stats['items'][] = array('id' => $orderId, 'number' => (string) $logRow->woo_order_number, 'status' => 'error');
                    continue;
                }

                $oldGross = (float) ($logRow->gross_amount ?? 0);
                $oldFee = (float) ($logRow->fee_amount ?? 0);
                $oldEcmPath = (string) ($logRow->pdf_ecm_filepath ?? '');

                $invoiceDiff = $hasIntegrations && $newInvoiceNumber !== $oldInvoiceNumber;
                $grossDiff = abs($newGross - $oldGross) > 0.005;
                $feeDiff = abs($newFee - $oldFee) > 0.005;
                $pdfDownloadEnabled = (int) $this->getConst('FAH_PDF_DOWNLOAD_ENABLED', '0') === 1;
                $pdfMissing = $hasIntegrations && $pdfDownloadEnabled && $newPdfUrl !== '' && $oldEcmPath === '';
                $pdfUrlChanged = $hasIntegrations && $newPdfUrl !== $oldPdfUrl;

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
            $newPayout = max(0.0, $newGross - $newFee);
            $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'bank SET'
                . " label='" . $this->db->escape($labelBase) . "'"
                . ', amount=' . price2num($newPayout, 'MT')
                . ' WHERE rowid=' . (int) $logRow->bank_line_id_gross;
            if (!$this->db->query($sql)) { $this->db->rollback(); return false; }
        }

        $oldEcmPath = (string) ($logRow->pdf_ecm_filepath ?? '');
        $pdfEcmFilepath = $oldEcmPath;
        if ((int) $this->getConst('FAH_PDF_DOWNLOAD_ENABLED', '0') === 1) {
            $result = $this->downloadAndSavePdf($orderId, $orderNumber, $newInvoiceNumber, $newPdfUrl);
            if ($result['ok'] && !$result['already']) $pdfEcmFilepath = $result['filepath'];
        }

        $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'fah_sync_log SET'
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

    public function desyncAllSyncedEntries($deleteOwnedAccounts = false)
    {
        $table = MAIN_DB_PREFIX . 'fah_sync_log';
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
            return array(false, 'Could not read Finance Automation Hub log: ' . $this->db->lasterror());
        }

        // 2) Also include mapped virtual bank accounts so older rows whose ids were not logged can be found safely.
        $map = $this->gatewayMap();
        foreach ($map as $gatewayConfig) {
            if (!empty($gatewayConfig['bank_id'])) $bankAccountIds[] = (int) $gatewayConfig['bank_id'];
        }
        $bankAccountIds = array_values(array_unique(array_filter($bankAccountIds)));

        // 3) Fallback for older module versions: find rows by their legacy label pattern.
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
                if (!empty($this->getTableColumns(MAIN_DB_PREFIX . 'bank_extrafields'))
                    && !$this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'bank_extrafields WHERE fk_object IN (' . $ids . ')')) {
                    $this->db->rollback();
                    return array(false, 'Could not delete bank custom-field values: ' . $this->db->lasterror());
                }

                $before = $this->countRows(MAIN_DB_PREFIX . 'bank', 'rowid IN (' . $ids . ')');
                if (!$this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'bank WHERE rowid IN (' . $ids . ')')) {
                    $this->db->rollback();
                    return array(false, 'Could not delete synced bank lines: ' . $this->db->lasterror());
                }
                $deletedBank += $before;
            }
        }

        // 5) Collect PDF paths before deleting log rows
        $pdfPaths = array();
        $ecmBase = !empty($this->conf->ecm->dir_output)
            ? rtrim($this->conf->ecm->dir_output, '/\\')
            : (defined('DOL_DATA_ROOT') ? rtrim(DOL_DATA_ROOT, '/\\') . '/ecm' : '');
        $pdfResql = $this->db->query('SELECT * FROM ' . $table . ' WHERE entity=' . (int) $this->conf->entity);
        if ($pdfResql) {
            while ($pdfObj = $this->db->fetch_object($pdfResql)) {
                $path = trim((string) ($pdfObj->pdf_ecm_filepath ?? ''), '/\\');
                if ($path !== '') $pdfPaths[] = $path;
            }
        }
        // Also collect from cache table
        $cacheT = MAIN_DB_PREFIX . 'fah_order_cache';
        $cols = $this->getTableColumns($cacheT);
        if (in_array('pdf_ecm_filepath', $cols, true)) {
            $cRes = $this->db->query("SELECT pdf_ecm_filepath FROM $cacheT WHERE entity=" . (int) $this->conf->entity . " AND pdf_ecm_filepath IS NOT NULL AND pdf_ecm_filepath!=''");
            if ($cRes) {
                while ($cObj = $this->db->fetch_object($cRes)) {
                    $path = trim((string) ($cObj->pdf_ecm_filepath ?? ''), '/\\');
                    if ($path !== '') $pdfPaths[] = $path;
                }
            }
        }
        $pdfPaths = array_values(array_unique($pdfPaths));

        // 6) Delete physical PDF files and their ECM records
        $deletedPdfs = 0;
        $ecmRecordsDeleted = false;
        foreach ($pdfPaths as $relPath) {
            if ($ecmBase !== '' && is_file($ecmBase . '/' . $relPath)) {
                @unlink($ecmBase . '/' . $relPath);
                $deletedPdfs++;
            }
            // Remove ecm_files record
            $ecmResql = $this->db->query(
                "SELECT rowid FROM " . MAIN_DB_PREFIX . "ecm_files"
                . " WHERE entity=" . (int) $this->conf->entity
                . " AND fullpath_orig='" . $this->db->escape($relPath) . "' LIMIT 1"
            );
            if ($ecmResql && ($ecmObj = $this->db->fetch_object($ecmResql))) {
                $this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'ecm_files WHERE rowid=' . (int) $ecmObj->rowid);
                $ecmRecordsDeleted = true;
            }
        }
        if ($ecmRecordsDeleted) $this->db->query('UPDATE ' . MAIN_DB_PREFIX . 'ecm_directories SET cachenbofdoc=-1 WHERE entity=' . (int) $this->conf->entity);

        // 7) Delete log rows and cache rows
        $deletedLogs = $this->countRows($table, 'entity=' . (int) $this->conf->entity);
        if (!$this->db->query('DELETE FROM ' . $table . ' WHERE entity=' . (int) $this->conf->entity)) {
            $this->db->rollback();
            return array(false, 'Could not clear Finance Automation Hub log: ' . $this->db->lasterror());
        }
        $this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'fah_order_cache WHERE entity=' . (int) $this->conf->entity);
        $this->db->commit();

        $deletedAccounts = 0;
        $accountMessage = '';
        if ($deleteOwnedAccounts) {
            list($accountsOk, $accountMessage, $deletedAccounts) = $this->deleteOwnedBankAccounts();
            if (!$accountsOk) $accountMessage = ' ' . $accountMessage;
        }

        return array(true, 'Desync complete.' . $accountMessage, array(
            'bank'    => (int) $deletedBank,
            'accounts' => (int) $deletedAccounts,
            'links'   => (int) $deletedLinks,
            'classes' => (int) $deletedClasses,
            'logs'    => (int) $deletedLogs,
            'pdfs'    => (int) $deletedPdfs,
        ));
    }

    public function deleteOwnedBankAccounts()
    {
        $ids = $this->getOwnedBankAccountIds();
        if (empty($ids)) return array(true, ' No module-created bank accounts needed removal.', 0);
        if (!isset($GLOBALS['user']) || empty($GLOBALS['user']->id)) return array(false, 'Module-created accounts could not be removed because no Dolibarr user is available.', 0);
        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';

        $deleted = array();
        $blocked = array();
        foreach ($ids as $accountId) {
            $account = new Account($this->db);
            if ($account->fetch((int) $accountId) <= 0) continue;
            $lineCheck = $this->db->query('SELECT COUNT(*) AS total, SUM(CASE WHEN fk_type=\'SOLD\' AND amount=0 THEN 0 ELSE 1 END) AS non_initial FROM ' . MAIN_DB_PREFIX . 'bank WHERE fk_account=' . (int) $accountId);
            $lineState = $lineCheck ? $this->db->fetch_object($lineCheck) : null;
            if (!$lineState || (int) $lineState->non_initial > 0) {
                $blocked[] = (string) $account->ref;
                continue;
            }
            if (!$account->can_be_deleted()) {
                $blocked[] = (string) $account->ref;
                continue;
            }
            if ($account->delete($GLOBALS['user']) > 0) $deleted[] = (int) $accountId;
            else $blocked[] = (string) $account->ref;
        }
        if (!empty($deleted)) $this->clearDeletedBankMappings($deleted);
        $message = ' Removed ' . count($deleted) . ' module-created bank account(s).';
        if (!empty($blocked)) $message .= ' Kept non-empty or linked accounts: ' . implode(', ', $blocked) . '.';
        return array(true, $message, count($deleted));
    }

    private function clearDeletedBankMappings(array $deletedIds)
    {
        $deletedIds = array_map('intval', $deletedIds);
        $map = $this->gatewayMap();
        foreach ($map as &$mapping) if (in_array((int) ($mapping['bank_id'] ?? 0), $deletedIds, true)) $mapping['bank_id'] = 0;
        unset($mapping);
        $this->setConst('FAH_GATEWAY_MAP_JSON', json_encode($map), 'chaine');

        foreach (array('amazon', 'sumup') as $connector) {
            $key = 'FAH_' . strtoupper($connector) . '_FINANCE_MAP_JSON';
            $map = $this->getJsonConst($key, array());
            foreach ($map as &$mapping) if (in_array((int) ($mapping['bank_id'] ?? 0), $deletedIds, true)) $mapping['bank_id'] = 0;
            unset($mapping);
            $this->setConst($key, json_encode($map), 'chaine');
        }
        foreach (array('FAH_PAYPAL_BANK_ID', 'FAH_STRIPE_BANK_ID', 'FAH_AMAZONPAY_BANK_ID', 'FAH_DIRECT_BANK_ID', 'FAH_AMAZON_BANK_ID', 'FAH_SUMUP_BANK_ID') as $key) {
            if (in_array((int) $this->getConst($key, '0'), $deletedIds, true)) $this->setConst($key, '0', 'chaine');
        }
        $owned = json_decode((string) $this->getConst('FAH_OWNED_BANK_ACCOUNT_IDS', '[]'), true);
        if (!is_array($owned)) $owned = array();
        $owned = array_values(array_diff(array_map('intval', $owned), $deletedIds));
        $this->setConst('FAH_OWNED_BANK_ACCOUNT_IDS', json_encode($owned), 'chaine');
    }

    private function countRows($table, $where)
    {
        $res = $this->db->query('SELECT COUNT(*) as nb FROM ' . $table . ' WHERE ' . $where);
        if ($res && ($obj = $this->db->fetch_object($res))) return (int) $obj->nb;
        return 0;
    }

    public function cleanupLegacyMenus()
    {
        $table = MAIN_DB_PREFIX . 'menu';
        if (empty($this->getTableColumns($table))) return array(false, 'Dolibarr menu storage is unavailable.', 0);
        $conditions = array(
            "mainmenu IN ('woobanksync','commerceautomationhub','dolicommercehub','dollicommercehub','dolibarrcommercehub')",
            "leftmenu LIKE 'woobanksync%'",
            "leftmenu LIKE 'commerceautomationhub%'",
            "leftmenu LIKE 'dolicommercehub%'",
            "leftmenu LIKE 'dollicommercehub%'",
            "leftmenu LIKE 'dolibarrcommercehub%'",
            "url LIKE '%/custom/woobanksync/%'",
            "url LIKE '%/custom/commerceautomationhub/%'",
            "url LIKE '%/custom/dolicommercehub/%'",
            "url LIKE '%/custom/dollicommercehub/%'",
            "url LIKE '%/custom/dolibarrcommercehub/%'",
            "titre IN ('WooBankSync','Commerce Automation Hub','Doli Commerce Hub','Dolli Commerce Hub','Dolibarr Commerce Hub')",
        );
        $where = '(' . implode(' OR ', $conditions) . ')';
        $count = $this->countRows($table, $where);
        if ($count > 0 && !$this->db->query('DELETE FROM ' . $table . ' WHERE ' . $where)) {
            return array(false, 'Could not remove stale legacy menu rows: ' . $this->db->lasterror(), 0);
        }
        return array(true, $count > 0 ? 'Removed ' . $count . ' stale legacy menu row(s).' : 'No stale legacy menu rows remain.', $count);
    }

    public function getBankEntrySequenceStatus()
    {
        $table = MAIN_DB_PREFIX . 'bank';
        $highest = 0;
        $resql = $this->db->query('SELECT MAX(rowid) AS highest FROM ' . $table);
        if ($resql && ($row = $this->db->fetch_object($resql))) $highest = max(0, (int) $row->highest);

        $next = $highest + 1;
        $resql = $this->db->query("SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $this->db->escape($table) . "' LIMIT 1");
        if ($resql && ($row = $this->db->fetch_object($resql)) && !empty($row->next_id)) $next = max($next, (int) $row->next_id);
        return array('highest' => $highest, 'minimum' => $highest + 1, 'next' => $next);
    }

    public function setBankEntrySequence($requestedNext)
    {
        $requestedNext = (int) $requestedNext;
        $status = $this->getBankEntrySequenceStatus();
        if ($requestedNext < 1) return array(false, 'The next bank entry reference must be a positive integer.', $status);
        if ($requestedNext < $status['minimum']) {
            return array(false, 'The next reference cannot be lower than ' . $status['minimum'] . ' because bank entry #' . $status['highest'] . ' still exists.', $status);
        }
        if (!$this->db->query('ALTER TABLE ' . MAIN_DB_PREFIX . 'bank AUTO_INCREMENT=' . $requestedNext)) {
            return array(false, 'Could not change the bank entry sequence: ' . $this->db->lasterror(), $status);
        }
        $status = $this->getBankEntrySequenceStatus();
        return array(true, 'The next global Dolibarr bank entry reference is now ' . $status['next'] . '.', $status);
    }

    public function getMaintenanceSummary()
    {
        $summary = array(
            'sequence' => $this->getBankEntrySequenceStatus(),
            'accounts' => count($this->getOwnedBankAccountIds()),
            'entries' => 0,
            'documents' => 0,
            'logs' => 0,
        );
        $resql = $this->db->query('SELECT COUNT(*) AS logs,'
            . ' SUM(CASE WHEN bank_line_id_gross > 0 THEN 1 ELSE 0 END) + SUM(CASE WHEN bank_line_id_fee > 0 THEN 1 ELSE 0 END) AS entries,'
            . " COUNT(DISTINCT CASE WHEN pdf_ecm_filepath IS NOT NULL AND pdf_ecm_filepath != '' THEN pdf_ecm_filepath END) AS documents"
            . ' FROM ' . MAIN_DB_PREFIX . 'fah_sync_log WHERE entity=' . (int) $this->conf->entity);
        if ($resql && ($row = $this->db->fetch_object($resql))) {
            $summary['entries'] = (int) $row->entries;
            $summary['documents'] = (int) $row->documents;
            $summary['logs'] = (int) $row->logs;
        }
        return $summary;
    }

    public function runDatabaseChecks()
    {
        $messages = array();
        $prefix = MAIN_DB_PREFIX;
        $table = $prefix . 'fah_sync_log';

        list($menuOk, $menuMessage) = $this->cleanupLegacyMenus();
        if (!$menuOk) return array(false, $menuMessage);
        $messages[] = $menuMessage;

        $sql = "CREATE TABLE IF NOT EXISTS " . $table . " (" .
            "rowid integer AUTO_INCREMENT PRIMARY KEY," .
            "entity integer NOT NULL DEFAULT 1," .
            "connector varchar(32) NOT NULL DEFAULT 'woocommerce'," .
            "woo_order_id varchar(128) NOT NULL," .
            "woo_order_number varchar(128) DEFAULT NULL," .
            "woo_transaction_id varchar(255) DEFAULT NULL," .
            "payment_method varchar(128) DEFAULT NULL," .
            "dolibarr_bank_account_id integer DEFAULT NULL," .
            "gross_amount double(24,8) DEFAULT 0," .
            "fee_amount double(24,8) DEFAULT 0," .
            "fee_source varchar(128) DEFAULT NULL," .
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
            'connector' => "varchar(32) NOT NULL DEFAULT 'woocommerce'",
            'fee_source' => 'varchar(128) DEFAULT NULL',
            'payout_amount'  => 'double(24,8) DEFAULT 0',
            'woo_payout_raw' => 'double(24,8) DEFAULT NULL',
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

        $legacyIndex = $this->db->query("SHOW INDEX FROM " . $table . " WHERE Key_name='uk_fah_entity_order'");
        if ($legacyIndex && $this->db->num_rows($legacyIndex) > 0) {
            if (!$this->db->query("ALTER TABLE " . $table . " DROP INDEX uk_fah_entity_order")) {
                return array(false, 'Database check failed while upgrading the connector-aware order key: ' . $this->db->lasterror());
            }
        }
        $resql = $this->db->query("SHOW INDEX FROM " . $table . " WHERE Key_name='uk_fah_entity_connector_order'");
        if ($resql && $this->db->num_rows($resql) == 0) {
            if (!$this->db->query("ALTER TABLE " . $table . " ADD UNIQUE KEY uk_fah_entity_connector_order (entity, connector, woo_order_id)")) {
                $messages[] = 'Connector-aware unique key could not be added, maybe duplicate old rows exist: ' . $this->db->lasterror();
            } else {
                $messages[] = 'Connector-aware unique key is ready.';
            }
        }

        $cacheTable = $prefix . 'fah_order_cache';
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
            "UNIQUE KEY uk_fah_order_cache (entity, woo_order_id)" .
            ") ENGINE=innodb";
        if (!$this->db->query($sql)) {
            return array(false, 'Database check failed while creating order cache table: ' . $this->db->lasterror());
        }
        $messages[] = 'Order cache table is ready.';

        list($inventoryOk, $inventoryMessage) = $this->inventory()->ensureSchema();
        if (!$inventoryOk) return array(false, 'Database check failed while creating commerce inventory tables: ' . $inventoryMessage);
        $messages[] = $inventoryMessage;

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
        $gzd = $this->integrationManager()->get('germanized');
        if ($gzd) return $gzd->createDocumentFolder($this);
        return array(false, 'Germanized integration not available.');
    }

    public function getBankExtraFields($types = array('varchar', 'text'))
    {
        $fields = array();
        $allowedTypes = array('varchar', 'text', 'double', 'price', 'integer', 'int', 'real');
        $types = array_values(array_intersect($allowedTypes, (array) $types));
        if (empty($types)) return $fields;
        $quotedTypes = array_map(function ($type) { return "'" . $type . "'"; }, $types);
        $sql = 'SELECT name, label FROM ' . MAIN_DB_PREFIX . "extrafields WHERE elementtype='bank' AND entity IN (0," . (int) $this->conf->entity . ') AND type IN (' . implode(',', $quotedTypes) . ') ORDER BY pos, label';
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $code = (string) $obj->name;
                if (!isset($fields[$code])) $fields[$code] = (string) $obj->label;
            }
        }
        return $fields;
    }

    public function getBankAmountExtraFields()
    {
        return $this->getBankExtraFields(array('double', 'price', 'integer', 'int', 'real'));
    }

    public function saveAmountExtraFieldMapping($grossCode, $feeCode, $grossLabel = '', $feeLabel = '')
    {
        $grossCode = trim((string) $grossCode);
        $feeCode = trim((string) $feeCode);
        $fields = $this->getBankAmountExtraFields();
        foreach (array('Gross' => $grossCode, 'Fee' => $feeCode) as $name => $code) {
            if ($code !== '' && !isset($fields[$code])) return array(false, $name . ' must be mapped to a numeric bank-entry custom field.');
        }
        if ($grossCode !== '' && $grossCode === $feeCode) return array(false, 'Gross amount and fee must use different custom fields.');
        $invoiceCode = trim((string) $this->getConst('FAH_BANK_EXTRAFIELD_CODE', ''));
        if ($invoiceCode !== '' && ($grossCode === $invoiceCode || $feeCode === $invoiceCode)) {
            return array(false, 'Amount fields cannot use the mapped invoice-number custom field.');
        }
        $this->setConst('FAH_EXTRAFIELD_GROSS_CODE', $grossCode, 'chaine');
        $this->setConst('FAH_EXTRAFIELD_FEE_CODE', $feeCode, 'chaine');
        foreach (array($grossCode => $grossLabel, $feeCode => $feeLabel) as $code => $label) {
            $label = trim((string) $label);
            if ($code !== '' && $label !== '') {
                $this->db->query('UPDATE ' . MAIN_DB_PREFIX . "extrafields SET label='" . $this->db->escape(substr($label, 0, 255)) . "' WHERE elementtype='bank' AND name='" . $this->db->escape($code) . "' AND entity IN (0," . (int) $this->conf->entity . ')');
            }
        }
        return array(true, 'WooCommerce amount custom field mapping saved.');
    }

    public function createAndMapAmountExtraFields($grossLabel = '', $feeLabel = '')
    {
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);
        $created = array();
        $reused  = array();
        $failed  = array();

        $toCreate = array(
            'fah_gross_amount' => array('label' => trim((string) $grossLabel) ?: 'WooCommerce gross amount', 'const' => 'FAH_EXTRAFIELD_GROSS_CODE'),
            'fah_fee_amount'   => array('label' => trim((string) $feeLabel) ?: 'WooCommerce fee amount', 'const' => 'FAH_EXTRAFIELD_FEE_CODE'),
        );

        $existingFields = $this->getBankAmountExtraFields();
        $claimed = array_filter(array(trim((string) $this->getConst('FAH_BANK_EXTRAFIELD_CODE', ''))));

        foreach ($toCreate as $code => $info) {
            $currentMapping = trim((string) $this->getConst($info['const'], ''));
            if ($currentMapping !== '' && isset($existingFields[$currentMapping]) && !in_array($currentMapping, $claimed, true)) {
                $reused[] = $code . ' (already mapped to ' . $currentMapping . ')';
                $claimed[] = $currentMapping;
                continue;
            }

            if (!isset($existingFields[$code])) {
                $result = $extrafields->addExtraField(
                    $code,
                    $info['label'],
                    'double',
                    100,
                    '24,8',
                    'bank',
                    0, 0, '', '', 1, '', '1',
                    'Amount field imported from a commerce channel by Finance Automation Hub',
                    '', (string) $this->conf->entity, '', '1'
                );
                if ($result <= 0) {
                    $failed[] = $code . ': ' . (!empty($extrafields->error) ? $extrafields->error : $this->db->lasterror());
                    continue;
                }
                $created[] = $code;
            } else {
                $reused[] = $code . ' (field already exists)';
            }

            $this->setConst($info['const'], $code, 'chaine');
            $claimed[] = $code;
        }

        if (!empty($failed)) {
            return array(false, 'Failed to create: ' . implode(', ', $failed) . '. Created: ' . implode(', ', $created) . '. Reused/skipped: ' . implode(', ', $reused) . '.');
        }
        list($mappingOk, $mappingMessage) = $this->saveAmountExtraFieldMapping(
            $this->getConst('FAH_EXTRAFIELD_GROSS_CODE', ''),
            $this->getConst('FAH_EXTRAFIELD_FEE_CODE', ''),
            $toCreate['fah_gross_amount']['label'],
            $toCreate['fah_fee_amount']['label']
        );
        if (!$mappingOk) return array(false, $mappingMessage);
        return array(true, 'WooCommerce amount custom fields ready. Created: ' . (empty($created) ? 'none' : implode(', ', $created)) . '. Reused/skipped: ' . (empty($reused) ? 'none' : implode(', ', $reused)) . '.');
    }

    public function saveInvoiceExtraFieldMapping($enabled, $code, $label = '')
    {
        $code = trim((string) $code);
        if ($enabled) {
            if ($code === '' || !isset($this->getBankExtraFields()[$code])) return array(false, 'Invoice number must be mapped to a text bank-entry custom field.');
            $grossCode = trim((string) $this->getConst('FAH_EXTRAFIELD_GROSS_CODE', ''));
            $feeCode = trim((string) $this->getConst('FAH_EXTRAFIELD_FEE_CODE', ''));
            if ($code === $grossCode || $code === $feeCode) return array(false, 'Invoice number cannot use a mapped amount field.');
        }
        $this->setConst('FAH_BANK_EXTRAFIELD_CODE', $code, 'chaine');
        $this->setConst('FAH_BANK_EXTRAFIELD_ENABLED', $enabled ? '1' : '0', 'yesno');
        $label = trim((string) $label);
        if ($code !== '' && $label !== '') {
            $this->db->query('UPDATE ' . MAIN_DB_PREFIX . "extrafields SET label='" . $this->db->escape(substr($label, 0, 255)) . "' WHERE elementtype='bank' AND name='" . $this->db->escape($code) . "' AND entity IN (0," . (int) $this->conf->entity . ')');
        }
        return array(true, 'Invoice custom field mapping saved.');
    }

    public function createAndMapInvoiceBankExtraField($label = '')
    {
        $mapped = trim((string) $this->getConst('FAH_BANK_EXTRAFIELD_CODE', ''));
        $label = trim((string) $label) ?: 'WooCommerce invoice number';
        if ($mapped !== '' && isset($this->getBankExtraFields()[$mapped])) {
            list($ok, $msg) = $this->saveInvoiceExtraFieldMapping(true, $mapped, $label);
            return array($ok, $ok ? 'The mapped invoice-number custom field is ready.' : $msg);
        }

        $code = 'woo_invoice_number';
        $fields = $this->getBankExtraFields();
        if (!isset($fields[$code])) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
            $extrafields = new ExtraFields($this->db);
            $result = $extrafields->addExtraField(
                $code,
                $label,
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

        list($ok, $msg) = $this->saveInvoiceExtraFieldMapping(true, $code, $label);
        return array($ok, $ok ? 'The invoice-number custom field was created or reused, mapped, and enabled.' : $msg);
    }

    public function repairExistingBankExtraFields()
    {
        $sql = 'SELECT bank_line_id_gross, gross_amount, fee_amount, woo_invoice_number FROM ' . MAIN_DB_PREFIX . 'fah_sync_log'
            . ' WHERE entity=' . (int) $this->conf->entity . $this->logConnectorCondition('woocommerce')
            . " AND sync_status='synced' AND bank_line_id_gross > 0";
        $resql = $this->db->query($sql);
        if (!$resql) return array(false, $this->db->lasterror());
        $updated = 0;
        $this->db->begin();
        while ($obj = $this->db->fetch_object($resql)) {
            $bankLineId = (int) $obj->bank_line_id_gross;
            list($ok, $message) = $this->writeBankAmountExtraFields($bankLineId, $obj->gross_amount, $obj->fee_amount);
            if ($ok) $ok = $this->setBankInvoiceNumber($bankLineId, (string) $obj->woo_invoice_number)
                && $this->setBankInvoiceExtraField($bankLineId, (string) $obj->woo_invoice_number, true);
            if (!$ok) {
                $this->db->rollback();
                return array(false, 'Repair stopped at bank entry ' . $bankLineId . ($message !== '' ? ': ' . $message : '.'));
            }
            $updated++;
        }
        $this->db->commit();
        return array(true, 'Repaired custom fields on ' . $updated . ' existing WooCommerce bank entries.');
    }

    public function saveGatewayMapFromPost()
    {
        $gateways = $this->getJsonConst('FAH_GATEWAYS_JSON', array());
        $map = array();
        foreach ($gateways as $gateway) {
            $gid = (string) $gateway['id'];
            $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $gid);
            $map[$gid] = array(
                'bank_id'    => (int) GETPOST('FAH_MAP_BANK_' . $safe, 'int'),
                'fee_key'    => GETPOST('FAH_MAP_FEE_' . $safe, 'restricthtml'),
                'payout_key' => GETPOST('FAH_MAP_PAYOUT_' . $safe, 'restricthtml'),
            );
        }
        $this->setConst('FAH_GATEWAY_MAP_JSON', json_encode($map), 'chaine');
    }

    public function channelFinanceMap($connector)
    {
        $connector = strtolower(trim((string) $connector));
        if (!in_array($connector, array('amazon', 'sumup'), true)) return array();
        $key = 'FAH_' . strtoupper($connector) . '_FINANCE_MAP_JSON';
        $map = $this->getJsonConst($key, array());
        if (!empty($map)) return $map;
        $legacyBankId = (int) $this->getConst('FAH_' . strtoupper($connector) . '_BANK_ID', '0');
        return array($connector => array('bank_id' => $legacyBankId));
    }

    public function saveChannelFinanceMapFromPost($connector)
    {
        $connector = strtolower(trim((string) $connector));
        if (!in_array($connector, array('amazon', 'sumup'), true)) return array(false, 'Unknown connector finance mapping.');
        $bankId = max(0, (int) GETPOST('finance_bank_id', 'int'));
        $map = array($connector => array('bank_id' => $bankId));
        $this->setConst('FAH_' . strtoupper($connector) . '_FINANCE_MAP_JSON', json_encode($map), 'chaine');
        $this->setConst('FAH_' . strtoupper($connector) . '_BANK_ID', (string) $bankId, 'chaine');
        return array(true, ucfirst($connector) . ' virtual bank mapping saved.');
    }

    public function autoCreateChannelAccount($connector)
    {
        $connector = strtolower(trim((string) $connector));
        if (!in_array($connector, array('amazon', 'sumup'), true)) return array(false, 'Unknown connector.');
        $label = ($connector === 'amazon' ? 'Amazon Seller' : 'SumUp') . ' clearing';
        $wasExisting = false;
        $accountId = $this->findOrCreateVirtualBankAccount($label, strtoupper(substr($connector, 0, 8)), $wasExisting);
        if ($accountId <= 0) return array(false, 'Could not create the virtual bank account: ' . $this->db->lasterror());
        $map = array($connector => array('bank_id' => $accountId));
        $this->setConst('FAH_' . strtoupper($connector) . '_FINANCE_MAP_JSON', json_encode($map), 'chaine');
        $this->setConst('FAH_' . strtoupper($connector) . '_BANK_ID', (string) $accountId, 'chaine');
        return array(true, ($wasExisting ? 'Reused and mapped ' : 'Created and mapped ') . $label . '.');
    }

    public function gatewayMap()
    {
        $map = $this->getJsonConst('FAH_GATEWAY_MAP_JSON', array());
        if (!empty($map)) return $map;

        $legacy = array();
        foreach ($this->csvToArray($this->getConst('FAH_GATEWAY_PAYPAL')) as $g) $legacy[$g] = array('bank_id' => (int) $this->getConst('FAH_PAYPAL_BANK_ID'), 'fee_key' => '', 'payout_key' => '');
        foreach ($this->csvToArray($this->getConst('FAH_GATEWAY_STRIPE')) as $g) $legacy[$g] = array('bank_id' => (int) $this->getConst('FAH_STRIPE_BANK_ID'), 'fee_key' => '', 'payout_key' => '');
        foreach ($this->csvToArray($this->getConst('FAH_GATEWAY_AMAZONPAY')) as $g) $legacy[$g] = array('bank_id' => (int) $this->getConst('FAH_AMAZONPAY_BANK_ID'), 'fee_key' => '', 'payout_key' => '');
        foreach ($this->csvToArray($this->getConst('FAH_GATEWAY_BANK')) as $g) $legacy[$g] = array('bank_id' => (int) $this->getConst('FAH_DIRECT_BANK_ID'), 'fee_key' => '', 'payout_key' => '');
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
                $amount = $this->extractFeeFromValue($meta['value'] ?? null);
                if ($amount > 0) $candidates[] = $amount;
            }
        }
        return !empty($candidates) ? max($candidates) : 0.0;
    }

    private function resolveWooFee(array &$order, array $gatewayConfig)
    {
        $fee = $this->extractAmountFromConfiguredKey($order, $gatewayConfig['fee_key'] ?? '');
        if ($fee <= 0) $fee = $this->autoDetectFee($order);
        if ($fee > 0) return $fee;

        $paymentMethod = strtolower((string) ($order['payment_method'] ?? ''));
        if (strpos($paymentMethod, 'stripe') === false && strpos($paymentMethod, 'klarna') === false && strpos($paymentMethod, 'woocommerce_payments') === false) return 0.0;
        $secretKey = trim((string) $this->getConst('FAH_STRIPE_SECRET_KEY'));
        if ($secretKey === '') {
            $order['_fah_fee_error'] = 'Exact Stripe fee unavailable: configure the Stripe secret key in WooCommerce settings.';
            return 0.0;
        }

        $references = array((string) ($order['transaction_id'] ?? ''));
        $referenceKeys = array('_stripe_intent_id', '_stripe_source_id', '_transaction_id', '_wc_stripe_charge_id');
        foreach ((array) ($order['meta_data'] ?? array()) as $meta) {
            if (in_array((string) ($meta['key'] ?? ''), $referenceKeys, true)) $references[] = (string) ($meta['value'] ?? '');
        }
        require_once __DIR__ . '/fahstripeclient.class.php';
        $client = new FahStripeClient($secretKey, $this->getConst('FAH_STRIPE_ACCOUNT_ID'));
        $fee = $client->getFee($references, (string) ($order['currency'] ?? ''));
        if ($fee !== false) {
            $order['_fah_fee_source'] = 'Stripe balance transaction API';
            return (float) $fee;
        }
        $order['_fah_fee_error'] = 'Exact Stripe fee lookup failed: ' . $client->error;
        return 0.0;
    }

    private function maybeUnserialize($value)
    {
        if (!is_string($value)) return $value;
        $v = trim($value);
        if ($v === '' || !preg_match('/^[abisd]:/', $v)) return $value;
        $result = @unserialize($v);
        return ($result !== false) ? $result : $value;
    }

    private function extractFeeFromValue($value)
    {
        $value = $this->maybeUnserialize($value);
        if (is_array($value)) {
            // PayPal ppcp: {paypal_fee: {value: '3.53'}}
            foreach (array('paypal_fee', 'fee', 'fee_amount', 'transaction_fee') as $k) {
                if (isset($value[$k])) return abs($this->normalizeAmount($value[$k]));
            }
            return abs($this->normalizeAmount($value));
        }
        return abs($this->normalizeAmount($value));
    }

    private function extractPayoutFromValue($value)
    {
        $value = $this->maybeUnserialize($value);
        if (is_array($value)) {
            // PayPal ppcp: {net_amount: {value: '101.37'}}
            foreach (array('net_amount', 'net', 'payout', 'payout_amount', 'settlement_amount') as $k) {
                if (isset($value[$k])) return abs($this->normalizeAmount($value[$k]));
            }
            // Last resort: if the array has a single numeric-ish value key, use it
            return abs($this->normalizeAmount($value));
        }
        return abs($this->normalizeAmount($value));
    }

    private function extractAmountFromConfiguredKey($order, $key)
    {
        $key = trim((string) $key);
        if ($key === '') return 0.0;
        foreach (($order['meta_data'] ?? array()) as $meta) {
            if (strcasecmp((string) ($meta['key'] ?? ''), $key) === 0) {
                return $this->extractFeeFromValue($meta['value'] ?? null);
            }
        }
        return 0.0;
    }

    private function extractPayoutAmountFromConfiguredKey($order, $key)
    {
        $key = trim((string) $key);
        if ($key === '') return 0.0;
        foreach (($order['meta_data'] ?? array()) as $meta) {
            if (strcasecmp((string) ($meta['key'] ?? ''), $key) === 0) {
                return $this->extractPayoutFromValue($meta['value'] ?? null);
            }
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

    private function writeBankAmountExtraFields($bankLineId, $gross, $fee)
    {
        if ($bankLineId <= 0) return array(false, 'Invalid bank entry.');
        $grossCode = trim((string) $this->getConst('FAH_EXTRAFIELD_GROSS_CODE', ''));
        $feeCode   = trim((string) $this->getConst('FAH_EXTRAFIELD_FEE_CODE', ''));
        if ($grossCode === '' && $feeCode === '') return array(true, '');

        $numericFields = $this->getBankAmountExtraFields();
        foreach (array('gross' => $grossCode, 'fee' => $feeCode) as $name => $code) {
            if ($code !== '' && !isset($numericFields[$code])) return array(false, ucfirst($name) . ' mapping is not a numeric bank custom field.');
        }
        if ($grossCode !== '' && $grossCode === $feeCode) return array(false, 'Gross and fee mappings point to the same field.');
        $invoiceCode = trim((string) $this->getConst('FAH_BANK_EXTRAFIELD_CODE', ''));
        if ($invoiceCode !== '' && ($grossCode === $invoiceCode || $feeCode === $invoiceCode)) return array(false, 'An amount mapping points to the invoice-number field.');

        $table   = MAIN_DB_PREFIX . 'bank_extrafields';
        $columns = $this->getTableColumns($table);
        if (empty($columns) || !in_array('fk_object', $columns, true)) return array(false, 'Bank custom-field storage is unavailable.');

        $resql = $this->db->query('SELECT rowid FROM ' . $table . ' WHERE fk_object=' . (int) $bankLineId . ' LIMIT 1');
        $existingId = 0;
        if (!$resql) return array(false, $this->db->lasterror());
        if ($obj = $this->db->fetch_object($resql)) $existingId = (int) $obj->rowid;

        $fields = array();
        if ($grossCode !== '' && in_array($grossCode, $columns, true)) $fields[$grossCode] = price2num($gross, 'MT');
        if ($feeCode !== '' && in_array($feeCode, $columns, true))     $fields[$feeCode]   = price2num($fee, 'MT');
        if (empty($fields)) return array(false, 'Mapped custom-field columns do not exist.');

        if ($existingId > 0) {
            $sets = array();
            foreach ($fields as $col => $val) $sets[] = $col . '=' . $val;
            $result = $this->db->query('UPDATE ' . $table . ' SET ' . implode(',', $sets) . ' WHERE rowid=' . $existingId);
        } else {
            $cols = array('fk_object');
            $vals = array((string) (int) $bankLineId);
            foreach ($fields as $col => $val) { $cols[] = $col; $vals[] = (string) $val; }
            $result = $this->db->query('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
        }
        return array((bool) $result, $result ? '' : $this->db->lasterror());
    }

    private function setBankInvoiceNumber($bankLineId, $invoiceNumber)
    {
        if (!$this->nativeInvoiceReferenceEnabled() || empty($invoiceNumber) || $bankLineId <= 0) return true;
        $fields = $this->getTableColumns(MAIN_DB_PREFIX . 'bank');
        if (in_array('num_chq', $fields, true)) {
            $sql = 'UPDATE ' . MAIN_DB_PREFIX . "bank SET num_chq='" . $this->db->escape((string) $invoiceNumber) . "' WHERE rowid=" . (int) $bankLineId;
            return (bool) $this->db->query($sql);
        }
        return true;
    }

    private function nativeInvoiceReferenceEnabled()
    {
        return (int) $this->getConst('FAH_DOCUMENT_SYNC_ENABLED', '0') === 1;
    }

    private function setBankInvoiceExtraField($bankLineId, $invoiceNumber, $allowEmpty = false)
    {
        if ((int) $this->getConst('FAH_BANK_EXTRAFIELD_ENABLED', '0') !== 1 || $bankLineId <= 0) return true;
        if (!$allowEmpty && empty($invoiceNumber)) return true;

        $code = trim((string) $this->getConst('FAH_BANK_EXTRAFIELD_CODE', ''));
        if ($code === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $code)) return false;
        if (!isset($this->getBankExtraFields()[$code])) return false;
        if ($code === trim((string) $this->getConst('FAH_EXTRAFIELD_GROSS_CODE', '')) || $code === trim((string) $this->getConst('FAH_EXTRAFIELD_FEE_CODE', ''))) return false;

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

    public function detectStoreaBillFolder()
    {
        $gzd = $this->integrationManager()->get('germanized');
        if ($gzd) return $gzd->detectStoreaBillFolder($this);
        return array(false, 'Germanized integration not available.');
    }

    public function testFetchPdfUrl($url)
    {
        $gzd = $this->integrationManager()->get('germanized');
        if ($gzd) return $gzd->testFetchUrl($url);
        return false;
    }

    public function testFetchStoreaBillPdf($orderId)
    {
        $gzd = $this->integrationManager()->get('germanized');
        if ($gzd) return $gzd->testFetchSabPdf($orderId);
        return false;
    }

    /** @return array{ok:bool, already:bool, filepath:string, log:string[]} */
    public function downloadAndSavePdf($orderId, $orderNumber, $invoiceNumber = '', $pdfUrl = '', $force = false)
    {
        foreach ($this->integrations() as $integration) {
            $result = $integration->tryDownloadPdf($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $this, $force);
            if ($result['ok'] || ($result['already'] ?? false)) return $result;
        }
        return array('ok' => false, 'already' => false, 'filepath' => '', 'log' => array());
    }

    public function getExistingEcmPath($orderId)
    {
        $e = (int) $this->conf->entity;
        $oid = $this->db->escape((string) $orderId);
        // Log table first (SELECT * — dynamic columns handled via null coalescing)
        $r = $this->db->query("SELECT * FROM " . MAIN_DB_PREFIX . "fah_sync_log WHERE entity=$e" . $this->logConnectorCondition('woocommerce') . " AND woo_order_id='$oid' AND sync_status='synced' LIMIT 1");
        if ($r && ($obj = $this->db->fetch_object($r))) {
            $path = (string) ($obj->pdf_ecm_filepath ?? '');
            if ($path !== '') return $path;
        }
        // Cache table fallback
        $r = $this->db->query("SELECT pdf_ecm_filepath FROM " . MAIN_DB_PREFIX . "fah_order_cache WHERE entity=$e AND woo_order_id='$oid' LIMIT 1");
        if ($r && ($obj = $this->db->fetch_object($r))) {
            return (string) ($obj->pdf_ecm_filepath ?? '');
        }
        return '';
    }

    public function updateCacheEcmPath($orderId, $ecmFilepath)
    {
        $escaped = $this->db->escape($ecmFilepath);
        $oid = $this->db->escape((string) $orderId);
        $entity = (int) $this->conf->entity;
        $now = $this->sqlDateNow();
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "fah_order_cache SET pdf_ecm_filepath='" . $escaped . "', date_updated=" . $now . " WHERE entity=" . $entity . " AND woo_order_id='" . $oid . "'");
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "fah_sync_log SET pdf_ecm_filepath='" . $escaped . "' WHERE entity=" . $entity . $this->logConnectorCondition('woocommerce') . " AND woo_order_id='" . $oid . "' AND sync_status='synced'");
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
        $gzd = $this->integrationManager()->get('germanized');
        if ($gzd) return $gzd->getPendingPdfOrders($force, $this);
        return array();
    }

    public function getCachedOrderJsonRows()
    {
        $rows = array();
        $table = MAIN_DB_PREFIX . 'fah_order_cache';
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
        $table = MAIN_DB_PREFIX . 'fah_order_cache';
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
        $sql = 'SELECT woo_order_id, woo_order_number FROM ' . MAIN_DB_PREFIX . 'fah_sync_log'
            . ' WHERE entity=' . (int) $this->conf->entity
            . $this->logConnectorCondition('woocommerce')
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
        $batchSize = max(1, min(100, (int) $this->getConst('FAH_CACHE_BATCH_SIZE', '1')));
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

        $result = array('updated' => 0, 'errors' => 0, 'items' => array());
        foreach ($ids as $id) {
            $key = (string) $id;
            if (empty($ordersById[$key])) {
                $result['errors']++;
                $result['items'][] = array('id' => $key, 'ok' => false, 'message' => 'Order not returned by WooCommerce');
                continue;
            }

            $order = $ordersById[$key];
            $invoiceNumber = '';
            $pdfUrl = '';
            foreach ($this->integrations() as $integration) {
                $enriched = $integration->enrichCacheOrder($order, $this);
                if ($invoiceNumber === '' && $enriched['invoice_number'] !== '') $invoiceNumber = $enriched['invoice_number'];
                if ($pdfUrl === '' && $enriched['pdf_url'] !== '') $pdfUrl = $enriched['pdf_url'];
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
                'has_integrations' => !empty($this->integrations()),
            );
        }
        return $result;
    }

    private function getOrderCacheState($orderId)
    {
        $state = array();
        $sql = 'SELECT woo_order_number, woo_invoice_number, woo_invoice_pdf_url, pdf_ecm_filepath'
            . ' FROM ' . MAIN_DB_PREFIX . 'fah_order_cache'
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
        $table = MAIN_DB_PREFIX . 'fah_order_cache';
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

    private function findOrCreateVirtualBankAccount($label, $ref, &$wasExisting = false)
    {
        $wasExisting = false;
        $table = MAIN_DB_PREFIX . 'bank_account';
        $safeRef = $this->makeUniqueSafeBankRef($ref);
        $sql = 'SELECT rowid, bank FROM ' . $table . " WHERE entity IN (0," . (int) $this->conf->entity . ") AND (label='" . $this->db->escape($label) . "' OR ref='" . $this->db->escape($safeRef) . "') LIMIT 1";
        $res = $this->db->query($sql);
        if ($res && ($obj = $this->db->fetch_object($res))) {
            $wasExisting = true;
            if (strpos((string) $label, 'Woo ') !== 0) {
                $this->db->query('UPDATE ' . $table . " SET label='" . $this->db->escape($label) . "' WHERE rowid=" . (int) $obj->rowid . " AND label LIKE 'Woo %'");
            }
            $id = (int) $obj->rowid;
            if (in_array((string) $obj->bank, array('Virtual payment clearing account', 'Virtual commerce clearing account'), true)) $this->rememberOwnedBankAccount($id);
            return $id;
        }

        if (file_exists(DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php')) {
            require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
            $account = new Account($this->db);
            $account->ref = $safeRef;
            $account->label = $label;
            $account->bank = 'Virtual payment clearing account';
            $account->number = $safeRef;
            $account->account_number = $safeRef;
            $account->type = Account::TYPE_CURRENT;
            $account->courant = 1;
            $account->status = Account::STATUS_OPEN;
            $account->clos = 0;
            $account->entity = (int) $this->conf->entity;
            $account->country_id = (int) $this->getDefaultCountryId();
            $account->date_solde = dol_now();
            $account->balance = 0;
            $account->currency_code = !empty($this->conf->currency) ? $this->conf->currency : 'EUR';
            $user = isset($GLOBALS['user']) ? $GLOBALS['user'] : null;
            $id = $user && method_exists($account, 'create') ? $account->create($user) : 0;
            if ($id > 0) {
                $this->rememberOwnedBankAccount((int) $id);
                return (int) $id;
            }
        }

        $fields = $this->getTableColumns($table);
        $data = array();
        $this->addDataIfColumn($data, $fields, 'ref', "'" . $this->db->escape($safeRef) . "'", false);
        $this->addDataIfColumn($data, $fields, 'label', "'" . $this->db->escape($label) . "'", false);
        $this->addDataIfColumn($data, $fields, 'bank', "'Virtual commerce clearing account'", false);
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
        $id = (int) $this->db->last_insert_id($table);
        if ($id > 0) $this->rememberOwnedBankAccount($id);
        return $id;
    }

    private function rememberOwnedBankAccount($accountId)
    {
        $accountId = (int) $accountId;
        if ($accountId <= 0) return;
        $ids = json_decode((string) $this->getConst('FAH_OWNED_BANK_ACCOUNT_IDS', '[]'), true);
        if (!is_array($ids)) $ids = array();
        $ids = array_values(array_unique(array_filter(array_map('intval', array_merge($ids, array($accountId))))));
        $this->setConst('FAH_OWNED_BANK_ACCOUNT_IDS', json_encode($ids), 'chaine');
    }

    public function getOwnedBankAccountIds()
    {
        $ids = json_decode((string) $this->getConst('FAH_OWNED_BANK_ACCOUNT_IDS', '[]'), true);
        if (!is_array($ids)) $ids = array();
        foreach ($this->gatewayMap() as $mapping) if (!empty($mapping['bank_id'])) $ids[] = (int) $mapping['bank_id'];
        foreach (array('amazon', 'sumup') as $connector) {
            foreach ($this->channelFinanceMap($connector) as $mapping) if (!empty($mapping['bank_id'])) $ids[] = (int) $mapping['bank_id'];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) return array();

        $owned = array();
        $resql = $this->db->query('SELECT rowid, bank FROM ' . MAIN_DB_PREFIX . 'bank_account WHERE rowid IN (' . implode(',', $ids) . ') AND entity IN (0,' . (int) $this->conf->entity . ')');
        if ($resql) while ($row = $this->db->fetch_object($resql)) {
            if (in_array((string) $row->bank, array('Virtual payment clearing account', 'Virtual commerce clearing account'), true)) $owned[] = (int) $row->rowid;
        }
        return array_values(array_unique($owned));
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

    private function insertLog($order, $bankId, $gross, $fee, $bankLineGross, $bankLineFee, $status, $message, $dateOrder, $invoiceNumber = '', $payoutAmount = 0, $pdfUrl = '', $pdfEcmFilepath = '', $wooPayoutRaw = 0.0)
    {
        $fields = $this->getTableColumns(MAIN_DB_PREFIX . 'fah_sync_log');
        $data = array(
            'entity' => (int) $this->conf->entity,
            'connector' => "'" . $this->db->escape((string) ($order['_fah_connector'] ?? 'woocommerce')) . "'",
            'woo_order_id' => "'" . $this->db->escape((string) ($order['id'] ?? '')) . "'",
            'woo_order_number' => "'" . $this->db->escape((string) ($order['number'] ?? ($order['id'] ?? ''))) . "'",
            'woo_transaction_id' => "'" . $this->db->escape((string) ($order['transaction_id'] ?? '')) . "'",
            'payment_method' => "'" . $this->db->escape((string) ($order['payment_method'] ?? '')) . "'",
            'dolibarr_bank_account_id' => (int) $bankId,
            'gross_amount' => price2num($gross, 'MT'),
            'fee_amount' => price2num($fee, 'MT'),
            'fee_source' => "'" . $this->db->escape((string) ($order['_fah_fee_source'] ?? (($order['_fah_connector'] ?? 'woocommerce') === 'woocommerce' ? 'WooCommerce order metadata' : ucfirst((string) ($order['_fah_connector'] ?? '')) . ' API'))) . "'",
            'payout_amount' => price2num($payoutAmount, 'MT'),
            'woo_payout_raw' => $wooPayoutRaw > 0 ? price2num($wooPayoutRaw, 'MT') : 'NULL',
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
        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'fah_sync_log (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_values($data)) . ')';
        $updates = array();
        foreach (array_keys($data) as $key) {
            if (in_array($key, array('entity', 'connector', 'woo_order_id'), true)) continue;
            $updates[] = $key . '=VALUES(' . $key . ')';
        }
        if (!empty($updates)) $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
        return $this->db->query($sql);
    }

    private function isOrderSynced($orderId, $connector = 'woocommerce')
    {
        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'fah_sync_log WHERE entity=' . (int) $this->conf->entity
            . (in_array('connector', $this->getTableColumns(MAIN_DB_PREFIX . 'fah_sync_log'), true)
                ? " AND connector='" . $this->db->escape((string) $connector) . "'" : '')
            . " AND woo_order_id='" . $this->db->escape((string) $orderId) . "' AND sync_status IN ('synced','dryrun')";
        $res = $this->db->query($sql);
        return ($res && $this->db->num_rows($res) > 0);
    }

    private function logConnectorCondition($connector)
    {
        if (!in_array('connector', $this->getTableColumns(MAIN_DB_PREFIX . 'fah_sync_log'), true)) return '';
        return " AND connector='" . $this->db->escape((string) $connector) . "'";
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


    public function setConst($name, $value, $type = 'chaine')
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

    /**
     * Read a module constant.
     *
     * Public because integration hooks receive the Finance Automation Hub instance and
     * use it to read shared WooCommerce/PDF configuration.
     */
    public function getConst($name, $default = '')
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
