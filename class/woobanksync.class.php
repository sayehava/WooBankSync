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
}
