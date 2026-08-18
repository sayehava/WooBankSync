<?php

/** Minimal Amazon Selling Partner Orders API client (Orders v2026-01-01). */
class FahAmazonClient
{
    private $clientId;
    private $clientSecret;
    private $refreshToken;
    private $sellerId;
    private $marketplaceIds;
    private $endpoint;
    private $accessToken = '';
    private static $lastFinanceRequestAt = 0.0;
    public $error = '';

    public function __construct($clientId, $clientSecret, $refreshToken, $sellerId, $marketplaceIds, $region = 'eu')
    {
        $this->clientId = trim((string) $clientId);
        $this->clientSecret = trim((string) $clientSecret);
        $this->refreshToken = trim((string) $refreshToken);
        $this->sellerId = trim((string) $sellerId);
        $this->marketplaceIds = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $marketplaceIds))));
        $endpoints = array(
            'eu' => 'https://sellingpartnerapi-eu.amazon.com',
            'na' => 'https://sellingpartnerapi-na.amazon.com',
            'fe' => 'https://sellingpartnerapi-fe.amazon.com',
        );
        $region = strtolower(trim((string) $region));
        $this->endpoint = isset($endpoints[$region]) ? $endpoints[$region] : $endpoints['eu'];
    }

    public function getOrders($createdAfter = '', $paginationToken = '', $limit = 50)
    {
        if (empty($this->marketplaceIds)) {
            $this->error = 'Amazon marketplace ID is missing.';
            return false;
        }
        $params = array(
            'marketplaceIds' => implode(',', $this->marketplaceIds),
            'maxResultsPerPage' => max(1, min(100, (int) $limit)),
            // Deliberately omit restricted buyer/recipient PII. The connector
            // only needs line items, fulfilment state and financial proceeds.
            'includedData' => 'PROCEEDS,FULFILLMENT,CANCELLATION',
        );
        $after = $this->normalizeDate($createdAfter);
        if ($after !== '') $params['createdAfter'] = $after;
        if ($paginationToken !== '') $params['paginationToken'] = $paginationToken;

        $json = $this->request('/orders/2026-01-01/orders', $params);
        if ($json === false) return false;
        $payload = isset($json['payload']) && is_array($json['payload']) ? $json['payload'] : $json;
        $orders = $payload['orders'] ?? $payload['Orders'] ?? array();
        $pagination = $payload['pagination'] ?? $payload['Pagination'] ?? array();
        $nextToken = (string) ($payload['nextToken'] ?? $payload['NextToken'] ?? $pagination['nextToken'] ?? $pagination['NextToken'] ?? '');
        if (!is_array($orders)) $orders = array();

        $normalized = array();
        foreach ($orders as $order) {
            if (!is_array($order)) continue;
            $status = (string) ($order['fulfillment']['fulfillmentStatus'] ?? $order['orderStatus'] ?? $order['OrderStatus'] ?? '');
            if (stripos($status, 'cancel') !== false) continue;
            $normalized[] = $this->normalizeOrder($order);
        }
        return array('orders' => $normalized, 'next_token' => $nextToken);
    }

    public function getListings($pageToken = '', $pageSize = 20)
    {
        if ($this->sellerId === '' || empty($this->marketplaceIds)) {
            $this->error = 'Amazon seller ID or marketplace ID is missing.';
            return false;
        }
        $params = array(
            'marketplaceIds' => implode(',', $this->marketplaceIds),
            'includedData' => 'summaries',
            'pageSize' => max(1, min(20, (int) $pageSize)),
            'sortBy' => 'sku',
            'sortOrder' => 'ASC',
        );
        if ($pageToken !== '') $params['pageToken'] = $pageToken;
        $json = $this->request('/listings/2021-08-01/items/' . rawurlencode($this->sellerId), $params);
        if ($json === false) return false;
        $products = array();
        foreach ((array) ($json['items'] ?? array()) as $item) {
            if (!is_array($item)) continue;
            $summary = !empty($item['summaries'][0]) && is_array($item['summaries'][0]) ? $item['summaries'][0] : array();
            $sku = (string) ($item['sku'] ?? '');
            $asin = (string) ($summary['asin'] ?? '');
            $products[] = array(
                'product_id' => $sku !== '' ? 'sku:' . $sku : $asin,
                'sku' => $sku,
                'name' => (string) ($summary['itemName'] ?? $sku),
            );
        }
        return array(
            'products' => $products,
            'next_token' => (string) ($json['pagination']['nextToken'] ?? ''),
        );
    }

    public function getOrderFinancials($orderId)
    {
        $orderId = trim((string) $orderId);
        if ($orderId === '') return array('available' => false, 'fee' => 0.0, 'payout' => 0.0, 'currency' => '');

        // The default Finances API rate is 0.5 requests/second. Respect it so
        // a normal sync batch does not immediately receive HTTP 429 errors.
        $elapsed = microtime(true) - self::$lastFinanceRequestAt;
        if (self::$lastFinanceRequestAt > 0 && $elapsed < 2.05) usleep((int) ((2.05 - $elapsed) * 1000000));
        $params = array(
            'relatedIdentifierName' => 'ORDER_ID',
            'relatedIdentifierValue' => $orderId,
        );
        $available = false;
        $expenseTotal = 0.0;
        $payout = 0.0;
        $currency = '';
        $nextToken = '';
        $pages = 0;
        do {
            if ($nextToken !== '') $params['nextToken'] = $nextToken;
            $json = $this->request('/finances/2024-06-19/transactions', $params);
            self::$lastFinanceRequestAt = microtime(true);
            if ($json === false) return false;
            $payload = isset($json['payload']) && is_array($json['payload']) ? $json['payload'] : $json;
            $transactions = isset($payload['transactions']) && is_array($payload['transactions']) ? $payload['transactions'] : array();
            foreach ($transactions as $transaction) {
                if (!is_array($transaction)) continue;
                if (strtoupper((string) ($transaction['transactionStatus'] ?? '')) === 'DEFERRED') continue;
                $available = true;
                $amount = $transaction['totalAmount'] ?? array();
                if (is_array($amount)) {
                    $payout += (float) ($amount['currencyAmount'] ?? 0);
                    if ($currency === '') $currency = (string) ($amount['currencyCode'] ?? '');
                }
                list($hasTransactionExpenses, $transactionExpenses) = $this->expensesFromBreakdowns($transaction['breakdowns'] ?? array());
                if ($hasTransactionExpenses) {
                    $expenseTotal += $transactionExpenses;
                } else {
                    foreach ((array) ($transaction['items'] ?? array()) as $item) {
                        if (!is_array($item)) continue;
                        list($hasItemExpenses, $itemExpenses) = $this->expensesFromBreakdowns($item['breakdowns'] ?? array());
                        if ($hasItemExpenses) $expenseTotal += $itemExpenses;
                    }
                }
            }
            $nextToken = (string) ($payload['nextToken'] ?? '');
            $pages++;
            if ($nextToken !== '' && $pages < 20) {
                $elapsed = microtime(true) - self::$lastFinanceRequestAt;
                if ($elapsed < 2.05) usleep((int) ((2.05 - $elapsed) * 1000000));
            }
        } while ($nextToken !== '' && $pages < 20);
        // Expenses are normally negative and reversals positive. Taking the
        // absolute value after summing preserves fee reversals/refunds.
        return array('available' => $available, 'fee' => abs($expenseTotal), 'payout' => $payout, 'currency' => $currency);
    }

    private function normalizeOrder(array $order)
    {
        $id = (string) ($order['orderId'] ?? $order['amazonOrderId'] ?? $order['AmazonOrderId'] ?? '');
        $items = $order['orderItems'] ?? $order['items'] ?? $order['OrderItems'] ?? array();
        if (isset($items['orderItems']) && is_array($items['orderItems'])) $items = $items['orderItems'];
        $lines = array();
        foreach ((array) $items as $index => $item) {
            if (!is_array($item)) continue;
            $product = isset($item['product']) && is_array($item['product']) ? $item['product'] : $item;
            $sku = (string) ($product['sellerSku'] ?? $item['sellerSku'] ?? $item['SellerSKU'] ?? '');
            $asin = (string) ($product['asin'] ?? $item['asin'] ?? $item['ASIN'] ?? '');
            $lineId = (string) ($item['orderItemId'] ?? $item['OrderItemId'] ?? $index);
            $lines[] = array(
                'id' => $lineId,
                'product_id' => $sku !== '' ? 'sku:' . $sku : ($asin !== '' ? $asin : $lineId),
                'variation_id' => '',
                'sku' => $sku,
                'name' => (string) ($product['title'] ?? $item['title'] ?? $item['Title'] ?? ($sku !== '' ? $sku : $asin)),
                'quantity' => (float) ($item['quantityOrdered'] ?? $item['QuantityOrdered'] ?? $item['quantity'] ?? 0),
            );
        }
        $total = $order['proceeds']['grandTotal'] ?? $order['orderTotal'] ?? $order['OrderTotal'] ?? $order['proceeds']['orderTotal'] ?? array();
        $amount = is_array($total) ? ($total['amount'] ?? $total['Amount'] ?? 0) : $total;
        $currency = is_array($total) ? ($total['currencyCode'] ?? $total['CurrencyCode'] ?? 'EUR') : 'EUR';
        return array(
            'id' => $id,
            'number' => $id,
            'transaction_id' => $id,
            'payment_method' => 'amazon',
            'payment_method_title' => 'Amazon Seller',
            'total' => $amount,
            'currency' => $currency,
            'status' => (string) ($order['fulfillment']['fulfillmentStatus'] ?? $order['orderStatus'] ?? $order['OrderStatus'] ?? ''),
            'date_created' => (string) ($order['purchaseDate'] ?? $order['PurchaseDate'] ?? $order['createdTime'] ?? ''),
            'date_paid' => (string) ($order['purchaseDate'] ?? $order['PurchaseDate'] ?? $order['createdTime'] ?? ''),
            'line_items' => $lines,
        );
    }

    private function request($path, array $params)
    {
        $this->error = '';
        if ($this->accessToken === '' && !$this->refreshAccessToken()) return false;
        $url = $this->endpoint . $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'x-amz-access-token: ' . $this->accessToken));
        curl_setopt($ch, CURLOPT_USERAGENT, 'Finance-Automation-Hub/3.0');
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($body === false || $curlError !== '') {
            $this->error = 'Amazon connection error: ' . $curlError;
            return false;
        }
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $message = $json['errors'][0]['message'] ?? $json['message'] ?? $body;
            $this->error = 'Amazon HTTP ' . $code . ': ' . $message;
            return false;
        }
        if (!is_array($json)) {
            $this->error = 'Amazon returned invalid JSON.';
            return false;
        }
        return $json;
    }

    private function refreshAccessToken()
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->refreshToken === '') {
            $this->error = 'Amazon LWA client ID, client secret or refresh token is missing.';
            return false;
        }
        $ch = curl_init('https://api.amazon.com/auth/o2/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ), '', '&', PHP_QUERY_RFC3986));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        if ($body === false || $curlError !== '' || $code < 200 || $code >= 300 || empty($json['access_token'])) {
            $this->error = 'Amazon authorization failed' . ($curlError !== '' ? ': ' . $curlError : (!empty($json['error_description']) ? ': ' . $json['error_description'] : '.'));
            return false;
        }
        $this->accessToken = (string) $json['access_token'];
        return true;
    }

    private function normalizeDate($date)
    {
        $date = trim((string) $date);
        if ($date === '') return gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400);
        $timestamp = strtotime($date);
        return $timestamp === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function expensesFromBreakdowns($breakdowns)
    {
        foreach ((array) $breakdowns as $breakdown) {
            if (!is_array($breakdown)) continue;
            if (strcasecmp((string) ($breakdown['breakdownType'] ?? ''), 'Expenses') !== 0) continue;
            $amount = $breakdown['breakdownAmount'] ?? array();
            return array(true, (float) (is_array($amount) ? ($amount['currencyAmount'] ?? 0) : $amount));
        }
        return array(false, 0.0);
    }
}
