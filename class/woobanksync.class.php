<?php

require_once __DIR__ . '/woocommerceclient.class.php';

class WooBankSync
{
    private $db;
    private $conf;
    private $langs;
    public $errors = array();

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
            $this->setBankInvoiceNumber($bankLineGross, $invoiceNumber);
            if (!$this->setBankInvoiceExtraField($bankLineGross, $invoiceNumber)) {
                $this->db->rollback();
                $msg = 'Failed to store invoice number in the mapped bank-entry custom field for Woo order #' . $orderNumber . ': ' . $this->db->lasterror();
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
                $this->setBankInvoiceNumber($bankLineFee, $invoiceNumber);
                if (!$this->setBankInvoiceExtraField($bankLineFee, $invoiceNumber)) {
                    $this->db->rollback();
                    $msg = 'Failed to store invoice number in the mapped bank-entry custom field for Woo order #' . $orderNumber . ': ' . $this->db->lasterror();
                    $this->insertLog($order, $bankId, $gross, $fee, 0, 0, 'error', $msg, $dateOrder, $invoiceNumber, $payout);
                    return array('status' => 'errors', 'message' => $msg);
                }
            }
        }

        $status = $dryRun ? 'dryrun' : 'synced';
        $message = ($dryRun ? '[DRY RUN] ' : '') . 'Synced Woo order #' . $orderNumber . ' gross=' . price($gross) . ' fee=' . price($fee) . ' payout=' . price($payout) . ' gateway=' . $paymentMethod . ($mappedPaymentMethod !== $paymentMethod ? ' mapped_to=' . $mappedPaymentMethod : '');
        $this->insertLog($order, $bankId, $gross, $fee, $bankLineGross, $bankLineFee, $status, $message, $dateOrder, $invoiceNumber, $payout);
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
}
