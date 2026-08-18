<?php

/** Read-only sales and provider-cost analytics for Commerce Automation Hub. */
class DchSalesReport
{
    private $db;
    private $conf;

    public function __construct($db, $conf)
    {
        $this->db = $db;
        $this->conf = $conf;
    }

    public function normalizeFilters(array $input)
    {
        $period = strtolower(trim((string) ($input['period'] ?? 'month')));
        if (!in_array($period, array('month', 'year', 'range', 'all'), true)) $period = 'month';
        $currentYear = (int) date('Y');
        $requestedYear = (int) ($input['year'] ?? 0);
        $requestedMonth = (int) ($input['month'] ?? 0);
        $year = $requestedYear > 0 ? max(2000, min(2100, $requestedYear)) : $currentYear;
        $month = $requestedMonth > 0 ? max(1, min(12, $requestedMonth)) : (int) date('n');
        $from = $this->validDate($input['from'] ?? '');
        $to = $this->validDate($input['to'] ?? '');
        if ($period === 'range' && $from === '' && $to === '') {
            $from = date('Y-m-01');
            $to = date('Y-m-d');
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            $swap = $from;
            $from = $to;
            $to = $swap;
        }
        $connector = strtolower(trim((string) ($input['connector'] ?? '')));
        if (!in_array($connector, array('', 'woocommerce', 'amazon', 'sumup'), true)) $connector = '';
        $warehouseId = max(0, (int) ($input['warehouse_id'] ?? 0));
        return array(
            'period' => $period,
            'year' => $year,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'connector' => $connector,
            'warehouse_id' => $warehouseId,
        );
    }

    public function getSummary(array $filters)
    {
        $rows = array();
        $pieces = $filters['warehouse_id'] > 0
            ? 'SUM(COALESCE((SELECT SUM(sm.quantity) FROM ' . MAIN_DB_PREFIX . 'dch_stock_movement sm'
                . ' WHERE sm.entity=s.entity AND sm.connector=s.connector AND sm.external_order_id=s.external_order_id'
                . ' AND sm.external_line_id=s.external_line_id AND sm.event_key=\'sale\' AND sm.status=\'applied\''
                . ' AND sm.fk_warehouse=' . (int) $filters['warehouse_id'] . '),0))'
            : 'SUM(s.component_units)';
        $sql = 'SELECT s.connector, COUNT(DISTINCT s.external_order_id) AS orders_count,'
            . ' SUM(CASE WHEN s.is_bundle=0 THEN s.quantity ELSE 0 END) AS single_items,'
            . ' SUM(CASE WHEN s.is_bundle=1 THEN s.quantity ELSE 0 END) AS bundle_items,'
            . ' SUM(s.quantity) AS sold_items, ' . $pieces . ' AS inventory_pieces'
            . ' FROM ' . MAIN_DB_PREFIX . 'dch_sales_line s WHERE ' . $this->salesWhere($filters, 's')
            . ' GROUP BY s.connector ORDER BY s.connector';
        $resql = $this->db->query($sql);
        if ($resql) while ($obj = $this->db->fetch_object($resql)) $rows[] = $this->summaryRow($obj);
        return $rows;
    }

