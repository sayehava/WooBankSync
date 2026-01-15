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
}
