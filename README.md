# WooBankSync

A custom Dolibarr v23 module that syncs WooCommerce payment movements into Dolibarr bank and virtual bank accounts.

WooCommerce remains the invoice master. WooBankSync only touches bank/cashflow entries in Dolibarr — it never creates Dolibarr customer invoices, modifies WooCommerce invoices, or sends customer emails.

---

## How it works

For each eligible WooCommerce order, WooBankSync creates a single bank entry in Dolibarr:

```
Bank entry amount = net payout (gross order total minus payment processor fee)
```

The gross amount and fee are stored separately in bank extra fields so reports can show all three values.

### Order eligibility

Only orders with status `processing` or `completed` are imported. The following are silently skipped:

- Zero-total orders
- Orders with no payment method set
- Cancelled or refunded orders
- Orders already present in the sync log (no duplicates)

### Payment processor fee detection

The fee is read from a configurable **Fee meta key** per payment gateway. If no key is configured, the module auto-detects the fee by scanning order meta keys that contain the word `fee`.

Fee values may be plain floats or PHP-serialized structures (e.g. PayPal's `_ppcp_paypal_fees`). The module unserializes these automatically and extracts the correct sub-field.

### Net payout detection

The net payout can optionally be read from a **Payout meta key** per payment gateway. This is useful when the payment provider stores the exact settled amount in WooCommerce meta (e.g. Stripe's `_stripe_net`, or the `net_amount` field inside PayPal's serialized `_ppcp_paypal_fees`).

If the payout meta key is blank, or if the read value fails a sanity check (payout exceeds gross, or suspiciously equals the fee), the module falls back to:

```
payout = gross - fee
```

The raw WooCommerce payout value is stored alongside the calculated value so mismatches are visible in the log viewer.

### Bank entry label format

```
WOO - #ORDER_NUMBER Buyer Name - InvoiceNumber
```

Example:

```
WOO - #30955 Max Mustermann - RE-2026-00123
```

The invoice reference is also written to the native `Number / Check/Transfer N°` bank field. No custom bank fields are used for this.

### Virtual bank accounts

Each payment gateway is mapped to a Dolibarr bank or virtual clearing account in the module settings. Typical setup:

| Gateway | Dolibarr account |
|---|---|
| Stripe | Stripe clearing (virtual) |
| PayPal | PayPal clearing (virtual) |
| Cash on delivery | Configured manually |
| Direct bank transfer | Main bank account |

Transfers from clearing accounts to the main bank account are separate reconciliation steps and are not generated automatically.

---

## Gateway configuration

### Payment method discovery

The gateway mapping UI shows only payment methods that are currently active in WooCommerce **or** have been used in at least one real order. Installed-but-unused gateways are not shown.

Gateway aliases are supported. For example, old PayPal orders stored as `paypal` are matched to the same bank account as the current `ppcp-gateway` mapping.

### Per-gateway settings

| Setting | Description |
|---|---|
| Dolibarr bank account | Which account receives this gateway's movements |
| Fee meta key | WooCommerce order meta key where the provider fee is stored |
| Payout meta key | WooCommerce order meta key where the net payout is stored (optional) |

---

## Gateway-specific behavior

| Gateway | Fee meta key | Payout meta key | How it works |
|---|---|---|---|
| **PayPal (ppcp-gateway)** | `_ppcp_paypal_fees` | `_ppcp_paypal_fees` | PHP-serialized data is unserialized; `paypal_fee.value` used for fee, `net_amount.value` for payout |
| **Old PayPal** | `PayPal Transaction Fee` | *(blank)* | Plain float for fee; payout not available — falls back to calculated |
| **Stripe** | *(auto detect)* | `_stripe_net` | Plain float read directly for payout |
| **Unknown gateway** | *(auto detect)* | *(blank)* | Fee auto-detected from meta; payout calculated as gross minus fee |

---

## Sync log viewer

The sync log is available in the module setup page. Each row shows date, order number, invoice number, payment method, gross, fee, net payout, a colour-coded status badge, a PDF indicator, and any error or mismatch message.

### Status badges

| Badge | Colour | Meaning |
|---|---|---|
| ✓ Matched | Green | WC payout was read from meta and agrees with the calculated value (within 0.005) |
| ~ Calculated | Blue | No payout meta key configured; net was calculated as gross minus fee |
| ⚠ Unmatched | Amber | WC payout was read but differs from the calculated value; both values appear in the message column |
| ✗ Error | Red | Sync failed for this order; error detail in the message column |
| – Skipped | Gray | Order was skipped (zero total, no payment method, or wrong status) |
| ◎ Dry Run | Gray | Test run; no bank entries were created |

The search box in the log viewer filters on order number, invoice number, payment method, status, and the text labels matched / calculated / unmatched.

---

## Amount custom fields

WooBankSync can write the gross amount and fee into Dolibarr bank extra fields so they appear in account exports and reports alongside the net bank entry amount. Configure the field codes in the setup page under **Amount custom fields**. The **Create and map missing fields** button creates any missing extra fields and maps them automatically without overwriting manually configured mappings.

---

## Desync

The Desync action (protected by confirmation) removes all bank entries and log rows created by WooBankSync. It uses stored bank line IDs first and falls back to label patterns (`WOO - #...`, `Payment fee for WOO - #...`) for older log entries. Manually created Dolibarr entries and unrelated bank records are never touched.

---

## Update process

No module disable/enable is needed for normal updates.

1. Replace files in `htdocs/custom/woobanksync`
2. Open the module setup page
3. Click **Run/update database checks**
4. Verify settings are preserved
5. Test with **Sync now**

---

## Main files

| File | Purpose |
|---|---|
| `core/modules/modWooBankSync.class.php` | Module descriptor |
| `admin/setup.php` | Settings and log viewer UI |
| `index.php` | Manual sync UI |
| `class/woobanksync.class.php` | All business logic |
| `class/woocommerceclient.class.php` | WooCommerce REST API client |
| `scripts/sync.php` | CLI/cron entry point |
| `sql/llx_woobanksync_log.sql` | Sync log table schema |
| `lang/en_US/woobanksync.lang` | Language strings |
