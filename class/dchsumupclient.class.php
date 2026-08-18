<?php

/** SumUp transaction-history client used for sales and product discovery. */
class DchSumUpClient
{
    private $accessToken;
    private $merchantCode;
    public $error = '';

    public function __construct($accessToken, $merchantCode)
    {
        $this->accessToken = trim((string) $accessToken);
        $this->merchantCode = trim((string) $merchantCode);
    }

    public function getTransactions($fromDate = '', $cursor = '', $limit = 50)
    {
        if ($this->merchantCode === '') {
            $this->error = 'SumUp merchant code is missing.';
            return false;
        }
        $params = array(
            'limit' => max(1, min(100, (int) $limit)),
            'order' => 'ascending',
        );
        if (trim((string) $cursor) !== '') {
            $params['oldest_ref'] = trim((string) $cursor);
        } elseif (trim((string) $fromDate) !== '') {
            $params['oldest_time'] = trim((string) $fromDate) . 'T00:00:00Z';
        }
        $json = $this->request('/v2.1/merchants/' . rawurlencode($this->merchantCode) . '/transactions/history', $params);
        if ($json === false) return false;
        $items = isset($json['items']) && is_array($json['items']) ? $json['items'] : (array) $json;
        $orders = array();
        foreach ($items as $transaction) {
            if (!is_array($transaction)) continue;
            $status = strtoupper((string) ($transaction['status'] ?? ''));
            $type = strtoupper((string) ($transaction['type'] ?? 'PAYMENT'));
            if ($status !== '' && !in_array($status, array('SUCCESSFUL', 'SUCCESS'), true)) continue;
            if (!in_array($type, array('PAYMENT', 'SALE'), true)) continue;
            $transactionId = (string) ($transaction['id'] ?? $transaction['transaction_id'] ?? '');
            // History rows are summaries. Always request transaction details:
            // that response is the authoritative source for fee_amount and
            // also supplies product lines when the history row omits them.
            if ($transactionId !== '') {
                $details = $this->request('/v2.1/merchants/' . rawurlencode($this->merchantCode) . '/transactions', array('id' => $transactionId));
                if ($details === false) return false;
                $transaction = array_merge($transaction, $details);
                if (!array_key_exists('fee_amount', $transaction)) {
                    $this->error = 'SumUp transaction ' . $transactionId . ' did not include fee_amount; no estimated bank entry was created.';
                    return false;
                }
            }
            $orders[] = $this->normalizeTransaction($transaction);
        }
        $nextCursor = '';
        foreach ((array) ($json['links'] ?? array()) as $link) {
            if (!is_array($link) || strtolower((string) ($link['rel'] ?? '')) !== 'next') continue;
            $query = (string) ($link['href'] ?? '');
            $queryString = parse_url($query, PHP_URL_QUERY);
            if ($queryString === null || $queryString === false) $queryString = ltrim($query, '?');
            $linkParams = array();
            parse_str($queryString, $linkParams);
            $nextCursor = (string) ($linkParams['oldest_ref'] ?? $linkParams['newest_ref'] ?? '');
            break;
        }
        return array('orders' => $orders, 'next_cursor' => $nextCursor, 'has_more' => $nextCursor !== '');
    }

    private function normalizeTransaction(array $transaction)
    {
        $id = (string) ($transaction['id'] ?? $transaction['transaction_id'] ?? $transaction['internal_id'] ?? '');
        $lines = array();
        foreach ((array) ($transaction['products'] ?? array()) as $index => $product) {
            if (!is_array($product)) continue;
            $name = trim((string) ($product['name'] ?? $product['description'] ?? ('Product ' . ($index + 1))));
            $sku = trim((string) ($product['sku'] ?? $product['product_code'] ?? ''));
            $externalId = (string) ($product['id'] ?? $product['product_id'] ?? '');
            if ($externalId === '') $externalId = 'item:' . sha1(strtolower($sku . '|' . $name . '|' . (string) ($product['price'] ?? '')));
            $lines[] = array(
                'id' => (string) ($product['id'] ?? $index),
                'product_id' => $externalId,
                'variation_id' => '',
                'sku' => $sku,
                'name' => $name,
                'quantity' => (float) ($product['quantity'] ?? 1),
            );
        }
        return array(
            'id' => $id,
            'number' => (string) ($transaction['transaction_code'] ?? $transaction['code'] ?? $id),
            'transaction_id' => $id,
            'payment_method' => 'sumup',
            'payment_method_title' => 'SumUp',
            'total' => $transaction['amount'] ?? 0,
            'fee' => $transaction['fee_amount'] ?? 0,
            '_dch_fee_source' => 'SumUp transaction details API',
            'currency' => (string) ($transaction['currency'] ?? 'EUR'),
            'status' => (string) ($transaction['status'] ?? ''),
            'date_created' => (string) ($transaction['timestamp'] ?? $transaction['date'] ?? ''),
            'date_paid' => (string) ($transaction['timestamp'] ?? $transaction['date'] ?? ''),
            'line_items' => $lines,
            '_dch_source_references' => array_values(array_filter(array_unique(array_map('strval', array(
                $transaction['client_transaction_id'] ?? '',
                $transaction['foreign_transaction_id'] ?? '',
                $transaction['checkout_reference'] ?? '',
                $transaction['reference'] ?? '',
                $transaction['transaction_code'] ?? '',
            ))))),
        );
    }

    private function request($path, array $params)
    {
        $this->error = '';
        if ($this->accessToken === '') {
            $this->error = 'SumUp access token is missing.';
            return false;
        }
        $url = 'https://api.sumup.com' . $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Authorization: Bearer ' . $this->accessToken));
        curl_setopt($ch, CURLOPT_USERAGENT, 'Commerce-Automation-Hub/3.0');
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($body === false || $curlError !== '') {
            $this->error = 'SumUp connection error: ' . $curlError;
            return false;
        }
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $message = $json['message'] ?? $json['error_message'] ?? $body;
            $this->error = 'SumUp HTTP ' . $code . ': ' . $message;
            return false;
        }
        if (!is_array($json)) {
            $this->error = 'SumUp returned invalid JSON.';
            return false;
        }
        return $json;
    }
}
