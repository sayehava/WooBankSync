# Finance Automation Hub

> **Dolibarr finance and commerce automation bridge** — synchronizes sales, expenses, money movements, provider fees, documents, product mappings, bundles, and stock across WooCommerce, Amazon Seller, Stripe, PayPal, SumUp, and future connectors.

> [!IMPORTANT]
> Each channel remains the source of its orders. Finance Automation Hub creates Dolibarr bank and stock movements; it does not create customer invoices, change remote orders, or send customer emails.

## Channel modules

WooCommerce, Amazon Seller, and SumUp are independent submodules. The compact connector manager shows one row per channel and opens only the selected connector's settings, so adding more channels does not create an ever-growing wall of settings cards. Each channel can be enabled or disabled independently.

Finance Automation Hub has its own top-level Dolibarr menu with separate **Dashboard**, **Sales analytics**, and **Configuration** pages. WooCommerce-only tools—including Germanized, amount custom fields, invoice cache, order-meta diagnostics, and gateway discovery—appear only while configuring WooCommerce.

- WooCommerce uses the WooCommerce REST API and keeps the existing gateway, Germanized invoice, PDF, and difference-check workflows.
- Amazon uses Login with Amazon plus SP-API Orders `2026-01-01`, Listings Items `2021-08-01`, and Finances `2024-06-19`. Buyer/recipient PII is not requested. Finalized finance transactions supply Amazon expenses and net proceeds; finance-pending orders wait rather than creating an estimated bank entry.
- SumUp uses transaction history and full transaction details so product quantities and exact `fee_amount` costs can be read when SumUp recorded product lines. If a separate Dolibarr POS/SumUp integration already owns those sales, duplicate protection can skip all SumUp writes or only transactions with configured POS reference prefixes while still counting their product lines and fees in analytics.

Amazon and SumUp each have their own optional Dolibarr virtual/clearing bank mapping. The setup page can create or reuse these accounts automatically. As with WooCommerce gateways, the account receives the net payout while gross sales, provider fees, net payout, and the fee source remain visible in the sync log and analytics.

## Product mapping and bundles

Recommended first-time/upgrade sequence:

1. Open module setup and run the database check once.
2. Save credentials and activate only the required channel modules.
3. Refresh each active product catalogue.
4. Map normal products and bundle components, then choose the source warehouse for every recipe component. The connector warehouse is only a fallback.
5. Enable stock deduction and run sync again. Previously synced orders are safe to revisit because stock events are idempotent.

The setup page contains a shared channel catalogue. Every external product or variation has one of three stock modes:

| Mode | Effect |
|---|---|
| Not mapped | Sync continues, but the order reports that stock is waiting for a recipe |
| Single / bundle recipe | Deducts one or more Dolibarr products using the configured quantities |
| Ignore | The channel item intentionally does not affect stock |

A normal item is a recipe with one Dolibarr component at quantity `1`. A double pack uses quantity `2`, a 4-pack uses `4`, and a mixed bundle may contain any number of different components. Warehouse routing belongs to each external product recipe, so the same Dolibarr product can be deducted from the online-shop warehouse for WooCommerce, the Amazon warehouse for Amazon, and the retail warehouse for SumUp.

Stock deductions are idempotent per channel, order, order line, and Dolibarr component. Re-running a sync can apply a recipe that was mapped later, but it does not deduct an already-applied sale twice. Each successful deduction is a native Dolibarr stock movement and also appears in the module audit with channel, order, source warehouse, destination and native movement ID.

Catalogue discovery is also idempotent. The database check consolidates duplicate external-product rows created by an older or incomplete schema, preserves the strongest saved recipe, reconnects sales and movement records, and restores the unique channel/product/variation key. Later syncs use an atomic upsert and cannot append the same recipe again.

Before enabling stock deductions, select a warehouse for each recipe component (or set a connector fallback) and map every sellable channel item. If a SumUp transaction contains no product lines, the financial movement can still sync, but the UI reports that stock could not be assigned.

This release records sale deductions only. A later refund, cancellation, return, or recipe/warehouse change does not automatically reverse a stock movement that was already applied; those adjustments remain explicit Dolibarr stock operations.

## Sales analytics and Excel export

The **Sales analytics** section reports orders and product quantities by WooCommerce, Amazon and SumUp. It separates single items from bundles/multipacks, shows total sold channel items, expands recipes into underlying inventory pieces, and reports gross sales, provider costs, and net payouts by currency. Reports can be filtered by sales channel, source warehouse, channel + warehouse together, month, year, custom date range, or all dates and exported as a UTF-8, Excel-compatible CSV.

