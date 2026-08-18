<?php

if (!defined('MAIN_DB_PREFIX')) die('Access denied');

/**
 * Contract that every Commerce Automation Hub integration must implement.
 *
 * An "integration" is an optional, self-contained plugin that extends the sync
 * with data from a third-party WordPress plugin (e.g. Germanized Pro, WCPDF).
 * The core module has zero direct knowledge of any specific integration class.
 *
 * Lifecycle:
 *   1. WbsIntegrationManager scans helpers/ for *Integration.php files.
 *   2. Instances are created with ($db, $conf).
 *   3. isDetected() gates whether the integration is shown in Setup and called
 *      during sync — false means the integration is silently skipped.
 *   4. The sync loop and setup page call hooks via this interface only.
 */
interface WbsIntegrationInterface
{
    /** Short machine ID, e.g. 'germanized'. Used as HTML element IDs. */
    public function getId();

    /** Human-readable tab label shown in Setup. */
    public function getLabel();

    /**
     * Returns true when this integration appears to be active on the connected
     * WooCommerce site. Must be fast (no live API calls on every page load).
     */
    public function isDetected();

    // ── Order sync hooks ──────────────────────────────────────────────────────

    /**
     * Extract the invoice number from a WooCommerce order array.
     * Return '' when the integration cannot provide one.
     */
    public function extractInvoiceNumber(array $order);

    /**
     * Extract the invoice PDF download URL from a WooCommerce order array.
     * Return '' when not available.
     */
    public function extractPdfUrl(array $order);

    /**
     * Attempt to download and store the invoice PDF for a synced order.
     * $sync is the Commerce Automation Hub instance; call its public utilities as needed.
     * Return array{ok:bool, already:bool, filepath:string, log:string[]}.
     * Return ['ok'=>false,...] immediately if this integration does not handle PDFs
     * or if PDF download is disabled in its own settings.
     */
    public function tryDownloadPdf($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $sync, $force = false);

    /**
     * Enrich order data from integration-specific endpoints during cache refresh.
     * Called once per order in refreshFullCacheBatch().
     * Return array{invoice_number:string, pdf_url:string} (empty strings = no data).
     * $sync is the Commerce Automation Hub instance for reading constants.
     */
    public function enrichCacheOrder(array $order, $sync);

    // ── Setup UI ──────────────────────────────────────────────────────────────

    /**
     * Echo the HTML for this integration's settings tab.
     * The output is placed inside a <div> inside the tab panel.
     * May include inline <style> and <script> blocks.
     */
    public function renderSetupHtml($conf, $db, $langs, $token);

    /**
     * Handle a POST action from the integration's settings tab.
     * Return true if this integration handled the action (stops further dispatch).
     * $sync is provided for calling public utilities such as setConst().
     */
    public function handleAction($action, $conf, $db, $langs, $sync);

    /**
     * Handle an AJAX action (Content-Type: application/json already set).
     * If this integration handles the action, echo JSON and exit.
     * Return null to pass control to the next integration (or the main handler).
     */
    public function handleAjaxAction($action, $conf, $db, $langs, $sync);

    /**
     * Called at the end of Desync so the integration can clean up its own data.
     * Core bank/log deletion is already done by the main desync routine.
     * Return count of extra items deleted (informational only).
     */
    public function onDesync($db, $conf);
}
