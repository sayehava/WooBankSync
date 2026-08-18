<?php

class WbsWooCommerceClient
{
    private $baseUrl;
    private $consumerKey;
    private $consumerSecret;
    public $error = '';
    public $lastTotalItems = 0;
    public $lastTotalPages = 0;

    public function __construct($baseUrl, $consumerKey, $consumerSecret)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->consumerKey = (string) $consumerKey;
        $this->consumerSecret = (string) $consumerSecret;
    }

    public function getOrders($statuses, $afterDate = '', $page = 1, $perPage = 50)
    {
        $params = array(
            'status' => implode(',', (array) $statuses),
            'orderby' => 'date',
            'order' => 'asc',
            'page' => (int) $page,
            'per_page' => (int) $perPage,
        );

        $after = $this->normalizeAfterDate($afterDate);
        if (!empty($after)) {
            $params['after'] = $after;
        }

        $result = $this->request('GET', '/wp-json/wc/v3/orders', $params);

        // Some WooCommerce/German locale installations are strict about the
        // REST API `after` parameter. If WooCommerce rejects it, retry once
        // without `after` instead of failing the whole sync.
        if ($result === false && !empty($params['after']) && stripos($this->error, 'after') !== false) {
            unset($params['after']);
            $oldError = $this->error;
            $result = $this->request('GET', '/wp-json/wc/v3/orders', $params);
            if ($result === false && empty($this->error)) {
                $this->error = $oldError;
            }
        }

        return $result;
    }

    private function normalizeAfterDate($afterDate)
    {
        $afterDate = trim((string) $afterDate);
        if ($afterDate === '') {
            return '';
        }

        // Accept only YYYY-MM-DD from the setup field. Anything else is ignored
        // to avoid WooCommerce HTTP 400 invalid parameter errors.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $afterDate)) {
            return '';
        }

        $dt = DateTime::createFromFormat('!Y-m-d H:i:s', $afterDate . ' 00:00:00', new DateTimeZone('UTC'));
        if (!$dt) {
            return '';
        }

        // WooCommerce REST accepts ISO 8601 datetime. Use Zulu time so the
        // timezone is explicit and URL encoding is safe.
        return $dt->format('Y-m-d\T00:00:00\Z');
    }

    public function getRecentOrders($limit = 20)
    {
        return $this->request('GET', '/wp-json/wc/v3/orders', array(
            'orderby' => 'date',
            'order' => 'desc',
            'page' => 1,
            'per_page' => (int) $limit,
        ));
    }

    public function getOrdersByIds(array $ids, array $fields = array())
    {
        if (empty($ids)) return array();
        $params = array(
            'include' => implode(',', array_map('intval', $ids)),
            'per_page' => count($ids),
        );
        if (!empty($fields)) {
            $params['_fields'] = implode(',', array_values(array_unique($fields)));
        }
        return $this->request('GET', '/wp-json/wc/v3/orders', $params);
    }

    public function getPaymentGateways()
    {
        return $this->request('GET', '/wp-json/wc/v3/payment_gateways', array());
    }

    public function getProducts($page = 1, $perPage = 100)
    {
        return $this->request('GET', '/wp-json/wc/v3/products', array(
            'page' => max(1, (int) $page),
            'per_page' => max(1, min(100, (int) $perPage)),
            'orderby' => 'id',
            'order' => 'asc',
        ));
    }

    public function getProductVariations($productId, $page = 1, $perPage = 100)
    {
        return $this->request('GET', '/wp-json/wc/v3/products/' . (int) $productId . '/variations', array(
            'page' => max(1, (int) $page),
            'per_page' => max(1, min(100, (int) $perPage)),
            'orderby' => 'id',
            'order' => 'asc',
        ));
    }

    public function testConnection()
    {
        $result = $this->request('GET', '/wp-json/wc/v3/system_status', array());
        return $result !== false;
    }

    private function request($method, $path, $params = array())
    {
        $this->error = '';
        $this->lastTotalItems = 0;
        $this->lastTotalPages = 0;
        if (empty($this->baseUrl) || empty($this->consumerKey) || empty($this->consumerSecret)) {
            $this->error = 'WooCommerce URL, consumer key or consumer secret is missing.';
            return false;
        }

        $url = $this->baseUrl . $path;
        $params['consumer_key'] = $this->consumerKey;
        $params['consumer_secret'] = $this->consumerSecret;
        $url .= '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
        curl_setopt($ch, CURLOPT_USERAGENT, 'Commerce-Automation-Hub/3.0');
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $headerLine) {
            $length = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) !== 2) return $length;

            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if ($name === 'x-wp-total') $this->lastTotalItems = max(0, (int) $value);
            if ($name === 'x-wp-totalpages') $this->lastTotalPages = max(0, (int) $value);
            return $length;
        });

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || !empty($curlError)) {
            $this->error = 'cURL error: ' . $curlError;
            return false;
        }

        $json = json_decode($body, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = is_array($json) && !empty($json['message']) ? $json['message'] : $body;
            $this->error = 'WooCommerce HTTP ' . $httpCode . ': ' . $message;
            return false;
        }

        if (!is_array($json)) {
            $this->error = 'Invalid JSON response from WooCommerce.';
            return false;
        }

        return $json;
    }
}
