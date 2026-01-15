ALTER TABLE llx_woobanksync_log ADD COLUMN IF NOT EXISTS payout_amount double(24,8) DEFAULT 0;
ALTER TABLE llx_woobanksync_log ADD COLUMN IF NOT EXISTS woo_invoice_number varchar(255) DEFAULT NULL;
