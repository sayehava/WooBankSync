<?php

class WbsWooCommerceClient
{
    private $baseUrl;
    private $consumerKey;
    private $consumerSecret;
    public $error = '';

    public function __construct($baseUrl, $consumerKey, $consumerSecret)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->consumerKey = (string) $consumerKey;
        $this->consumerSecret = (string) $consumerSecret;
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
}
