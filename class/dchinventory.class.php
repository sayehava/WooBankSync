<?php

/**
 * Shared catalogue, bundle-recipe and stock-movement service for
 * Dolli Commerce Hub sales-channel connectors.
 */
class DchInventoryManager
{
    private $db;
    private $conf;

    public function __construct($db, $conf)
    {
        $this->db = $db;
        $this->conf = $conf;
    }

    public function ensureSchema()
    {
        $prefix = MAIN_DB_PREFIX;
        $queries = array(
            "CREATE TABLE IF NOT EXISTS {$prefix}dch_catalog_product (" .
                "rowid integer AUTO_INCREMENT PRIMARY KEY," .
                "entity integer NOT NULL DEFAULT 1," .
                "connector varchar(32) NOT NULL," .
                "external_product_id varchar(128) NOT NULL DEFAULT ''," .
                "external_variant_id varchar(128) NOT NULL DEFAULT ''," .
                "external_sku varchar(255) DEFAULT NULL," .
                "label varchar(255) DEFAULT NULL," .
                "stock_mode varchar(16) NOT NULL DEFAULT 'unmapped'," .
                "is_bundle integer NOT NULL DEFAULT 0," .
                "active integer NOT NULL DEFAULT 1," .
                "date_seen datetime DEFAULT NULL," .
                "tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP," .
                "UNIQUE KEY uk_dch_catalog_external (entity, connector, external_product_id, external_variant_id)," .
                "KEY idx_dch_catalog_sku (entity, connector, external_sku)" .
            ") ENGINE=innodb",
            "CREATE TABLE IF NOT EXISTS {$prefix}dch_bundle_component (" .
                "rowid integer AUTO_INCREMENT PRIMARY KEY," .
                "entity integer NOT NULL DEFAULT 1," .
                "fk_catalog_product integer NOT NULL," .
                "fk_product integer NOT NULL," .
                "fk_warehouse integer NOT NULL DEFAULT 0," .
                "quantity double(24,8) NOT NULL DEFAULT 1," .
                "tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP," .
                "UNIQUE KEY uk_dch_component_product (entity, fk_catalog_product, fk_product)," .
                "KEY idx_dch_component_catalog (fk_catalog_product)" .
            ") ENGINE=innodb",
            "CREATE TABLE IF NOT EXISTS {$prefix}dch_stock_movement (" .
                "rowid integer AUTO_INCREMENT PRIMARY KEY," .
                "entity integer NOT NULL DEFAULT 1," .
                "connector varchar(32) NOT NULL," .
                "external_order_id varchar(128) NOT NULL," .
                "external_order_number varchar(128) DEFAULT NULL," .
                "external_line_id varchar(128) NOT NULL," .
                "event_key varchar(32) NOT NULL DEFAULT 'sale'," .
                "fk_catalog_product integer DEFAULT NULL," .
                "fk_product integer NOT NULL," .
                "fk_warehouse integer NOT NULL," .
                "destination varchar(255) DEFAULT NULL," .
                "quantity double(24,8) NOT NULL DEFAULT 0," .
                "fk_stock_movement integer DEFAULT NULL," .
                "status varchar(16) NOT NULL DEFAULT 'pending'," .
                "error_message text," .
                "date_order datetime DEFAULT NULL," .
                "date_created datetime DEFAULT NULL," .
                "tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP," .
                "UNIQUE KEY uk_dch_stock_event (entity, connector, external_order_id, external_line_id, event_key, fk_product)," .
                "KEY idx_dch_stock_status (entity, connector, status)" .
            ") ENGINE=innodb",
            "CREATE TABLE IF NOT EXISTS {$prefix}dch_sales_line (" .
                "rowid integer AUTO_INCREMENT PRIMARY KEY," .
                "entity integer NOT NULL DEFAULT 1," .
                "connector varchar(32) NOT NULL," .
                "external_order_id varchar(128) NOT NULL," .
                "external_order_number varchar(128) DEFAULT NULL," .
                "external_line_id varchar(128) NOT NULL," .
                "fk_catalog_product integer DEFAULT NULL," .
                "external_product_id varchar(128) DEFAULT NULL," .
                "external_variant_id varchar(128) DEFAULT NULL," .
                "external_sku varchar(255) DEFAULT NULL," .
                "product_label varchar(255) DEFAULT NULL," .
                "quantity double(24,8) NOT NULL DEFAULT 0," .
                "is_bundle integer NOT NULL DEFAULT 0," .
                "component_units double(24,8) NOT NULL DEFAULT 0," .
                "source_origin varchar(32) NOT NULL DEFAULT 'connector'," .
                "date_order datetime DEFAULT NULL," .
                "date_recorded datetime DEFAULT NULL," .
                "tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP," .
                "UNIQUE KEY uk_dch_sales_line (entity, connector, external_order_id, external_line_id)," .
                "KEY idx_dch_sales_date (entity, date_order)," .
                "KEY idx_dch_sales_connector_date (entity, connector, date_order)" .
            ") ENGINE=innodb"
        );

        foreach ($queries as $sql) {
            if (!$this->db->query($sql)) return array(false, $this->db->lasterror());
        }
        $columnCheck = $this->db->query("SHOW COLUMNS FROM {$prefix}dch_catalog_product LIKE 'is_bundle'");
        if ($columnCheck && $this->db->num_rows($columnCheck) == 0) {
            if (!$this->db->query("ALTER TABLE {$prefix}dch_catalog_product ADD COLUMN is_bundle integer NOT NULL DEFAULT 0 AFTER stock_mode")) {
                return array(false, $this->db->lasterror());
            }
        }
        $upgrades = array(
            array("{$prefix}dch_bundle_component", 'fk_warehouse', 'integer NOT NULL DEFAULT 0 AFTER fk_product'),
            array("{$prefix}dch_stock_movement", 'external_order_number', 'varchar(128) DEFAULT NULL AFTER external_order_id'),
            array("{$prefix}dch_stock_movement", 'destination', 'varchar(255) DEFAULT NULL AFTER fk_warehouse'),
        );
        foreach ($upgrades as $upgrade) {
            $check = $this->db->query("SHOW COLUMNS FROM " . $upgrade[0] . " LIKE '" . $this->db->escape($upgrade[1]) . "'");
            if ($check && $this->db->num_rows($check) == 0 && !$this->db->query('ALTER TABLE ' . $upgrade[0] . ' ADD COLUMN ' . $upgrade[1] . ' ' . $upgrade[2])) {
                return array(false, $this->db->lasterror());
            }
        }
        return array(true, 'Catalogue, bundle, stock and sales analytics tables are ready.');
    }

