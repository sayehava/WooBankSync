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
}
