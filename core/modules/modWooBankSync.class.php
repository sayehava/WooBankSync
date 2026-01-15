<?php
/* Copyright (C) 2026 Sayeh Ava Pazouki
 * Dolibarr module descriptor for WooCommerce Bank Sync.
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modWooBankSync extends DolibarrModules
{
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;
        $this->numero = 104357;
        $this->rights_class = 'woobanksync';
        $this->family = 'financial';
        $this->module_position = 500;
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'Sync paid WooCommerce orders into Dolibarr bank accounts without creating Dolibarr invoices.';
        $this->descriptionlong = 'Imports WooCommerce paid orders as bank/cash movements: gross order deposits and payment gateway fees. WooCommerce remains the invoice master.';
        $this->editor_name = 'TASG / Sayeh Ava Pazouki';
        $this->version = '1.1.25';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'bank';
        $this->module_parts = array(
            'triggers' => 0,
            'hooks' => array(),
            'cron' => 1,
        );
        $this->dirs = array('/woobanksync/temp');
        $this->config_page_url = array('setup.php@woobanksync');
        $this->hidden = false;
        $this->depends = array('modBanque');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array('woobanksync@woobanksync');
        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(16, 0);

        $this->const = array(
            array('WBS_WOO_URL', 'chaine', '', 'WooCommerce store URL', 0, 'current', 1),
            array('WBS_WOO_CONSUMER_KEY', 'chaine', '', 'WooCommerce consumer key', 0, 'current', 1),
            array('WBS_WOO_CONSUMER_SECRET', 'password', '', 'WooCommerce consumer secret', 0, 'current', 1),
            array('WBS_SYNC_FROM_DATE', 'chaine', '', 'Sync from date YYYY-MM-DD', 0, 'current', 1),
            array('WBS_ORDER_STATUSES', 'chaine', 'processing,completed', 'WooCommerce order statuses', 0, 'current', 1),
            array('WBS_GATEWAY_PAYPAL', 'chaine', 'paypal,ppcp-gateway,ppec_paypal', 'PayPal method IDs', 0, 'current', 1),
            array('WBS_GATEWAY_STRIPE', 'chaine', 'stripe,woocommerce_payments', 'Stripe method IDs', 0, 'current', 1),
            array('WBS_GATEWAY_AMAZONPAY', 'chaine', 'amazon_payments,amazonpay,amazon_pay', 'Amazon Pay method IDs', 0, 'current', 1),
            array('WBS_GATEWAY_BANK', 'chaine', 'bacs,direct_bank_transfer', 'Bank transfer method IDs', 0, 'current', 1),
            array('WBS_PAYPAL_BANK_ID', 'chaine', '', 'Dolibarr PayPal bank id', 0, 'current', 1),
            array('WBS_STRIPE_BANK_ID', 'chaine', '', 'Dolibarr Stripe bank id', 0, 'current', 1),
            array('WBS_AMAZONPAY_BANK_ID', 'chaine', '', 'Dolibarr Amazon Pay bank id', 0, 'current', 1),
            array('WBS_DIRECT_BANK_ID', 'chaine', '', 'Dolibarr direct bank id', 0, 'current', 1),
            array('WBS_PAYPAL_FEE_KEYS', 'chaine', '_paypal_fee,_ppcp_paypal_fees,paypal_fee,PayPal Fee', 'PayPal fee meta keys', 0, 'current', 1),
            array('WBS_STRIPE_FEE_KEYS', 'chaine', '_stripe_fee,stripe_fee,_wcpay_fee', 'Stripe fee meta keys', 0, 'current', 1),
            array('WBS_AMAZONPAY_FEE_KEYS', 'chaine', '_amazon_pay_fee,amazon_pay_fee', 'Amazon Pay fee meta keys', 0, 'current', 1),
            array('WBS_DRY_RUN', 'yesno', '0', 'Dry run mode', 0, 'current', 1),
            array('WBS_GATEWAYS_JSON', 'chaine', '', 'Detected WooCommerce gateways JSON', 0, 'current', 1),
            array('WBS_META_KEYS_JSON', 'chaine', '', 'Detected WooCommerce meta keys JSON', 0, 'current', 1),
            array('WBS_GATEWAY_MAP_JSON', 'chaine', '', 'Dynamic gateway mapping JSON', 0, 'current', 1),
            array('WBS_WOO_INVOICE_AVAILABLE', 'yesno', '0', 'Woo invoice meta available', 0, 'current', 1),
            array('WBS_WOO_INVOICE_KEYS_JSON', 'chaine', '', 'Woo invoice meta keys JSON', 0, 'current', 1),
            array('WBS_DOCUMENT_SYNC_ENABLED', 'yesno', '0', 'Store Woo invoice number on bank lines', 0, 'current', 1),
            array('WBS_DOCUMENT_FOLDER_ID', 'chaine', '', 'Dolibarr ECM folder id for Woo invoices', 0, 'current', 1),
        );

        $this->tabs = array();
        $this->dictionaries = array();
        $this->boxes = array();

        $this->menu = array();
        $r = 0;
        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=bank',
            'type' => 'left',
            'titre' => 'WooBankSync',
            'mainmenu' => 'bank',
            'leftmenu' => 'woobanksync',
            'url' => '/custom/woobanksync/index.php',
            'langs' => 'woobanksync@woobanksync',
            'position' => 1100,
            'enabled' => '$conf->woobanksync->enabled',
            'perms' => '$user->hasRight("banque", "lire")',
            'target' => '',
            'user' => 2,
        );

        $this->rights = array();
        $this->rights[0][0] = 1043571;
        $this->rights[0][1] = 'Read WooCommerce Bank Sync';
        $this->rights[0][4] = 'read';
        $this->rights[0][5] = 'read';
        $this->rights[0][6] = 1;
        $this->rights[1][0] = 1043572;
        $this->rights[1][1] = 'Run WooCommerce Bank Sync';
        $this->rights[1][4] = 'run';
        $this->rights[1][5] = 'run';
        $this->rights[1][6] = 0;

        $this->cronjobs = array(
            array(
                'label' => 'WooCommerce Bank Sync',
                'jobtype' => 'command',
                'class' => '',
                'objectname' => '',
                'method' => '',
                'parameters' => '',
                'comment' => 'Run WooCommerce order to bank account sync',
                'frequency' => 3600,
                'unitfrequency' => 3600,
                'status' => 0,
                'test' => '$conf->woobanksync->enabled',
                'priority' => 50,
                'command' => DOL_DOCUMENT_ROOT . '/custom/woobanksync/scripts/sync.php',
            ),
        );
    }

    public function init($options = '')
    {
        $sql = array();
        $result = $this->_load_tables('/woobanksync/sql/');
        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
