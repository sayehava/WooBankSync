# 🏦 WooBankSync

> **Dolibarr v23 module** — syncs WooCommerce payment movements into Dolibarr bank and virtual clearing accounts.

> [!IMPORTANT]
> WooCommerce remains the **invoice master**. WooBankSync only creates bank/cashflow entries in Dolibarr — it never creates Dolibarr customer invoices, modifies WooCommerce invoices, or sends customer emails.

---

## ⚙️ How it works

For each eligible WooCommerce order, WooBankSync creates a **single bank entry** in Dolibarr:

```
💰 Bank entry amount  =  net payout  (gross order total  −  payment processor fee)
```

The gross amount and fee are stored separately in bank extra fields so reports can show all three values.

---

## ✅ Order eligibility

| Condition | Action |
|---|---|
| Status `processing` or `completed` | ✅ Imported |
| Zero-total order | ⏭️ Silently skipped |
| No payment method set | ⏭️ Silently skipped |
| Cancelled or refunded | ❌ Not imported |
| Already in sync log | ⏭️ Skipped — no duplicates |

---

## 💳 Payment processor fee detection

The fee is read from a configurable **Fee meta key** per payment gateway. If no key is configured, the module auto-detects the fee by scanning order meta keys that contain the word `fee`.

> [!TIP]
> Fee values can be plain floats **or** PHP-serialized structures (e.g. PayPal's `_ppcp_paypal_fees`). The module unserializes these automatically and extracts the correct sub-field.

---

## 💸 Net payout detection

The net payout can optionally be read from a **Payout meta key** per payment gateway — useful when the payment provider stores the exact settled amount in WooCommerce meta.

If the payout meta key is blank, or if the read value fails a sanity check (payout exceeds gross, or suspiciously equals the fee), the module falls back to:

```
payout = gross − fee
```

The raw WooCommerce payout value is stored alongside the calculated value so any mismatches are visible in the log viewer.

---

## 🏷️ Bank entry label format

```
WOO - #ORDER_NUMBER Buyer Name - InvoiceNumber
```

**Example:**

```
WOO - #30955 Max Mustermann - RE-2026-00123
```

The invoice reference is also written to the native `Number / Check/Transfer N°` bank field. No custom bank fields are used for this.

---

## 🏛️ Virtual bank accounts

Each payment gateway is mapped to a Dolibarr bank or virtual clearing account in the module settings:

| Gateway | Dolibarr account |
|---|---|
| 🟦 Stripe | Stripe clearing (virtual) |
| 🟦 PayPal | PayPal clearing (virtual) |
| 🟩 Direct bank transfer | Main bank account |
| 🟨 Cash on delivery | Configured manually |

> [!NOTE]
> Transfers from clearing accounts to the main bank account are **separate reconciliation steps** and are not generated automatically by order sync.

---

## 🔌 Gateway configuration

### 🔍 Payment method discovery

The gateway mapping UI shows only payment methods that are **currently active** in WooCommerce **or** have been used in at least one real order. Installed-but-unused gateways are never shown.

Gateway **aliases** are supported — old PayPal orders stored as `paypal` are matched to the same bank account as the current `ppcp-gateway` mapping.

### ⚙️ Per-gateway settings

| Setting | Description |
|---|---|
| 🏦 Dolibarr bank account | Which account receives this gateway's movements |
| 🔑 Fee meta key | WooCommerce order meta key where the provider fee is stored |
| 🔑 Payout meta key | WooCommerce order meta key where the net payout is stored *(optional)* |

---

## 🗺️ Gateway-specific behavior

| Gateway | Fee meta key | Payout meta key | How it works |
|---|---|---|---|
| 🔵 **PayPal (ppcp-gateway)** | `_ppcp_paypal_fees` | `_ppcp_paypal_fees` | PHP-serialized data is unserialized; `paypal_fee.value` → fee, `net_amount.value` → payout |
| 🔵 **Old PayPal** | `PayPal Transaction Fee` | *(blank)* | Plain float for fee; payout not available — falls back to calculated |
| 🟣 **Stripe** | *(auto detect)* | `_stripe_net` | Plain float read directly for payout |
| ⚪ **Unknown gateway** | *(auto detect)* | *(blank)* | Fee auto-detected from meta; payout calculated as gross − fee |

---

## 📋 Sync log viewer

The sync log is available in the module setup page. Each row shows date, order number, invoice number, payment method, gross, fee, net payout, a colour-coded status badge, a PDF indicator, and any error or mismatch message.

### 🎨 Status badges

| Badge | Colour | Meaning |
|---|---|---|
| ✅ **Matched** | 🟢 Green | WC payout was read from meta and agrees with the calculated value (within 0.005) |
| 🔵 **Calculated** | 🔵 Blue | No payout meta key configured; net was calculated as gross − fee |
| ⚠️ **Unmatched** | 🟠 Amber | WC payout was read but differs from the calculated value; both values appear in the message column |
| ❌ **Error** | 🔴 Red | Sync failed for this order; error detail in the message column |
| ⏭️ **Skipped** | ⚫ Gray | Order was skipped (zero total, no payment method, or wrong status) |
| 🔘 **Dry Run** | ⚫ Gray | Test run — no bank entries were created |

> [!TIP]
> The search box in the log viewer filters on order number, invoice number, payment method, and the text labels **matched** / **calculated** / **unmatched**.

---

## 📊 Amount custom fields

WooBankSync can write the **gross amount** and **fee** into Dolibarr bank extra fields so they appear in account exports and reports alongside the net bank entry.

Configure the field codes in the setup page under **Amount custom fields**. The **Create and map missing fields** button creates any missing extra fields and maps them automatically without overwriting manually configured mappings.

---

## 🗑️ Desync

> [!WARNING]
> The Desync action is **destructive** and is protected by a confirmation prompt.

Desync removes all bank entries and log rows created by WooBankSync. It uses stored bank line IDs first and falls back to label patterns (`WOO - #...`) for older log entries.

Manually created Dolibarr entries and unrelated bank records are **never touched**.

---

## 🔄 Update process

> [!NOTE]
> No module disable/enable is needed for normal updates.

1. 📁 Replace files in `htdocs/custom/woobanksync`
2. 🌐 Open the module setup page
3. ▶️ Click **Run/update database checks**
4. ✅ Verify settings are preserved
5. 🚀 Test with **Sync now**

---

## 📁 Main files

| File | Purpose |
|---|---|
| `core/modules/modWooBankSync.class.php` | Module descriptor |
| `admin/setup.php` | ⚙️ Settings and log viewer UI |
| `index.php` | 🚀 Manual sync UI |
| `class/woobanksync.class.php` | 🧠 All business logic |
| `class/woocommerceclient.class.php` | 🔌 WooCommerce REST API client |
| `scripts/sync.php` | 🖥️ CLI/cron entry point |
| `sql/llx_woobanksync_log.sql` | 🗄️ Sync log table schema |
| `lang/en_US/woobanksync.lang` | 🌍 Language strings |

---

## 📜 License

This project is licensed under the **GNU Affero General Public License v3.0** (AGPL-3.0). See the [LICENSE](LICENSE) file for the full text.

---

### ❤️ Support Development

☕ **Buy Me a Coffee**  
https://buymeacoffee.com/sayehava

💜 **Ko-fi**  
https://ko-fi.com/sayehava

> [!TIP]
> Even a small donation helps fund future modules, maintenance, bug fixes, and new features.

---
