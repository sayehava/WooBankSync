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
}
