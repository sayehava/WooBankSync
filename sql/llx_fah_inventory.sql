CREATE TABLE IF NOT EXISTS llx_fah_catalog_product (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  connector varchar(32) NOT NULL,
  external_product_id varchar(128) NOT NULL DEFAULT '',
  external_variant_id varchar(128) NOT NULL DEFAULT '',
  external_sku varchar(255) DEFAULT NULL,
  label varchar(255) DEFAULT NULL,
  stock_mode varchar(16) NOT NULL DEFAULT 'unmapped',
  is_bundle integer NOT NULL DEFAULT 0,
  active integer NOT NULL DEFAULT 1,
  date_seen datetime DEFAULT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_fah_catalog_external (entity, connector, external_product_id, external_variant_id),
  KEY idx_fah_catalog_sku (entity, connector, external_sku)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_fah_bundle_component (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  fk_catalog_product integer NOT NULL,
  fk_product integer NOT NULL,
  fk_warehouse integer NOT NULL DEFAULT 0,
  quantity double(24,8) NOT NULL DEFAULT 1,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_fah_component_product (entity, fk_catalog_product, fk_product),
  KEY idx_fah_component_catalog (fk_catalog_product)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_fah_stock_movement (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  connector varchar(32) NOT NULL,
  external_order_id varchar(128) NOT NULL,
  external_order_number varchar(128) DEFAULT NULL,
  external_line_id varchar(128) NOT NULL,
  event_key varchar(32) NOT NULL DEFAULT 'sale',
  fk_catalog_product integer DEFAULT NULL,
  fk_product integer NOT NULL,
  fk_warehouse integer NOT NULL,
  destination varchar(255) DEFAULT NULL,
  quantity double(24,8) NOT NULL DEFAULT 0,
  fk_stock_movement integer DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  error_message text,
  date_order datetime DEFAULT NULL,
  date_created datetime DEFAULT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_fah_stock_event (entity, connector, external_order_id, external_line_id, event_key, fk_product),
  KEY idx_fah_stock_status (entity, connector, status)
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_fah_sales_line (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer NOT NULL DEFAULT 1,
  connector varchar(32) NOT NULL,
  external_order_id varchar(128) NOT NULL,
  external_order_number varchar(128) DEFAULT NULL,
  external_line_id varchar(128) NOT NULL,
  fk_catalog_product integer DEFAULT NULL,
  external_product_id varchar(128) DEFAULT NULL,
  external_variant_id varchar(128) DEFAULT NULL,
  external_sku varchar(255) DEFAULT NULL,
  product_label varchar(255) DEFAULT NULL,
  quantity double(24,8) NOT NULL DEFAULT 0,
  is_bundle integer NOT NULL DEFAULT 0,
  component_units double(24,8) NOT NULL DEFAULT 0,
  source_origin varchar(32) NOT NULL DEFAULT 'connector',
  date_order datetime DEFAULT NULL,
  date_recorded datetime DEFAULT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_fah_sales_line (entity, connector, external_order_id, external_line_id),
  KEY idx_fah_sales_date (entity, date_order),
  KEY idx_fah_sales_connector_date (entity, connector, date_order)
) ENGINE=innodb;
