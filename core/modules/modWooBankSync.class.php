<?php
/* Copyright (C) 2026 Sayeh Ava Pazouki
 * Dolibarr module descriptor for Dolli Commerce Hub.
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
        $this->description = 'Synchronize multichannel orders, payments, bundles and stock with Dolibarr.';
        $this->descriptionlong = 'Dolli Commerce Hub connects WooCommerce, Amazon Seller and SumUp to shared Dolibarr payment reconciliation, product mapping, bundle recipes and inventory movements.';
        $this->editor_name = 'TASG / Sayeh Ava Pazouki';
        $this->version = '2.2.1';
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
            array('DCH_STOCK_USER_ID', 'chaine', '', 'Fallback author for scheduled stock movements', 0, 'current', 1),
            array('DCH_WOOCOMMERCE_ENABLED', 'yesno', '1', 'Enable WooCommerce connector', 0, 'current', 1),
            array('DCH_WOOCOMMERCE_STOCK_ENABLED', 'yesno', '0', 'Enable WooCommerce stock deductions', 0, 'current', 1),
            array('DCH_WOOCOMMERCE_WAREHOUSE_ID', 'chaine', '', 'WooCommerce default Dolibarr warehouse', 0, 'current', 1),
            array('DCH_AMAZON_ENABLED', 'yesno', '0', 'Enable Amazon Seller connector', 0, 'current', 1),
            array('DCH_AMAZON_STOCK_ENABLED', 'yesno', '0', 'Enable Amazon stock deductions', 0, 'current', 1),
            array('DCH_AMAZON_WAREHOUSE_ID', 'chaine', '', 'Amazon default Dolibarr warehouse', 0, 'current', 1),
            array('DCH_AMAZON_LWA_CLIENT_ID', 'chaine', '', 'Amazon Login with Amazon client id', 0, 'current', 1),
            array('DCH_AMAZON_LWA_CLIENT_SECRET', 'password', '', 'Amazon Login with Amazon client secret', 0, 'current', 1),
            array('DCH_AMAZON_REFRESH_TOKEN', 'password', '', 'Amazon SP-API refresh token', 0, 'current', 1),
            array('DCH_AMAZON_SELLER_ID', 'chaine', '', 'Amazon seller id', 0, 'current', 1),
            array('DCH_AMAZON_MARKETPLACE_IDS', 'chaine', '', 'Amazon marketplace ids', 0, 'current', 1),
            array('DCH_AMAZON_REGION', 'chaine', 'eu', 'Amazon SP-API region', 0, 'current', 1),
            array('DCH_AMAZON_SYNC_FROM_DATE', 'chaine', '', 'Amazon sync start date', 0, 'current', 1),
            array('DCH_AMAZON_BANK_ID', 'chaine', '', 'Amazon Dolibarr bank account', 0, 'current', 1),
            array('DCH_AMAZON_FINANCE_ENABLED', 'yesno', '1', 'Read exact Amazon fees and proceeds from Finances API', 0, 'current', 1),
            array('DCH_AMAZON_FINANCE_MAP_JSON', 'chaine', '', 'Amazon virtual bank mapping', 0, 'current', 1),
            array('DCH_SUMUP_ENABLED', 'yesno', '0', 'Enable SumUp connector', 0, 'current', 1),
            array('DCH_SUMUP_STOCK_ENABLED', 'yesno', '0', 'Enable SumUp stock deductions', 0, 'current', 1),
            array('DCH_SUMUP_WAREHOUSE_ID', 'chaine', '', 'SumUp default Dolibarr warehouse', 0, 'current', 1),
            array('DCH_SUMUP_ACCESS_TOKEN', 'password', '', 'SumUp API access token', 0, 'current', 1),
            array('DCH_SUMUP_MERCHANT_CODE', 'chaine', '', 'SumUp merchant code', 0, 'current', 1),
            array('DCH_SUMUP_SYNC_FROM_DATE', 'chaine', '', 'SumUp sync start date', 0, 'current', 1),
            array('DCH_SUMUP_BANK_ID', 'chaine', '', 'SumUp Dolibarr bank account', 0, 'current', 1),
            array('DCH_SUMUP_FINANCE_MAP_JSON', 'chaine', '', 'SumUp virtual bank mapping', 0, 'current', 1),
            array('DCH_SUMUP_POS_DUPLICATE_MODE', 'chaine', 'off', 'Prevent duplicate imports when a Dolibarr POS SumUp module owns transactions', 0, 'current', 1),
            array('DCH_SUMUP_POS_REFERENCE_PREFIXES', 'chaine', '', 'Reference prefixes created by the Dolibarr POS SumUp module', 0, 'current', 1),
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
            'fk_menu' => '0',
            'type' => 'top',
            'titre' => 'Dolli Commerce Hub',
            'mainmenu' => 'woobanksync',
            'leftmenu' => '',
            'url' => '/custom/woobanksync/index.php?mainmenu=woobanksync',
            'langs' => 'woobanksync@woobanksync',
            'position' => 1000,
            'enabled' => '$conf->woobanksync->enabled',
            'perms' => '$user->hasRight("woobanksync", "read") || $user->admin',
            'target' => '',
            'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=woobanksync',
            'type' => 'left',
            'titre' => 'Dashboard',
            'mainmenu' => 'woobanksync',
            'leftmenu' => 'woobanksync_dashboard',
            'url' => '/custom/woobanksync/index.php?mainmenu=woobanksync',
            'langs' => 'woobanksync@woobanksync',
            'position' => 100,
            'enabled' => '$conf->woobanksync->enabled',
            'perms' => '$user->hasRight("woobanksync", "read") || $user->admin',
            'target' => '',
            'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=woobanksync',
            'type' => 'left',
            'titre' => 'Sales analytics',
            'mainmenu' => 'woobanksync',
            'leftmenu' => 'woobanksync_reports',
            'url' => '/custom/woobanksync/reports.php?mainmenu=woobanksync',
            'langs' => 'woobanksync@woobanksync',
            'position' => 101,
            'enabled' => '$conf->woobanksync->enabled',
            'perms' => '$user->hasRight("woobanksync", "read") || $user->admin',
            'target' => '',
            'user' => 2,
        );
        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=woobanksync',
            'type' => 'left',
            'titre' => 'Configuration',
            'mainmenu' => 'woobanksync',
            'leftmenu' => 'woobanksync_setup',
            'url' => '/custom/woobanksync/admin/setup.php?mainmenu=woobanksync',
            'langs' => 'woobanksync@woobanksync',
            'position' => 102,
            'enabled' => '$conf->woobanksync->enabled',
            'perms' => '$user->admin',
            'target' => '',
            'user' => 2,
        );

        $this->rights = array();
        $this->rights[0][0] = 1043571;
        $this->rights[0][1] = 'Read Dolli Commerce Hub';
        $this->rights[0][4] = 'read';
        $this->rights[0][5] = 'read';
        $this->rights[0][6] = 1;
        $this->rights[1][0] = 1043572;
        $this->rights[1][1] = 'Run Dolli Commerce Hub synchronizations';
        $this->rights[1][4] = 'run';
        $this->rights[1][5] = 'run';
        $this->rights[1][6] = 0;

        $this->cronjobs = array(
            array(
                'label' => 'Dolli Commerce Hub: enabled channel sync',
                'jobtype' => 'command',
                'class' => '',
                'objectname' => '',
                'method' => '',
                'parameters' => '',
                'comment' => 'Synchronize enabled Dolli Commerce Hub sales channels',
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
        // Pre-2.2 used a left menu under Bank/Cash. Because that menu is no
        // longer present in this descriptor, Dolibarr's standard cleanup may
        // not recognize the old stored row during an upgrade.
        $this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . "menu WHERE url LIKE '%/custom/woobanksync/index.php%' AND (mainmenu='bank' OR leftmenu='woobanksync')");
        $result = $this->_load_tables('/woobanksync/sql/');
        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
