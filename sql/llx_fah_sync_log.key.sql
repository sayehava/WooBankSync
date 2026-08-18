ALTER TABLE llx_fah_sync_log ADD COLUMN IF NOT EXISTS payout_amount double(24,8) DEFAULT 0;
ALTER TABLE llx_fah_sync_log ADD COLUMN IF NOT EXISTS woo_invoice_number varchar(255) DEFAULT NULL;
ALTER TABLE llx_fah_sync_log ADD COLUMN IF NOT EXISTS connector varchar(32) NOT NULL DEFAULT 'woocommerce';
ALTER TABLE llx_fah_sync_log ADD COLUMN IF NOT EXISTS fee_source varchar(128) DEFAULT NULL;