    public function getProductRows(array $filters)
    {
        $rows = array();
        $pieces = $filters['warehouse_id'] > 0
            ? 'SUM(COALESCE((SELECT SUM(sm.quantity) FROM ' . MAIN_DB_PREFIX . 'dch_stock_movement sm'
                . ' WHERE sm.entity=s.entity AND sm.connector=s.connector AND sm.external_order_id=s.external_order_id'
                . ' AND sm.external_line_id=s.external_line_id AND sm.event_key=\'sale\' AND sm.status=\'applied\''
                . ' AND sm.fk_warehouse=' . (int) $filters['warehouse_id'] . '),0))'
            : 'SUM(s.component_units)';
        $sql = 'SELECT s.connector, s.fk_catalog_product, s.external_sku, s.product_label, s.is_bundle, s.source_origin,'
            . ' COUNT(DISTINCT s.external_order_id) AS orders_count, SUM(s.quantity) AS sold_items, ' . $pieces . ' AS inventory_pieces'
            . ' FROM ' . MAIN_DB_PREFIX . 'dch_sales_line s WHERE ' . $this->salesWhere($filters, 's')
            . ' GROUP BY s.connector, s.fk_catalog_product, s.external_sku, s.product_label, s.is_bundle, s.source_origin'
            . ' ORDER BY s.connector, sold_items DESC, s.product_label';
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $rows[] = array(
                    'connector' => (string) $obj->connector,
                    'sku' => (string) $obj->external_sku,
                    'label' => (string) $obj->product_label,
                    'type' => !empty($obj->is_bundle) ? 'Bundle / multipack' : 'Single',
                    'source_origin' => (string) $obj->source_origin,
                    'orders' => (int) $obj->orders_count,
                    'sold_items' => (float) $obj->sold_items,
                    'inventory_pieces' => (float) $obj->inventory_pieces,
                );
            }
        }
        return $rows;
    }

    public function getInventoryProductRows(array $filters)
    {
        $rows = array();
        $where = $this->salesWhere($filters, 's', false);
        if ($filters['warehouse_id'] > 0) {
            $warehouseId = (int) $filters['warehouse_id'];
            $defaults = array(
                'woocommerce' => (int) ($this->conf->global->DCH_WOOCOMMERCE_WAREHOUSE_ID ?? 0),
                'amazon' => (int) ($this->conf->global->DCH_AMAZON_WAREHOUSE_ID ?? 0),
                'sumup' => (int) ($this->conf->global->DCH_SUMUP_WAREHOUSE_ID ?? 0),
            );
            $fallback = "CASE s.connector WHEN 'woocommerce' THEN " . $defaults['woocommerce'] . " WHEN 'amazon' THEN " . $defaults['amazon'] . " WHEN 'sumup' THEN " . $defaults['sumup'] . ' ELSE 0 END';
            $where .= ' AND COALESCE(NULLIF(bc.fk_warehouse,0),' . $fallback . ')=' . $warehouseId;
        }
        $sql = 'SELECT s.connector, p.rowid AS product_id, p.ref, p.label, COUNT(DISTINCT s.external_order_id) AS orders_count,'
            . ' SUM(CASE WHEN s.is_bundle=0 THEN s.quantity*bc.quantity ELSE 0 END) AS direct_units,'
            . ' SUM(CASE WHEN s.is_bundle=1 THEN s.quantity*bc.quantity ELSE 0 END) AS bundle_units,'
            . ' SUM(s.quantity*bc.quantity) AS total_units'
            . ' FROM ' . MAIN_DB_PREFIX . 'dch_sales_line s'
            . ' INNER JOIN ' . MAIN_DB_PREFIX . 'dch_bundle_component bc ON bc.entity=s.entity AND bc.fk_catalog_product=s.fk_catalog_product'
            . ' INNER JOIN ' . MAIN_DB_PREFIX . 'product p ON p.rowid=bc.fk_product'
            . ' WHERE ' . $where
            . ' GROUP BY s.connector, p.rowid, p.ref, p.label ORDER BY total_units DESC, p.ref, s.connector';
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $rows[] = array(
                    'connector' => (string) $obj->connector,
                    'product_id' => (int) $obj->product_id,
                    'ref' => (string) $obj->ref,
                    'label' => (string) $obj->label,
                    'orders' => (int) $obj->orders_count,
                    'direct_units' => (float) $obj->direct_units,
                    'bundle_units' => (float) $obj->bundle_units,
                    'total_units' => (float) $obj->total_units,
                );
            }
        }
        return $rows;
    }

    /** Platform x actual source warehouse, based on applied native stock movements. */
    public function getWarehouseRows(array $filters)
    {
        $rows = array();
        $movementWhere = $this->movementWhere($filters, 'sm');
        $sql = 'SELECT routed.connector, routed.fk_warehouse, routed.warehouse_ref, routed.warehouse_label,'
            . ' COUNT(DISTINCT routed.external_order_id) AS orders_count, SUM(routed.sold_items) AS sold_items,'
            . ' SUM(routed.inventory_pieces) AS inventory_pieces FROM ('
            . ' SELECT s.rowid AS sales_rowid, s.connector, s.external_order_id, sm.fk_warehouse,'
            . ' e.ref AS warehouse_ref, COALESCE(NULLIF(e.lieu,\'\'),e.description,\'\') AS warehouse_label,'
            . ' MAX(s.quantity) AS sold_items, SUM(sm.quantity) AS inventory_pieces'
            . ' FROM ' . MAIN_DB_PREFIX . 'dch_sales_line s'
            . ' INNER JOIN ' . MAIN_DB_PREFIX . 'dch_stock_movement sm ON sm.entity=s.entity AND sm.connector=s.connector'
            . ' AND sm.external_order_id=s.external_order_id AND sm.external_line_id=s.external_line_id'
            . ' LEFT JOIN ' . MAIN_DB_PREFIX . 'entrepot e ON e.rowid=sm.fk_warehouse'
            . ' WHERE ' . $this->salesWhere($filters, 's', false) . ' AND ' . $movementWhere
            . ' GROUP BY s.rowid, s.connector, s.external_order_id, sm.fk_warehouse, e.ref, e.lieu, e.description'
            . ') routed GROUP BY routed.connector, routed.fk_warehouse, routed.warehouse_ref, routed.warehouse_label'
            . ' ORDER BY routed.warehouse_ref, routed.connector';
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $rows[] = array(
                    'connector' => (string) $obj->connector,
                    'warehouse_id' => (int) $obj->fk_warehouse,
                    'warehouse_ref' => (string) $obj->warehouse_ref,
                    'warehouse_label' => (string) $obj->warehouse_label,
                    'orders' => (int) $obj->orders_count,
                    'sold_items' => (float) $obj->sold_items,
                    'inventory_pieces' => (float) $obj->inventory_pieces,
                );
            }
        }
        return $rows;
    }

    public function getFinancialRows(array $filters)
    {
        $rows = array();
        $sql = 'SELECT l.connector, l.currency, COUNT(DISTINCT l.woo_order_id) AS orders_count,'
            . ' SUM(l.gross_amount) AS gross_amount, SUM(l.fee_amount) AS fee_amount, SUM(l.payout_amount) AS payout_amount'
            . ' FROM ' . MAIN_DB_PREFIX . 'woobanksync_log l WHERE ' . $this->financeWhere($filters, 'l')
            . ' GROUP BY l.connector, l.currency ORDER BY l.connector, l.currency';
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $rows[] = array(
                    'connector' => (string) $obj->connector,
                    'currency' => (string) $obj->currency,
                    'orders' => (int) $obj->orders_count,
                    'gross' => (float) $obj->gross_amount,
                    'fees' => (float) $obj->fee_amount,
                    'net' => (float) $obj->payout_amount,
                );
            }
        }
        return $rows;
    }

    public function totals(array $summary)
    {
        $total = array('connector' => 'all', 'orders' => 0, 'single_items' => 0.0, 'bundle_items' => 0.0, 'sold_items' => 0.0, 'inventory_pieces' => 0.0);
        foreach ($summary as $row) foreach (array('orders', 'single_items', 'bundle_items', 'sold_items', 'inventory_pieces') as $key) $total[$key] += $row[$key];
        return $total;
    }

    public function periodLabel(array $filters)
    {
        if ($filters['period'] === 'month') return sprintf('%04d-%02d', $filters['year'], $filters['month']);
        if ($filters['period'] === 'year') return (string) $filters['year'];
        if ($filters['period'] === 'range') return ($filters['from'] !== '' ? $filters['from'] : 'beginning') . ' to ' . ($filters['to'] !== '' ? $filters['to'] : 'today');
        return 'All dates';
    }

    private function summaryRow($obj)
    {
        return array(
            'connector' => (string) $obj->connector,
            'orders' => (int) $obj->orders_count,
            'single_items' => (float) $obj->single_items,
            'bundle_items' => (float) $obj->bundle_items,
            'sold_items' => (float) $obj->sold_items,
            'inventory_pieces' => (float) $obj->inventory_pieces,
        );
    }

    private function salesWhere(array $filters, $alias, $warehouse = true)
    {
        $where = array($alias . '.entity=' . (int) $this->conf->entity);
        if ($filters['connector'] !== '') $where[] = $alias . ".connector='" . $this->db->escape($filters['connector']) . "'";
        $this->appendPeriodWhere($where, $filters, $alias . '.date_order');
        if ($warehouse && $filters['warehouse_id'] > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM ' . MAIN_DB_PREFIX . 'dch_stock_movement whsm'
                . ' WHERE whsm.entity=' . $alias . '.entity AND whsm.connector=' . $alias . '.connector'
                . ' AND whsm.external_order_id=' . $alias . '.external_order_id AND whsm.external_line_id=' . $alias . '.external_line_id'
                . " AND whsm.event_key='sale' AND whsm.status='applied' AND whsm.fk_warehouse=" . (int) $filters['warehouse_id'] . ')';
        }
        return implode(' AND ', $where);
    }

    private function movementWhere(array $filters, $alias)
    {
        $where = array($alias . '.entity=' . (int) $this->conf->entity, $alias . ".event_key='sale'", $alias . ".status='applied'");
        if ($filters['warehouse_id'] > 0) $where[] = $alias . '.fk_warehouse=' . (int) $filters['warehouse_id'];
        return implode(' AND ', $where);
    }

    private function financeWhere(array $filters, $alias)
    {
        $where = array(
            $alias . '.entity=' . (int) $this->conf->entity,
            '(' . $alias . ".sync_status IN ('synced','dryrun') OR (" . $alias . ".connector IN ('amazon','sumup') AND " . $alias . ".sync_status='skipped'))",
        );
        if ($filters['connector'] !== '') $where[] = $alias . ".connector='" . $this->db->escape($filters['connector']) . "'";
        $this->appendPeriodWhere($where, $filters, $alias . '.date_order');
        if ($filters['warehouse_id'] > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM ' . MAIN_DB_PREFIX . 'dch_stock_movement fsm'
                . ' WHERE fsm.entity=' . $alias . '.entity AND fsm.connector=' . $alias . '.connector'
                . ' AND fsm.external_order_id=' . $alias . '.woo_order_id AND fsm.event_key=\'sale\''
                . " AND fsm.status='applied' AND fsm.fk_warehouse=" . (int) $filters['warehouse_id'] . ')';
        }
        return implode(' AND ', $where);
    }

    private function appendPeriodWhere(array &$where, array $filters, $column)
    {
        if ($filters['period'] === 'month') {
            $start = sprintf('%04d-%02d-01', $filters['year'], $filters['month']);
            $end = date('Y-m-d', strtotime($start . ' +1 month'));
            $where[] = $column . ">='" . $this->db->escape($start) . " 00:00:00'";
            $where[] = $column . "<'" . $this->db->escape($end) . " 00:00:00'";
        } elseif ($filters['period'] === 'year') {
            $where[] = $column . ">='" . (int) $filters['year'] . "-01-01 00:00:00'";
            $where[] = $column . "<'" . ((int) $filters['year'] + 1) . "-01-01 00:00:00'";
        } elseif ($filters['period'] === 'range') {
            if ($filters['from'] !== '') $where[] = $column . ">='" . $this->db->escape($filters['from']) . " 00:00:00'";
            if ($filters['to'] !== '') {
                $dayAfter = date('Y-m-d', strtotime($filters['to'] . ' +1 day'));
                $where[] = $column . "<'" . $this->db->escape($dayAfter) . " 00:00:00'";
            }
        }
    }

    private function validDate($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
        $parts = array_map('intval', explode('-', $value));
        return checkdate($parts[1], $parts[2], $parts[0]) ? $value : '';
    }
}