    public function connectorEnabled($connector)
    {
        $key = 'DCH_' . strtoupper($connector) . '_ENABLED';
        if ($connector === 'woocommerce' && !isset($this->conf->global->$key)) return true;
        return !empty($this->conf->global->$key);
    }

    public function stockEnabled($connector)
    {
        $key = 'DCH_' . strtoupper($connector) . '_STOCK_ENABLED';
        return $this->connectorEnabled($connector) && !empty($this->conf->global->$key);
    }

    public function warehouseId($connector)
    {
        $key = 'DCH_' . strtoupper($connector) . '_WAREHOUSE_ID';
        return isset($this->conf->global->$key) ? (int) $this->conf->global->$key : 0;
    }

    public function getWarehouses()
    {
        $rows = array();
        $sql = 'SELECT rowid, ref, lieu, description FROM ' . MAIN_DB_PREFIX . 'entrepot'
            . ' WHERE entity IN (0,' . (int) $this->conf->entity . ') AND statut=1 ORDER BY ref';
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $rows[] = array(
                    'id' => (int) $obj->rowid,
                    'ref' => (string) $obj->ref,
                    'label' => trim((string) ($obj->lieu ?: $obj->description)),
                );
            }
        }
        return $rows;
    }

    public function getDolibarrProducts()
    {
        $rows = array();
        $sql = 'SELECT rowid, ref, label FROM ' . MAIN_DB_PREFIX . 'product'
            . ' WHERE entity IN (0,' . (int) $this->conf->entity . ') AND fk_product_type=0 ORDER BY ref, label';
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $rows[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'label' => (string) $obj->label);
            }
        }
        return $rows;
    }

    public function upsertCatalogProduct($connector, $externalProductId, $externalVariantId, $sku, $label)
    {
        $connector = $this->normalizeConnector($connector);
        $externalProductId = trim((string) $externalProductId);
        $externalVariantId = trim((string) $externalVariantId);
        $sku = trim((string) $sku);
        $label = trim((string) $label);
        if ($externalProductId === '' && $sku === '') return 0;
        if ($externalProductId === '') $externalProductId = 'sku:' . $sku;

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'dch_catalog_product'
            . ' WHERE entity=' . (int) $this->conf->entity
            . " AND connector='" . $this->db->escape($connector) . "'"
            . " AND external_product_id='" . $this->db->escape($externalProductId) . "'"
            . " AND external_variant_id='" . $this->db->escape($externalVariantId) . "' LIMIT 1";
        $resql = $this->db->query($sql);
        $obj = $resql ? $this->db->fetch_object($resql) : null;
        if ($obj) {
            $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dch_catalog_product SET'
                . " external_sku='" . $this->db->escape($sku) . "',"
                . " label='" . $this->db->escape($label) . "', active=1, date_seen=" . $this->sqlDateNow()
                . ' WHERE rowid=' . (int) $obj->rowid;
            return $this->db->query($sql) ? (int) $obj->rowid : 0;
        }

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'dch_catalog_product'
            . ' (entity, connector, external_product_id, external_variant_id, external_sku, label, stock_mode, active, date_seen) VALUES ('
            . (int) $this->conf->entity . ", '" . $this->db->escape($connector) . "', '"
            . $this->db->escape($externalProductId) . "', '" . $this->db->escape($externalVariantId) . "', '"
            . $this->db->escape($sku) . "', '" . $this->db->escape($label) . "', 'unmapped', 1, " . $this->sqlDateNow() . ')';
        return $this->db->query($sql) ? (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'dch_catalog_product') : 0;
    }

    public function getCatalog($connector = '')
    {
        $rows = array();
        $sql = 'SELECT c.* FROM ' . MAIN_DB_PREFIX . 'dch_catalog_product c WHERE c.entity=' . (int) $this->conf->entity;
        if ($connector !== '') $sql .= " AND c.connector='" . $this->db->escape($this->normalizeConnector($connector)) . "'";
        $sql .= ' ORDER BY c.connector, c.label, c.external_sku';
        $resql = $this->db->query($sql);
        if (!$resql) return $rows;
        while ($obj = $this->db->fetch_object($resql)) {
            $row = array(
                'id' => (int) $obj->rowid,
                'connector' => (string) $obj->connector,
                'external_product_id' => (string) $obj->external_product_id,
                'external_variant_id' => (string) $obj->external_variant_id,
                'sku' => (string) $obj->external_sku,
                'label' => (string) $obj->label,
                'stock_mode' => (string) $obj->stock_mode,
                'is_bundle' => !empty($obj->is_bundle) ? 1 : 0,
                'components' => array(),
            );
            $componentSql = 'SELECT bc.fk_product, bc.fk_warehouse, bc.quantity, p.ref, p.label, e.ref AS warehouse_ref FROM ' . MAIN_DB_PREFIX . 'dch_bundle_component bc'
                . ' LEFT JOIN ' . MAIN_DB_PREFIX . 'product p ON p.rowid=bc.fk_product'
                . ' LEFT JOIN ' . MAIN_DB_PREFIX . 'entrepot e ON e.rowid=bc.fk_warehouse'
                . ' WHERE bc.entity=' . (int) $this->conf->entity . ' AND bc.fk_catalog_product=' . (int) $obj->rowid
                . ' ORDER BY bc.rowid';
            $componentRes = $this->db->query($componentSql);
            if ($componentRes) {
                while ($component = $this->db->fetch_object($componentRes)) {
                    $row['components'][] = array(
                        'product_id' => (int) $component->fk_product,
                        'warehouse_id' => (int) $component->fk_warehouse,
                        'warehouse_ref' => (string) $component->warehouse_ref,
                        'quantity' => (float) $component->quantity,
                        'ref' => (string) $component->ref,
                        'label' => (string) $component->label,
                    );
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function saveRecipe($catalogId, $mode, array $components, $isBundle = false)
    {
        $catalogId = (int) $catalogId;
        $mode = in_array($mode, array('ignore', 'recipe'), true) ? $mode : 'unmapped';
        $catalog = $this->fetchCatalogById($catalogId);
        if (!$catalog) return array(false, 'External catalogue product was not found.');

        $clean = array();
        if ($mode === 'recipe') {
            foreach ($components as $component) {
                $productId = isset($component['product_id']) ? (int) $component['product_id'] : 0;
                $warehouseId = isset($component['warehouse_id']) ? (int) $component['warehouse_id'] : 0;
                $quantity = isset($component['quantity']) ? (float) price2num($component['quantity'], 'MU') : 0;
                if ($productId > 0 && $quantity > 0) {
                    if (!isset($clean[$productId])) $clean[$productId] = array('quantity' => 0.0, 'warehouse_id' => $warehouseId);
                    if ((int) $clean[$productId]['warehouse_id'] !== $warehouseId) return array(false, 'The same component cannot use two warehouses in one recipe. Add its quantities together or choose one warehouse.');
                    $clean[$productId]['quantity'] += $quantity;
                }
            }
            if (empty($clean)) return array(false, 'Choose at least one Dolibarr component and a quantity greater than zero.');
        }

        $this->db->begin();
        if (!$this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'dch_bundle_component WHERE entity=' . (int) $this->conf->entity . ' AND fk_catalog_product=' . $catalogId)) {
            $this->db->rollback();
            return array(false, $this->db->lasterror());
        }
        foreach ($clean as $productId => $component) {
            $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'dch_bundle_component (entity, fk_catalog_product, fk_product, fk_warehouse, quantity) VALUES ('
                . (int) $this->conf->entity . ', ' . $catalogId . ', ' . (int) $productId . ', ' . (int) $component['warehouse_id'] . ', ' . price2num($component['quantity'], 'MU') . ')';
            if (!$this->db->query($sql)) {
                $this->db->rollback();
                return array(false, $this->db->lasterror());
            }
        }
        if (!$this->db->query('UPDATE ' . MAIN_DB_PREFIX . "dch_catalog_product SET stock_mode='" . $this->db->escape($mode) . "', is_bundle=" . ($isBundle ? '1' : '0') . ' WHERE rowid=' . $catalogId . ' AND entity=' . (int) $this->conf->entity)) {
            $this->db->rollback();
            return array(false, $this->db->lasterror());
        }
        $componentFactor = 0.0;
        foreach ($clean as $component) $componentFactor += (float) $component['quantity'];
        if (!$this->db->query('UPDATE ' . MAIN_DB_PREFIX . 'dch_sales_line SET is_bundle=' . ($isBundle ? '1' : '0')
            . ', component_units=quantity*' . price2num($componentFactor, 'MU')
            . ' WHERE entity=' . (int) $this->conf->entity . ' AND fk_catalog_product=' . $catalogId)) {
            $this->db->rollback();
            return array(false, 'The recipe was not saved because its sales-report mapping could not be updated: ' . $this->db->lasterror());
        }
        $this->db->commit();
        return array(true, $mode === 'ignore' ? 'Product will not affect stock.' : 'Stock recipe saved.');
    }

    public function processOrder($connector, array $order, $user = null)
    {
        $connector = $this->normalizeConnector($connector);
        $result = array('applied' => 0, 'already' => 0, 'ignored' => 0, 'unmapped' => 0, 'errors' => 0, 'messages' => array());

        // Always learn products from sales data, even while stock handling is
        // disabled. This lets an administrator prepare mappings before the
        // first live stock deduction is enabled.
        if (!$this->recordOrderSales($connector, $order)) {
            $result['errors']++;
            $result['messages'][] = '#' . (string) ($order['number'] ?? $order['id'] ?? '') . ': sales analytics ledger could not be updated.';
        }
        if (!$this->stockEnabled($connector)) return $result;

        $defaultWarehouseId = $this->warehouseId($connector);
        if ($user === null && isset($GLOBALS['user'])) $user = $GLOBALS['user'];
        if (!$user || empty($user->id)) $user = $this->resolveStockUser();
        if (!$user || empty($user->id)) {
            $result['errors']++;
            $result['messages'][] = ucfirst($connector) . ': no Dolibarr user is available for stock movements.';
            return $result;
        }

        $orderId = (string) ($order['id'] ?? $order['order_id'] ?? '');
        $orderNumber = (string) ($order['number'] ?? $orderId);
        $dateOrder = (string) ($order['date_created'] ?? $order['date_paid'] ?? '');
        $dateTimestamp = $dateOrder !== '' ? strtotime($dateOrder) : 0;
        if ($dateTimestamp === false) $dateTimestamp = 0;
        $lines = isset($order['line_items']) && is_array($order['line_items']) ? $order['line_items'] : array();
        if (empty($lines)) {
            $result['unmapped']++;
            $result['messages'][] = '#' . $orderNumber . ': the channel returned no product line details, so stock could not be assigned.';
            return $result;
        }

        foreach ($lines as $lineIndex => $line) {
            if (!is_array($line)) continue;
            $lineId = (string) ($line['id'] ?? $line['order_item_id'] ?? $lineIndex);
            $productId = (string) ($line['product_id'] ?? $line['external_product_id'] ?? '');
            $variantId = (string) ($line['variation_id'] ?? $line['external_variant_id'] ?? '');
            $sku = (string) ($line['sku'] ?? '');
            $label = (string) ($line['name'] ?? $line['title'] ?? $sku);
            $soldQuantity = (float) price2num($line['quantity'] ?? 0, 'MU');
            if ($soldQuantity <= 0) continue;

            $catalogId = $this->upsertCatalogProduct($connector, $productId, $variantId, $sku, $label);
            $catalog = $catalogId > 0 ? $this->fetchCatalogById($catalogId) : null;
            if (!$catalog) {
                $result['errors']++;
                $result['messages'][] = '#' . $orderNumber . ' line ' . $label . ': catalogue record could not be saved.';
                continue;
            }
            if ($catalog->stock_mode === 'ignore') {
                $result['ignored']++;
                continue;
            }
            if ($catalog->stock_mode !== 'recipe') {
                $result['unmapped']++;
                $result['messages'][] = '#' . $orderNumber . ' line ' . $label . ': stock recipe is not mapped.';
                continue;
            }

            $components = $this->getRecipeComponents($catalogId);
            if (empty($components)) {
                $result['unmapped']++;
                $result['messages'][] = '#' . $orderNumber . ' line ' . $label . ': stock recipe has no components.';
                continue;
            }

            $desiredMovements = array();
            foreach ($components as $component) {
                $componentWarehouseId = !empty($component['warehouse_id']) ? (int) $component['warehouse_id'] : $defaultWarehouseId;
                $desiredMovements[(int) $component['product_id']] = array('quantity' => $soldQuantity * (float) $component['quantity'], 'warehouse_id' => $componentWarehouseId);
            }
            $existingEvents = $this->getLineMovementEvents($connector, $orderId, $lineId);
            $hasApplied = false;
            $recipeChanged = false;
            foreach ($existingEvents as $existingProductId => $existingEvent) {
                if ($existingEvent['status'] !== 'applied') continue;
                $hasApplied = true;
                if (!isset($desiredMovements[$existingProductId]) || abs((float) $existingEvent['quantity'] - (float) $desiredMovements[$existingProductId]['quantity']) > 0.0000001 || (int) $existingEvent['warehouse_id'] !== (int) $desiredMovements[$existingProductId]['warehouse_id']) {
                    $recipeChanged = true;
                    break;
                }
            }
            if ($hasApplied && !$recipeChanged) {
                foreach ($desiredMovements as $desiredProductId => $desiredQuantity) {
                    // A failed/pending row proves this component belonged to
                    // the original attempt. A completely new component after
                    // another component was applied is a recipe change and
                    // must not silently create an additional deduction.
                    if (!isset($existingEvents[$desiredProductId])) {
                        $recipeChanged = true;
                        break;
                    }
                }
            }
            if ($recipeChanged) {
                $result['errors']++;
                $result['messages'][] = '#' . $orderNumber . ' line ' . $label . ': recipe changed after stock was applied; no extra deduction was made. Adjust the existing stock movement manually.';
                continue;
            }
            foreach ($components as $component) {
                $quantity = $soldQuantity * (float) $component['quantity'];
                $warehouseId = !empty($component['warehouse_id']) ? (int) $component['warehouse_id'] : $defaultWarehouseId;
                if ($warehouseId <= 0) {
                    $result['errors']++;
                    $result['messages'][] = '#' . $orderNumber . ' line ' . $label . ': no warehouse is set for component product ' . (int) $component['product_id'] . '.';
                    continue;
                }
                $movement = $this->applySaleMovement(
                    $connector,
                    $orderId,
                    $orderNumber,
                    $lineId,
                    $catalogId,
                    (int) $component['product_id'],
                    $warehouseId,
                    $quantity,
                    $dateTimestamp,
                    $user
                );
                $result[$movement['status']]++;
                if ($movement['message'] !== '') $result['messages'][] = $movement['message'];
            }
        }
        return $result;
    }

    public function learnOrderProducts($connector, array $order)
    {
        $this->discoverOrderProducts($this->normalizeConnector($connector), $order);
    }

    public function recordOrderSales($connector, array $order, $sourceOrigin = 'connector')
    {
        $connector = $this->normalizeConnector($connector);
        $orderId = (string) ($order['id'] ?? $order['order_id'] ?? '');
        if ($orderId === '') return false;
        $success = true;
        $orderNumber = (string) ($order['number'] ?? $orderId);
        $dateValue = (string) ($order['date_paid'] ?? $order['date_created'] ?? '');
        $dateTimestamp = $dateValue !== '' ? strtotime($dateValue) : false;
        $dateSql = $dateTimestamp !== false ? "'" . $this->db->idate($dateTimestamp) . "'" : 'NULL';
        foreach ((array) ($order['line_items'] ?? array()) as $index => $line) {
            if (!is_array($line)) continue;
            $lineId = (string) ($line['id'] ?? $line['order_item_id'] ?? $index);
            $externalProductId = (string) ($line['product_id'] ?? $line['external_product_id'] ?? '');
            $externalVariantId = (string) ($line['variation_id'] ?? $line['external_variant_id'] ?? '');
            $sku = (string) ($line['sku'] ?? '');
            $label = (string) ($line['name'] ?? $line['title'] ?? $sku);
            $quantity = (float) price2num($line['quantity'] ?? 0, 'MU');
            if ($quantity <= 0) continue;
            $catalogId = $this->upsertCatalogProduct($connector, $externalProductId, $externalVariantId, $sku, $label);
            $catalog = $catalogId > 0 ? $this->fetchCatalogById($catalogId) : null;
            $isBundle = $catalog && !empty($catalog->is_bundle) ? 1 : 0;
            $componentFactor = 0.0;
            if ($catalog && $catalog->stock_mode === 'recipe') {
                foreach ($this->getRecipeComponents($catalogId) as $component) $componentFactor += (float) $component['quantity'];
            }
            $table = MAIN_DB_PREFIX . 'dch_sales_line';
            $values = array(
                'external_order_number' => $orderNumber,
                'fk_catalog_product' => $catalogId,
                'external_product_id' => $externalProductId,
                'external_variant_id' => $externalVariantId,
                'external_sku' => $sku,
                'product_label' => $label,
                'quantity' => $quantity,
                'is_bundle' => $isBundle,
                'component_units' => $quantity * $componentFactor,
                'source_origin' => substr((string) $sourceOrigin, 0, 32),
            );
            $where = 'entity=' . (int) $this->conf->entity . " AND connector='" . $this->db->escape($connector) . "' AND external_order_id='" . $this->db->escape($orderId) . "' AND external_line_id='" . $this->db->escape($lineId) . "'";
            $exists = $this->db->query('SELECT rowid FROM ' . $table . ' WHERE ' . $where . ' LIMIT 1');
            $existing = $exists ? $this->db->fetch_object($exists) : null;
            $sets = array();
            foreach ($values as $key => $value) {
                $sets[] = $key . '=' . (in_array($key, array('fk_catalog_product', 'quantity', 'is_bundle', 'component_units'), true) ? price2num($value, 'MU') : "'" . $this->db->escape($value) . "'");
            }
            $sets[] = 'date_order=' . $dateSql;
            if ($existing) {
                if (!$this->db->query('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE rowid=' . (int) $existing->rowid)) $success = false;
            } else {
                if (!$this->db->query('INSERT INTO ' . $table . ' SET entity=' . (int) $this->conf->entity . ", connector='" . $this->db->escape($connector) . "', external_order_id='" . $this->db->escape($orderId) . "', external_line_id='" . $this->db->escape($lineId) . "', " . implode(', ', $sets) . ', date_recorded=' . $this->sqlDateNow())) $success = false;
            }
        }
        return $success;
    }

    public function getStockMovementLog($limit = 500)
    {
        $rows = array();
        $sql = 'SELECT sm.*, p.ref, p.label AS product_label, e.ref AS warehouse_ref FROM ' . MAIN_DB_PREFIX . 'dch_stock_movement sm'
            . ' LEFT JOIN ' . MAIN_DB_PREFIX . 'product p ON p.rowid=sm.fk_product'
            . ' LEFT JOIN ' . MAIN_DB_PREFIX . 'entrepot e ON e.rowid=sm.fk_warehouse'
            . ' WHERE sm.entity=' . (int) $this->conf->entity . ' ORDER BY sm.rowid DESC LIMIT ' . max(1, min(5000, (int) $limit));
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) $rows[] = $obj;
        }
        return $rows;
    }

    private function applySaleMovement($connector, $orderId, $orderNumber, $lineId, $catalogId, $productId, $warehouseId, $quantity, $dateOrder, $user)
    {
        $table = MAIN_DB_PREFIX . 'dch_stock_movement';
        $inventoryCode = substr('DCH-' . strtoupper($connector) . '-' . $orderId . '-' . $lineId, 0, 128);
        $where = 'entity=' . (int) $this->conf->entity
            . " AND connector='" . $this->db->escape($connector) . "'"
            . " AND external_order_id='" . $this->db->escape($orderId) . "'"
            . " AND external_line_id='" . $this->db->escape($lineId) . "'"
            . " AND event_key='sale' AND fk_product=" . (int) $productId;
        $resql = $this->db->query('SELECT rowid, status FROM ' . $table . ' WHERE ' . $where . ' LIMIT 1');
        $existing = $resql ? $this->db->fetch_object($resql) : null;
        if ($existing && $existing->status === 'applied') return array('status' => 'already', 'message' => '');
        if ($existing && $existing->status === 'pending') {
            $movementRes = $this->db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . 'stock_mouvement'
                . " WHERE inventorycode='" . $this->db->escape($inventoryCode) . "' AND fk_product=" . (int) $productId . ' LIMIT 1');
            $movementRow = $movementRes ? $this->db->fetch_object($movementRes) : null;
            if ($movementRow) {
                $this->db->query("UPDATE $table SET status='applied', fk_stock_movement=" . (int) $movementRow->rowid . ", error_message=NULL WHERE rowid=" . (int) $existing->rowid);
                return array('status' => 'already', 'message' => '');
            }
            return array('status' => 'errors', 'message' => '#' . $orderNumber . ': a stock movement is already pending for product ' . $productId . '.');
        }

        if ($existing) {
            $rowId = (int) $existing->rowid;
            $this->db->query("UPDATE $table SET status='pending', fk_warehouse=" . (int) $warehouseId . ', quantity=' . price2num($quantity, 'MU')
                . ", external_order_number='" . $this->db->escape($orderNumber) . "', destination='Sold via " . $this->db->escape($connector === 'woocommerce' ? 'WooCommerce' : ucfirst($connector)) . "', error_message=NULL WHERE rowid=" . $rowId);
        } else {
            $destination = 'Sold via ' . ($connector === 'woocommerce' ? 'WooCommerce' : ucfirst($connector));
            $sql = 'INSERT INTO ' . $table
                . ' (entity, connector, external_order_id, external_order_number, external_line_id, event_key, fk_catalog_product, fk_product, fk_warehouse, destination, quantity, status, date_order, date_created) VALUES ('
                . (int) $this->conf->entity . ", '" . $this->db->escape($connector) . "', '" . $this->db->escape($orderId) . "', '"
                . $this->db->escape($orderNumber) . "', '" . $this->db->escape($lineId) . "', 'sale', " . (int) $catalogId . ', ' . (int) $productId . ', ' . (int) $warehouseId . ", '" . $this->db->escape($destination) . "', "
                . price2num($quantity, 'MU') . ", 'pending', " . ($dateOrder > 0 ? "'" . $this->db->idate($dateOrder) . "'" : 'NULL') . ', ' . $this->sqlDateNow() . ')';
            if (!$this->db->query($sql)) {
                // A concurrent request may have completed the same unique event.
                $check = $this->db->query('SELECT status FROM ' . $table . ' WHERE ' . $where . ' LIMIT 1');
                $duplicate = $check ? $this->db->fetch_object($check) : null;
                if ($duplicate && $duplicate->status === 'applied') return array('status' => 'already', 'message' => '');
                return array('status' => 'errors', 'message' => '#' . $orderNumber . ': could not reserve stock movement: ' . $this->db->lasterror());
            }
            $rowId = (int) $this->db->last_insert_id($table);
        }

        require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
        $movement = new MouvementStock($this->db);
        $destination = 'Sold via ' . ($connector === 'woocommerce' ? 'WooCommerce' : ucfirst($connector));
        $label = 'Dolli Commerce Hub | ' . $destination . ' | order #' . $orderNumber;
        $movementId = $movement->livraison($user, $productId, $warehouseId, $quantity, 0, substr($label, 0, 255), $dateOrder ?: '', '', '', '', 0, $inventoryCode);
        if ($movementId > 0) {
            $this->db->query("UPDATE $table SET status='applied', fk_stock_movement=" . (int) $movementId . ", external_order_number='" . $this->db->escape($orderNumber) . "', destination='" . $this->db->escape($destination) . "', error_message=NULL WHERE rowid=" . (int) $rowId);
            return array('status' => 'applied', 'message' => '');
        }

        $error = !empty($movement->error) ? $movement->error : (!empty($movement->errors) ? implode('; ', $movement->errors) : 'Dolibarr rejected the stock movement.');
        $this->db->query("UPDATE $table SET status='failed', error_message='" . $this->db->escape($error) . "' WHERE rowid=" . (int) $rowId);
        return array('status' => 'errors', 'message' => '#' . $orderNumber . ': stock deduction failed for product ' . $productId . ': ' . $error);
    }

    private function fetchCatalogById($catalogId)
    {
        $resql = $this->db->query('SELECT * FROM ' . MAIN_DB_PREFIX . 'dch_catalog_product WHERE rowid=' . (int) $catalogId . ' AND entity=' . (int) $this->conf->entity . ' LIMIT 1');
        return $resql ? $this->db->fetch_object($resql) : null;
    }

    private function discoverOrderProducts($connector, array $order)
    {
        $lines = isset($order['line_items']) && is_array($order['line_items']) ? $order['line_items'] : array();
        foreach ($lines as $line) {
            if (!is_array($line)) continue;
            $this->upsertCatalogProduct(
                $connector,
                (string) ($line['product_id'] ?? $line['external_product_id'] ?? ''),
                (string) ($line['variation_id'] ?? $line['external_variant_id'] ?? ''),
                (string) ($line['sku'] ?? ''),
                (string) ($line['name'] ?? $line['title'] ?? $line['sku'] ?? '')
            );
        }
    }

    private function getRecipeComponents($catalogId)
    {
        $rows = array();
        $resql = $this->db->query('SELECT fk_product, fk_warehouse, quantity FROM ' . MAIN_DB_PREFIX . 'dch_bundle_component WHERE entity=' . (int) $this->conf->entity . ' AND fk_catalog_product=' . (int) $catalogId);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) $rows[] = array('product_id' => (int) $obj->fk_product, 'warehouse_id' => (int) $obj->fk_warehouse, 'quantity' => (float) $obj->quantity);
        }
        return $rows;
    }

    private function getLineMovementEvents($connector, $orderId, $lineId)
    {
        $rows = array();
        $sql = 'SELECT fk_product, fk_warehouse, quantity, status FROM ' . MAIN_DB_PREFIX . 'dch_stock_movement'
            . ' WHERE entity=' . (int) $this->conf->entity
            . " AND connector='" . $this->db->escape($connector) . "'"
            . " AND external_order_id='" . $this->db->escape($orderId) . "'"
            . " AND external_line_id='" . $this->db->escape($lineId) . "' AND event_key='sale'";
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $rows[(int) $obj->fk_product] = array('warehouse_id' => (int) $obj->fk_warehouse, 'quantity' => (float) $obj->quantity, 'status' => (string) $obj->status);
            }
        }
        return $rows;
    }

    private function normalizeConnector($connector)
    {
        $connector = strtolower(trim((string) $connector));
        return in_array($connector, array('woocommerce', 'amazon', 'sumup'), true) ? $connector : 'woocommerce';
    }

    private function resolveStockUser()
    {
        $userId = !empty($this->conf->global->DCH_STOCK_USER_ID) ? (int) $this->conf->global->DCH_STOCK_USER_ID : 0;
        if ($userId <= 0) {
            $resql = $this->db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . 'user'
                . ' WHERE statut=1 AND admin=1 AND entity IN (0,' . (int) $this->conf->entity . ') ORDER BY rowid LIMIT 1');
            $obj = $resql ? $this->db->fetch_object($resql) : null;
            if ($obj) $userId = (int) $obj->rowid;
        }
        if ($userId <= 0) return null;
        require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
        $stockUser = new User($this->db);
        return $stockUser->fetch($userId) > 0 ? $stockUser : null;
    }

    private function sqlDateNow()
    {
        return "'" . $this->db->idate(dol_now()) . "'";
    }
}