The **Warehouse × sales channel** table uses the warehouse stored on the applied stock movement, not the product's current recipe. This preserves historical routing when mappings later change and distinguishes WooCommerce from SumUp even when both use the same warehouse.

Sales lines are stored independently from bank and stock writes. This means ignored/unmapped items and SumUp transactions owned by a separate Dolibarr POS integration still appear in sales counts without causing duplicate entries. After installing this upgrade, re-run each connector sync for the historical period you want to report; idempotency prevents repeat stock movements and the resync backfills the new analytics ledger.

Amazon-generated invoices and SumUp receipts remain on their respective platforms. The connector does not attempt PDF download when the API role or endpoint does not provide those documents.

---

## ⚙️ How it works

For each eligible WooCommerce order, Finance Automation Hub creates a **single bank entry** in Dolibarr:

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

The invoice reference is also written to the native `Number / Check/Transfer N°` bank field. It can optionally be mapped to a separate text custom field. The invoice field label is configurable.

Downloaded WooCommerce invoice PDFs are saved in the selected Dolibarr Documents folder and indexed in ECM so they appear in the Documents module.

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

The sync log is available on the dashboard and in the module setup page. Each row shows channel, date, order number, invoice number, payment method, gross, fee, fee source, net payout, status, PDF indicator, and any error or mismatch message.

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

## 📊 WooCommerce amount custom fields

Finance Automation Hub can write the WooCommerce **gross amount** and **fee** into Dolibarr bank extra fields so they appear in account exports and reports alongside the net bank entry. Amazon and SumUp costs remain in their connector-neutral sync log and sales analytics instead of using these WooCommerce fields.

Configure the fields inside **WooCommerce configuration → WooCommerce amount custom fields**. Gross and fee accept only numeric bank-entry custom fields, must use different fields, and cannot use the invoice-number field. You can create fields manually in Dolibarr and map them, or use the explicit **Create and map missing amount fields automatically** button.

The mapped gross, fee, and invoice display labels can be renamed from the setup page. After correcting an older mapping, use **Repair existing bank entries** to rewrite the values on previously synced WooCommerce movements.

---

## Bank/Cash and test-data maintenance

Configuration shows the number of module-created bank accounts, imported bank entries, indexed documents, and sync-log rows. It also shows Dolibarr's highest and next global bank-entry references. An administrator may set the next reference to any collision-free value at or above the highest remaining `llx_bank.rowid` plus one. This sequence is global to every Bank/Cash entry, not private to this module.

The legacy-menu cleanup removes stale WooBankSync, Commerce Automation Hub, Dolli Commerce Hub, and Dolibarr Commerce Hub menu rows. It runs during activation and database checks and is also available as an explicit button.

## 🗑️ Desync

> [!WARNING]
> The Desync action is **destructive** and is protected by a confirmation prompt.

Desync removes all bank entries, downloaded/indexed WooCommerce PDFs, cache rows, and log rows created by Finance Automation Hub. It uses stored bank line IDs first and falls back to label patterns (`WOO - #...`) for older log entries. An optional checkbox also deletes empty virtual bank accounts created by the module and clears their mappings. Non-empty and manually mapped accounts are retained.

Manually created Dolibarr entries and unrelated bank records are **never touched**.

---

## 🔄 Installation

> [!NOTE]
> This is a clean pre-production module identity. Install it as a new module; no compatibility layer or data migration from an earlier identity is included.

1. 📁 Install the module in `htdocs/custom/financeautomationhub`
2. ▶️ Activate Finance Automation Hub and run **Run/update database checks**
3. ⚙️ Configure connector credentials, payment mappings, product recipes, and warehouses
4. 🚀 Test with **Sync now**

If an earlier module title remains in the menu after upgrading, open Configuration and click **Remove stale Dolli Commerce Hub menus**, then reload the page.

---

## 📁 Main files

| File | Purpose |
|---|---|
| `core/modules/modFinanceAutomationHub.class.php` | Dolibarr module descriptor |
| `admin/setup.php` | ⚙️ Settings and log viewer UI |
| `index.php` | 🚀 Manual sync UI |
| `class/financeautomationhub.class.php` | 🧠 All business logic |
| `class/fahinventory.class.php` | Product recipes, warehouse routing and stock movements |
| `class/fahsalesreport.class.php` | Cross-platform sales analytics queries |
| `reports.php` | Filterable sales report and Excel-compatible export |
| `class/fahwoocommerceclient.class.php` | 🔌 WooCommerce REST API client |
| `scripts/sync.php` | 🖥️ CLI/cron entry point |
| `sql/llx_fah_sync_log.sql` | 🗄️ Sync log table schema |
| `lang/en_US/financeautomationhub.lang` | 🌍 Language strings |

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
