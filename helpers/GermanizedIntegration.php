<?php

if (!defined('MAIN_DB_PREFIX')) die('Access denied');

require_once __DIR__ . '/../class/wbsgermanizedclient.class.php';

/**
 * WooBankSync integration for WooCommerce Germanized / Germanized Pro.
 *
 * Provides:
 *  - Invoice number extraction from order meta and Germanized document endpoints.
 *  - PDF download via StoreaBill API (/wp-json/sab/v1/) with URL fallback.
 *  - ECM folder and file management for saved PDFs.
 *  - Settings UI tab in WooBankSync Setup.
 *
 * This integration is completely optional. If Germanized is not installed on
 * the WooCommerce site, isDetected() returns false and nothing here runs.
 */
class WbsGermanizedIntegration implements WbsIntegrationInterface
{
    private $db;
    private $conf;

    /** Shared with WooBankSync::$pdfLog — populated during PDF fetch. */
    public $pdfLog = array();
    /** Set during StoreaBill fetch when the SAB response includes an invoice number. */
    public $lastSabInvoiceNumber = '';

    public function __construct($db, $conf)
    {
        $this->db   = $db;
        $this->conf = $conf;
    }

    public function getId() { return 'germanized'; }
    public function getLabel() { return 'Germanized / Germanized Pro'; }

    // ── Detection ─────────────────────────────────────────────────────────────

    public function isDetected()
    {
        // 1. User explicitly enabled Germanized in a previous session.
        if (!empty($this->conf->global->WBS_GERMANIZED_PRO_ENABLED)) return true;
        // 2. Scan existing data for Germanized signatures without extra API calls.
        return $this->scanForGzdSignatures();
    }

    private function scanForGzdSignatures()
    {
        // Any synced order that has an invoice number = Germanized was used before.
        $r = $this->db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . 'woobanksync_log'
            . ' WHERE entity=' . (int) $this->conf->entity
            . " AND woo_invoice_number IS NOT NULL AND woo_invoice_number != '' LIMIT 1");
        if ($r && $this->db->fetch_object($r)) return true;

        // Order cache JSON containing _wc_gzd meta keys.
        $r2 = $this->db->query('SELECT raw_order_json FROM ' . MAIN_DB_PREFIX . 'woobanksync_order_cache'
            . ' WHERE entity=' . (int) $this->conf->entity
            . " AND raw_order_json IS NOT NULL AND raw_order_json != '' LIMIT 20");
        if ($r2) {
            while ($obj = $this->db->fetch_object($r2)) {
                $json = (string) $obj->raw_order_json;
                if (strpos($json, '"_wc_gzd') !== false || strpos($json, '"_wc_gzdp') !== false) return true;
            }
        }
        return false;
    }

    // ── Invoice number extraction ─────────────────────────────────────────────

    public function extractInvoiceNumber(array $order)
    {
        if ((int) $this->getConst('WBS_GERMANIZED_PRO_ENABLED', '0') !== 1) return '';

        // StoreaBill / Germanized Pro embeds invoice data directly in the order
        // REST response under $order['invoices'] — not in meta_data.
        if (!empty($order['invoices']) && is_array($order['invoices'])) {
            foreach ($order['invoices'] as $invoice) {
                if (!empty($invoice['formatted_number'])) return (string) $invoice['formatted_number'];
                if (!empty($invoice['number'])) return (string) $invoice['number'];
            }
        }

        $configuredKeys = $this->getJsonConst('WBS_WOO_INVOICE_KEYS_JSON', array());
        $defaultKeys = array(
            '_wc_gzd_invoice_number', '_wc_gzd_order_invoice_number',
            '_wc_gzd_document_invoice_number', '_wc_gzd_document_number',
            '_wc_gzd_document_data', '_wc_gzd_invoice', '_wc_gzd_invoices',
            '_wc_gzdp_invoice_number', '_wc_gzdp_invoice_id',
            '_wcpdf_invoice_number', '_wcpdf_invoice_number_data',
            '_wpo_wcpdf_invoice_number', '_wpo_wcpdf_invoice_number_data',
            '_bewpi_invoice_number', '_invoice_number', 'invoice_number', 'document_number',
        );
        $keys = array_unique(array_merge($configuredKeys, $defaultKeys));

        foreach (($order['meta_data'] ?? array()) as $meta) {
            $key = (string) ($meta['key'] ?? '');
            foreach ($keys as $wanted) {
                if (strcasecmp($key, (string) $wanted) === 0) {
                    $found = $this->extractInvoiceNumberFromValue($meta['value'] ?? '', $this->invoiceMetaKeyAllowsNumericValue($key));
                    if ($found !== '') return $found;
                }
            }
        }

        // Heuristic second pass over all invoice/document/rechnung meta keys.
        foreach (($order['meta_data'] ?? array()) as $meta) {
            $key = strtolower((string) ($meta['key'] ?? ''));
            if ($this->looksLikeInvoiceMetaKey($key)) {
                $found = $this->extractInvoiceNumberFromValue($meta['value'] ?? '', $this->invoiceMetaKeyAllowsNumericValue($key));
                if ($found !== '') return $found;
            }
        }

        return '';
    }

    private function looksLikeInvoiceMetaKey($key)
    {
        foreach (array('invoice', 'rechnung', 'gzd_document', 'document_invoice', 'document_number', 'wcpdf') as $n) {
            if (strpos((string) $key, $n) !== false) return true;
        }
        return false;
    }

    private function invoiceMetaKeyAllowsNumericValue($key)
    {
        $key = strtolower((string) $key);
        if (strpos($key, 'id') !== false && !preg_match('/number|nummer|_no(?:_|$)/', $key)) return false;
        return (bool) preg_match('/number|nummer|_no(?:_|$)/', $key);
    }

    private function extractInvoiceNumberFromValue($value, $allowNumeric = false)
    {
        if (is_object($value)) $value = (array) $value;
        if (is_scalar($value)) {
            $value = trim((string) $value);
            if ($value === '') return '';
            if (!$allowNumeric && preg_match('/^\d{1,7}$/', $value)) return '';
            return dol_trunc($value, 120);
        }
        if (!is_array($value)) return '';

        foreach (array('formatted_number','number_formatted','invoice_number','document_number',
                       'formatted_invoice_number','formatted_document_number','number',
                       'invoiceNo','invoice_no','documentNo','document_no') as $f) {
            if (isset($value[$f])) {
                $found = $this->extractInvoiceNumberFromValue($value[$f], true);
                if ($found !== '') return $found;
            }
        }
        foreach ($value as $k => $v) {
            $kl = strtolower((string) $k);
            if (strpos($kl, 'id') !== false && !preg_match('/number|nummer|no/', $kl)) continue;
            if (preg_match('/number|nummer|invoice|rechnung|document|beleg/', $kl)) {
                $found = $this->extractInvoiceNumberFromValue($v, $this->invoiceMetaKeyAllowsNumericValue($kl));
                if ($found !== '') return $found;
            }
        }
        foreach ($value as $v) {
            if (is_array($v) || is_object($v)) {
                $found = $this->extractInvoiceNumberFromValue($v);
                if ($found !== '') return $found;
            }
        }
        return '';
    }

    // ── PDF URL extraction ────────────────────────────────────────────────────

    public function extractPdfUrl(array $order)
    {
        if ((int) $this->getConst('WBS_GERMANIZED_PRO_ENABLED', '0') !== 1) return '';
        return $this->extractPdfUrlFromOrder($order);
    }

    public function extractPdfUrlFromOrder(array $order)
    {
        $urlFields = array('download_url', 'file_url', 'pdf_url', 'url');
        foreach ((array) ($order['invoices'] ?? array()) as $invoice) {
            foreach ($urlFields as $f) {
                $v = trim((string) ($invoice[$f] ?? ''));
                if ($v !== '') return $v;
            }
            $path = trim((string) ($invoice['path'] ?? ''));
            if ($path !== '') { $w = $this->filesystemPathToWebUrl($path); if ($w !== '') return $w; }
        }
        foreach ((array) ($order['shipments'] ?? array()) as $shipment) {
            foreach ((array) ($shipment['invoices'] ?? array()) as $invoice) {
                foreach ($urlFields as $f) {
                    $v = trim((string) ($invoice[$f] ?? ''));
                    if ($v !== '') return $v;
                }
                $path = trim((string) ($invoice['path'] ?? ''));
                if ($path !== '') { $w = $this->filesystemPathToWebUrl($path); if ($w !== '') return $w; }
            }
        }
        return '';
    }

    private function filesystemPathToWebUrl($path)
    {
        foreach (array('/wp-content/', '/wp-includes/') as $marker) {
            $pos = strpos((string) $path, $marker);
            if ($pos !== false) {
                $wooUrl = rtrim((string) $this->getConst('WBS_WOO_URL', ''), '/');
                if ($wooUrl !== '') return $wooUrl . substr($path, $pos);
            }
        }
        return '';
    }

    private function gzdPdfUrl($orderId, $order = null)
    {
        $url    = (string) $this->getConst('WBS_WOO_URL', '');
        $key    = (string) $this->getConst('WBS_WOO_CONSUMER_KEY', '');
        $secret = (string) $this->getConst('WBS_WOO_CONSUMER_SECRET', '');
        if ($url === '' || $key === '' || $secret === '') return '';
        $gzd = new WbsGermanizedClient($url, $key, $secret);
        return $gzd->getInvoicePdfUrl($orderId, $order);
    }

    /** Probe Germanized endpoints for diagnostic — called from setup.php. */
    public function probeGzd($orderId)
    {
        $url    = (string) $this->getConst('WBS_WOO_URL', '');
        $key    = (string) $this->getConst('WBS_WOO_CONSUMER_KEY', '');
        $secret = (string) $this->getConst('WBS_WOO_CONSUMER_SECRET', '');
        if ($url === '' || $key === '' || $secret === '') return null;
        $gzd = new WbsGermanizedClient($url, $key, $secret);
        return $gzd->probeOrder((int) $orderId);
    }

    // ── PDF download ──────────────────────────────────────────────────────────

    public function tryDownloadPdf($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $sync, $force = false)
    {
        if ((int) $sync->getConst('WBS_PDF_DOWNLOAD_ENABLED', '0') !== 1) {
            return array('ok' => false, 'already' => false, 'filepath' => '', 'log' => array());
        }
        if (!$force) {
            $existing = $sync->getExistingEcmPath($orderId);
            if ($existing !== '' && $sync->isInvoicePdfStored($existing)) {
                return array('ok' => true, 'already' => true, 'filepath' => $existing, 'log' => array());
            }
        }
        $this->pdfLog = array();
        $ecmPath = $this->downloadAndStoreInvoicePdf($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $sync);
        $sync->pdfLog = $this->pdfLog;
        if ($ecmPath !== '') {
            $sync->updateCacheEcmPath($orderId, $ecmPath);
            return array('ok' => true, 'already' => false, 'filepath' => $ecmPath, 'log' => $this->pdfLog);
        }
        return array('ok' => false, 'already' => false, 'filepath' => '', 'log' => $this->pdfLog);
    }

    public function testFetchUrl($url)
    {
        return $this->fetchPdfContent((string) $url);
    }

    public function testFetchSabPdf($orderId)
    {
        $this->pdfLog = array();
        $this->lastSabInvoiceNumber = '';
        return $this->fetchStoreaBillPdf((int) $orderId);
    }

    public function getPendingPdfOrders($force, $sync)
    {
        $rows = array();
        $e    = (int) $this->conf->entity;
        $sql  = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'woobanksync_log'
            . " WHERE entity=$e AND sync_status='synced' ORDER BY rowid DESC";
        $resql = $this->db->query($sql);
        if (!$resql) return $rows;
        while ($obj = $this->db->fetch_object($resql)) {
            if (!$force) {
                $ecmFilepath = (string) ($obj->pdf_ecm_filepath ?? '');
                if ($sync->isInvoicePdfStored($ecmFilepath)) continue;
                if ($ecmFilepath !== '') $sync->updateCacheEcmPath((string) $obj->woo_order_id, '');
            }
            $rows[] = array(
                'id'      => (string) $obj->woo_order_id,
                'number'  => (string) $obj->woo_order_number,
                'invoice' => (string) ($obj->woo_invoice_number ?? ''),
            );
        }
        return $rows;
    }

    // ── Cache enrichment ──────────────────────────────────────────────────────

    public function enrichCacheOrder(array $order, $sync)
    {
        $result = array('invoice_number' => '', 'pdf_url' => '');
        if ((int) $sync->getConst('WBS_GERMANIZED_PRO_ENABLED', '0') !== 1) return $result;

        $invoiceNumber = $this->extractInvoiceNumber($order);
        $pdfUrl        = $this->extractPdfUrl($order);

        if ($invoiceNumber === '' && !empty($order['id'])) {
            $url    = (string) $sync->getConst('WBS_WOO_URL', '');
            $key    = (string) $sync->getConst('WBS_WOO_CONSUMER_KEY', '');
            $secret = (string) $sync->getConst('WBS_WOO_CONSUMER_SECRET', '');
            if ($url !== '' && $key !== '' && $secret !== '') {
                $gzd     = new WbsGermanizedClient($url, $key, $secret);
                $gzdData = $gzd->getOrderDocumentData((int) $order['id']);
                if (!empty($gzdData['invoice_number'])) $invoiceNumber = (string) $gzdData['invoice_number'];
                if (!empty($gzdData['invoice_pdf_url'])) $pdfUrl = (string) $gzdData['invoice_pdf_url'];
            }
        }

        $result['invoice_number'] = $invoiceNumber;
        $result['pdf_url']        = $pdfUrl;
        return $result;
    }

    // ── ECM folder / document folder ──────────────────────────────────────────

    public function createDocumentFolder($sync)
    {
        $folderId = (int) $this->findOrCreateEcmFolder('Woo Invoices');
        if ($folderId > 0) $sync->setConst('WBS_DOCUMENT_FOLDER_ID', (string) $folderId, 'chaine');
        return array($folderId > 0,
            $folderId > 0 ? 'Woo Invoices ECM folder is ready.' : 'Could not create/find ECM folder. Is Documents/ECM module enabled?');
    }

    // ── StoreaBill auto-detection ─────────────────────────────────────────────

    public function detectStoreaBillFolder($sync)
    {
        $pattern = '#/wp-content/uploads/(storeabill-[a-z0-9]+)/#i';

        // Step 1: parse PDF URLs already stored in the local cache (zero extra HTTP calls)
        foreach (array(MAIN_DB_PREFIX . 'woobanksync_order_cache', MAIN_DB_PREFIX . 'woobanksync_log') as $table) {
            $sql = 'SELECT woo_invoice_pdf_url FROM ' . $table
                . ' WHERE entity=' . (int) $this->conf->entity
                . " AND woo_invoice_pdf_url IS NOT NULL AND woo_invoice_pdf_url != '' LIMIT 50";
            $res = $this->db->query($sql);
            if (!$res) continue;
            while ($obj = $this->db->fetch_object($res)) {
                $url = (string) ($obj->woo_invoice_pdf_url ?? '');
                if (preg_match($pattern, $url, $m)) {
                    $sync->setConst('WBS_STOREABILL_FOLDER', $m[1], 'chaine');
                    return array(true, 'Detected from local cache: ' . $m[1]);
                }
            }
        }

        // Step 2: scan ECM filepath column
        $sql = 'SELECT pdf_ecm_filepath FROM ' . MAIN_DB_PREFIX . 'woobanksync_log'
            . ' WHERE entity=' . (int) $this->conf->entity
            . " AND pdf_ecm_filepath IS NOT NULL AND pdf_ecm_filepath != '' LIMIT 20";
        $res = $this->db->query($sql);
        if ($res) {
            while ($obj = $this->db->fetch_object($res)) {
                $path = (string) ($obj->pdf_ecm_filepath ?? '');
                $decoded = @json_decode($path, true);
                $found = '';
                if ($this->findPatternInData($decoded ?: $path, $pattern, $found)) {
                    $sync->setConst('WBS_STOREABILL_FOLDER', $found, 'chaine');
                    return array(true, 'Detected from ECM filepath: ' . $found);
                }
            }
        }

        // Step 3: probe live WooCommerce API
        $url    = (string) $sync->getConst('WBS_WOO_URL', '');
        $key    = (string) $sync->getConst('WBS_WOO_CONSUMER_KEY', '');
        $secret = (string) $sync->getConst('WBS_WOO_CONSUMER_SECRET', '');
        if ($url === '' || $key === '' || $secret === '') {
            return array(false, 'No StoreaBill URL found in local data and WooCommerce is not connected.');
        }

        require_once __DIR__ . '/../class/woocommerceclient.class.php';
        $client   = new WooCommerceClient($url, $key, $secret);
        $syncedIds = array();
        $rIds = $this->db->query('SELECT DISTINCT woo_order_id FROM ' . MAIN_DB_PREFIX . 'woobanksync_log'
            . ' WHERE entity=' . (int) $this->conf->entity . " AND sync_status='synced' ORDER BY rowid DESC LIMIT 5");
        if ($rIds) while ($obj = $this->db->fetch_object($rIds)) $syncedIds[] = (string) $obj->woo_order_id;

        $ordersToProbe = !empty($syncedIds) ? $client->getOrdersByIds($syncedIds) : $client->getRecentOrders(5);
        if ($ordersToProbe === false) {
            return array(false, 'No StoreaBill URLs in local data and WooCommerce connection failed: ' . $client->error);
        }

        $gzd        = new WbsGermanizedClient($url, $key, $secret);
        $scanPattern = '#/uploads/(storeabill-[a-z0-9]+)/#i';

        foreach ($ordersToProbe as $recent) {
            if (!is_array($recent)) continue;
            $found = '';
            if ($this->findPatternInData($recent, $scanPattern, $found)) {
                $sync->setConst('WBS_STOREABILL_FOLDER', $found, 'chaine');
                return array(true, 'Detected from order list (order #' . ($recent['number'] ?? $recent['id'] ?? '?') . '): ' . $found);
            }
        }

        foreach (array_slice($ordersToProbe, 0, 5) as $recent) {
            $orderId = (int) ($recent['id'] ?? 0);
            if ($orderId <= 0) continue;
            $orderNum = (string) ($recent['number'] ?? $orderId);
            $found    = '';

            $fullOrder = $gzd->getFullOrder($orderId);
            if ($fullOrder !== false && $this->findPatternInData($fullOrder, $scanPattern, $found)) {
                $sync->setConst('WBS_STOREABILL_FOLDER', $found, 'chaine');
                return array(true, 'Detected from single-order endpoint (order #' . $orderNum . '): ' . $found);
            }

            $docs = $gzd->getOrderDocuments($orderId);
            if ($docs !== false && $this->findPatternInData($docs, $scanPattern, $found)) {
                $sync->setConst('WBS_STOREABILL_FOLDER', $found, 'chaine');
                return array(true, 'Detected from Germanized document endpoint (order #' . $orderNum . '): ' . $found);
            }
        }

        return array(false, 'Probed ' . count($ordersToProbe) . ' orders — no StoreaBill URL found.');
    }

    private function findPatternInData($data, $pattern, &$found)
    {
        if (is_string($data)) {
            if ($data !== '' && preg_match($pattern, $data, $m)) { $found = (string) $m[1]; return true; }
            return false;
        }
        if (is_array($data)) {
            foreach ($data as $val) { if ($this->findPatternInData($val, $pattern, $found)) return true; }
        }
        return false;
    }

    // ── PDF download internals ────────────────────────────────────────────────

    private function downloadAndStoreInvoicePdf($orderId, $orderNumber, $invoiceNumber, $pdfUrl, $sync)
    {
        $this->lastSabInvoiceNumber = '';
        $content = $this->fetchStoreaBillPdf($orderId);
        if ($content !== false && $invoiceNumber === '' && $this->lastSabInvoiceNumber !== '') {
            $invoiceNumber = $this->lastSabInvoiceNumber;
        }
        if ($content === false) {
            if (!empty($pdfUrl)) {
                $this->pdfLog[] = '[DL] SAB failed — trying stored URL fallback';
                $content = $this->fetchPdfContent($pdfUrl);
            }
        }
        if ($content === false || strlen($content) < 64) {
            $this->pdfLog[] = '[DL] No valid PDF content obtained';
            return '';
        }
        $this->pdfLog[] = '[DL] Got ' . strlen($content) . ' bytes — proceeding to save';

        // Resolve ECM folder
        $folderId = (int) $this->getConst('WBS_DOCUMENT_FOLDER_ID', '0');
        if ($folderId <= 0) {
            $folderId = (int) $this->findOrCreateEcmFolder('Woo Invoices');
            if ($folderId > 0) {
                $sync->setConst('WBS_DOCUMENT_FOLDER_ID', (string) $folderId, 'chaine');
            } else {
                $this->pdfLog[] = '[STORE] ECM folder "Woo Invoices" not found — go to Setup and click "Create/repair Woo Invoices folder"';
                return '';
            }
        }

        $sql   = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'ecm_directories WHERE rowid=' . $folderId;
        $resql = $this->db->query($sql);
        if (!$resql || !($obj = $this->db->fetch_object($resql))) {
            $this->pdfLog[] = '[STORE] ECM folder row not found (ID=' . $folderId . ') — re-run database checks in Setup';
            return '';
        }
        $relpath = trim((string) (!empty($obj->relpath) ? $obj->relpath : (!empty($obj->fullpath) ? $obj->fullpath : ($obj->filepath ?? ''))), '/');
        if ($relpath === '') {
            $this->pdfLog[] = '[STORE] ECM folder has empty path — re-run database checks in Setup';
            return '';
        }

        $base = !empty($this->conf->ecm->dir_output)
            ? rtrim($this->conf->ecm->dir_output, '/\\')
            : (defined('DOL_DATA_ROOT') ? rtrim(DOL_DATA_ROOT, '/\\') . '/ecm' : '');
        if ($base === '') { $this->pdfLog[] = '[STORE] ECM base directory not configured'; return ''; }

        $dir = $base . '/' . $relpath;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $this->pdfLog[] = '[STORE] Cannot create ECM directory: ' . $dir;
            return '';
        }

        $safe     = static function ($s) { return preg_replace('/[^a-zA-Z0-9\-_]/', '-', trim((string) $s)); };
        $ts       = time();
        $filename = 'woo-' . $safe($orderNumber) . ($invoiceNumber !== '' ? '-' . $safe($invoiceNumber) : '') . '-dld-' . $ts . '.pdf';
        $filepath = $dir . '/' . $filename;
        $ecmRel   = $relpath . '/' . $filename;

        if (file_put_contents($filepath, $content) === false) {
            $this->pdfLog[] = '[STORE] file_put_contents failed: ' . $filepath;
            return '';
        }
        $this->pdfLog[] = '[STORE] Saved: ' . $ecmRel;
        $this->registerEcmFile($folderId, $filename, $ecmRel, $orderId, $invoiceNumber);
        return $ecmRel;
    }

    private function fetchStoreaBillPdf($orderId)
    {
        $wooUrl = rtrim((string) $this->getConst('WBS_WOO_URL', ''), '/');
        $key    = (string) $this->getConst('WBS_WOO_CONSUMER_KEY', '');
        $secret = (string) $this->getConst('WBS_WOO_CONSUMER_SECRET', '');
        if ($wooUrl === '' || $key === '' || $secret === '') return false;

        $gzd = new WbsGermanizedClient($wooUrl, $key, $secret);
        $this->pdfLog[] = '[SAB-1] Calling /wp-json/sab/v1/invoices/?reference_id=' . (int) $orderId;
        $invoices = $gzd->getStoreaBillInvoices((int) $orderId);
        if ($invoices === false) {
            $this->pdfLog[] = '[SAB-1] endpoint failed or returned no data: ' . $gzd->error;
            return false;
        }

        $sabId         = 0;
        $invoiceNumber = '';
        $list          = isset($invoices[0]) ? $invoices : array_values($invoices);
        foreach ($list as $item) {
            if (!is_array($item)) continue;
            if (!empty($item['id'])) $sabId = (int) $item['id'];
            if (empty($invoiceNumber)) {
                foreach (array('formatted_number', 'number', 'document_number') as $nf) {
                    if (!empty($item[$nf])) { $invoiceNumber = (string) $item[$nf]; break; }
                }
            }
            if ($sabId > 0) break;
        }

        if ($sabId <= 0) {
            $this->pdfLog[] = '[SAB-1] No invoice ID in SAB response (' . count($list) . ' items — is SAB plugin active?)';
            return false;
        }
        $this->pdfLog[] = '[SAB-1] Got SAB invoice ID: ' . $sabId . ($invoiceNumber !== '' ? ' (' . $invoiceNumber . ')' : '');
        $this->lastSabInvoiceNumber = $invoiceNumber;

        $pdfPath       = '/wp-json/sab/v1/invoices/' . $sabId . '/pdf';
        $pdfUrlWithAuth = $wooUrl . $pdfPath . '?consumer_key=' . urlencode($key) . '&consumer_secret=' . urlencode($secret);
        $this->pdfLog[] = '[SAB-2] Downloading from: ' . $wooUrl . $pdfPath;
        $body = $this->curlGet($pdfUrlWithAuth, '', '');
        $pdf  = $this->decodePdfBody($body);
        if ($pdf !== false && strlen($pdf) >= 64) {
            $this->pdfLog[] = '[SAB-2] OK — got PDF (' . strlen($pdf) . ' bytes)';
            return $pdf;
        }
        $this->pdfLog[] = '[SAB-2] failed: ' . ($body === false ? 'connection/HTTP error' : 'not a PDF/base64, starts with: ' . substr((string) $body, 0, 120));

        $this->pdfLog[] = '[SAB-3] Retry with Basic auth header: ' . $wooUrl . $pdfPath;
        $body = $this->curlGet($wooUrl . $pdfPath, $key, $secret);
        $pdf  = $this->decodePdfBody($body);
        if ($pdf !== false && strlen($pdf) >= 64) {
            $this->pdfLog[] = '[SAB-3] OK — got PDF (' . strlen($pdf) . ' bytes)';
            return $pdf;
        }
        $this->pdfLog[] = '[SAB-3] failed: ' . ($body === false ? 'connection/HTTP error' : 'not a PDF/base64, starts with: ' . substr((string) $body, 0, 120));
        return false;
    }

    private function decodePdfBody($body)
    {
        if ($body === false || !is_string($body) || $body === '') return false;
        if (substr($body, 0, 4) === '%PDF') return $body;
        $json = @json_decode($body, true);
        if (is_array($json)) {
            foreach (array('data','pdf','content','file','base64','pdf_data','body') as $k) {
                if (!empty($json[$k]) && is_string($json[$k])) {
                    $dec = base64_decode($json[$k], true);
                    if ($dec !== false && substr($dec, 0, 4) === '%PDF') {
                        $this->pdfLog[] = '  → base64 decoded field "' . $k . '" (' . strlen($dec) . ' bytes)';
                        return $dec;
                    }
                }
            }
        }
        $trimmed = trim($body);
        if (strlen($trimmed) > 32 && preg_match('/^[A-Za-z0-9+\/\r\n]+=*$/', $trimmed)) {
            $dec = base64_decode($trimmed, true);
            if ($dec !== false && substr($dec, 0, 4) === '%PDF') {
                $this->pdfLog[] = '  → base64 decoded raw body (' . strlen($dec) . ' bytes)';
                return $dec;
            }
        }
        return false;
    }

    private function fetchPdfContent($url)
    {
        $this->pdfLog = array();
        if (!preg_match('#^https?://#i', $url)) {
            $converted = $this->filesystemPathToWebUrl($url);
            if ($converted !== '') {
                $this->pdfLog[] = '[0] filesystem path converted: ' . $converted;
                $url = $converted;
            } else {
                $this->pdfLog[] = '[0] not an HTTP URL and WooCommerce base URL not configured: ' . $url;
                return false;
            }
        }
        $key    = (string) $this->getConst('WBS_WOO_CONSUMER_KEY', '');
        $secret = (string) $this->getConst('WBS_WOO_CONSUMER_SECRET', '');

        $urlPath = (string) @parse_url($url, PHP_URL_PATH);
        if ($urlPath !== '' && strtolower(substr($urlPath, -4)) === '.pdf' && strpos($url, '?') !== false) {
            $cleanUrl = (string) strstr($url, '?', true);
            if ($cleanUrl !== '') {
                $this->pdfLog[] = '[1] bare URL: ' . $cleanUrl;
                $body = $this->curlGet($cleanUrl, '', '');
                if ($body !== false && substr($body, 0, 4) === '%PDF') { $this->pdfLog[] = '[1] OK (' . strlen($body) . ' bytes)'; return $body; }
                $this->pdfLog[] = '[1] failed: ' . ($body === false ? 'connection error' : substr($body, 0, 80));
            }
        }
        if ($key !== '' && $secret !== '' && strpos($url, '/wp-json/') !== false) {
            $sep     = strpos($url, '?') !== false ? '&' : '?';
            $authUrl = $url . $sep . 'consumer_key=' . urlencode($key) . '&consumer_secret=' . urlencode($secret);
            $this->pdfLog[] = '[2] WC REST with credentials: ' . $authUrl;
            $body = $this->curlGet($authUrl, '', '');
            if ($body !== false && substr($body, 0, 4) === '%PDF') { $this->pdfLog[] = '[2] OK (' . strlen($body) . ' bytes)'; return $body; }
            $this->pdfLog[] = '[2] failed: ' . ($body === false ? 'connection error' : substr($body, 0, 80));
        }
        $this->pdfLog[] = '[3] as-is: ' . $url;
        $body = $this->curlGet($url, '', '');
        if ($body !== false && substr($body, 0, 4) === '%PDF') { $this->pdfLog[] = '[3] OK (' . strlen($body) . ' bytes)'; return $body; }
        $this->pdfLog[] = '[3] failed: ' . ($body === false ? 'connection error' : substr($body, 0, 80));
        if ($key !== '' && $secret !== '') {
            $this->pdfLog[] = '[4] Basic auth header: ' . $url;
            $body = $this->curlGet($url, $key, $secret);
            if ($body !== false && substr($body, 0, 4) === '%PDF') { $this->pdfLog[] = '[4] OK (' . strlen($body) . ' bytes)'; return $body; }
            $this->pdfLog[] = '[4] failed: ' . ($body === false ? 'connection error' : substr($body, 0, 80));
        }
        $this->pdfLog[] = 'All attempts failed for: ' . $url;
        return false;
    }

    private function curlGet($url, $key, $secret)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        if ($key !== '' && $secret !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $key . ':' . $secret);
        }
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode < 200 || $httpCode >= 300 || empty($body)) return false;
        return $body;
    }

    // ── ECM utilities ─────────────────────────────────────────────────────────

    private function findOrCreateEcmFolder($label)
    {
        $table = MAIN_DB_PREFIX . 'ecm_directories';
        if (empty($this->getTableColumns($table))) return 0;
        $sql = 'SELECT rowid FROM ' . $table . ' WHERE entity=' . (int) $this->conf->entity . " AND label='" . $this->db->escape($label) . "' LIMIT 1";
        $res = $this->db->query($sql);
        if ($res && ($obj = $this->db->fetch_object($res))) return (int) $obj->rowid;

        $this->ensurePhysicalEcmFolder($label);
        $fields  = $this->getTableColumns($table);
        $data    = array();
        $relpath = $this->sanitizeEcmRelPath($label);
        $this->addDataIfColumn($data, $fields, 'label', "'" . $this->db->escape($label) . "'", false);
        $this->addDataIfColumn($data, $fields, 'description', "'WooCommerce PDF invoices'", false);
        $this->addDataIfColumn($data, $fields, 'fullpath', "'" . $this->db->escape($relpath) . "'", false);
        $this->addDataIfColumn($data, $fields, 'relpath', "'" . $this->db->escape($relpath) . "'", false);
        $this->addDataIfColumn($data, $fields, 'filepath', "'" . $this->db->escape($relpath) . "'", false);
        $this->addDataIfColumn($data, $fields, 'cachenbofdoc', 0, true);
        $this->addDataIfColumn($data, $fields, 'fk_parent', 0, true);
        $this->addDataIfColumn($data, $fields, 'entity', (int) $this->conf->entity, true);
        $this->addDataIfColumn($data, $fields, 'date_c', $this->sqlDateNow(), false);
        $this->addDataIfColumn($data, $fields, 'fk_user_c', isset($GLOBALS['user']->id) ? (int) $GLOBALS['user']->id : 0, true);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_values($data)) . ')';
        $res = $this->db->query($sql);
        if (!$res) return 0;
        return (int) $this->db->last_insert_id($table);
    }

    private function sanitizeEcmRelPath($label)
    {
        $label = trim((string) $label);
        $label = str_replace(array('..', '/', '\\'), array('', ' ', ' '), $label);
        return $label !== '' ? $label : 'Woo Invoices';
    }

    private function ensurePhysicalEcmFolder($label)
    {
        $relpath = $this->sanitizeEcmRelPath($label);
        $base    = '';
        if (!empty($this->conf->ecm->dir_output)) $base = $this->conf->ecm->dir_output;
        elseif (defined('DOL_DATA_ROOT')) $base = DOL_DATA_ROOT . '/ecm';
        if ($base === '') return false;
        $dir = rtrim($base, '/\\') . '/' . $relpath;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return is_dir($dir);
    }

    private function registerEcmFile($folderId, $filename, $relPath, $orderId, $invoiceNumber)
    {
        $fields = $this->getTableColumns(MAIN_DB_PREFIX . 'ecm_files');
        if (empty($fields)) return;
        $folderPath = ltrim(dirname($relPath), '/\\');
        $label      = pathinfo($filename, PATHINFO_FILENAME);
        $data       = array();
        $this->addDataIfColumn($data, $fields, 'label', "'" . $this->db->escape($label) . "'", false);
        $this->addDataIfColumn($data, $fields, 'filename', "'" . $this->db->escape($filename) . "'", false);
        $this->addDataIfColumn($data, $fields, 'filepath', "'" . $this->db->escape($folderPath) . "'", false);
        $this->addDataIfColumn($data, $fields, 'fullpath_orig', "'" . $this->db->escape($relPath) . "'", false);
        $this->addDataIfColumn($data, $fields, 'fk_parent', (int) $folderId, true);
        $this->addDataIfColumn($data, $fields, 'entity', (int) $this->conf->entity, true);
        $this->addDataIfColumn($data, $fields, 'fk_user_c', isset($GLOBALS['user']->id) ? (int) $GLOBALS['user']->id : 0, true);
        $this->addDataIfColumn($data, $fields, 'date_c', $this->sqlDateNow(), false);
        $this->addDataIfColumn($data, $fields, 'note', "'" . $this->db->escape('WooBankSync order #' . $orderId . ($invoiceNumber !== '' ? ' / ' . $invoiceNumber : '')) . "'", false);
        $this->addDataIfColumn($data, $fields, 'keywords', "'" . $this->db->escape('woobanksync woo ' . $orderId) . "'", false);
        $this->addDataIfColumn($data, $fields, 'mimetype', "'application/pdf'", false);
        $this->addDataIfColumn($data, $fields, 'status', 1, true);
        $this->addDataIfColumn($data, $fields, 'position', 0, true);
        if (empty($data)) return;
        $sql = 'INSERT IGNORE INTO ' . MAIN_DB_PREFIX . 'ecm_files (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_values($data)) . ')';
        $this->db->query($sql);
        $this->db->query('UPDATE ' . MAIN_DB_PREFIX . 'ecm_directories SET cachenbofdoc=(SELECT COUNT(*) FROM ' . MAIN_DB_PREFIX . 'ecm_files WHERE fk_parent=' . (int) $folderId . ' AND entity=' . (int) $this->conf->entity . ') WHERE rowid=' . (int) $folderId);
    }

    // ── Desync ────────────────────────────────────────────────────────────────

    public function onDesync($db, $conf) { return 0; }

    // ── Setup UI ──────────────────────────────────────────────────────────────

    public function handleAction($action, $conf, $db, $langs, $sync)
    {
        if ($action === 'save_invoice') {
            $gzdEnabled  = GETPOST('WBS_GERMANIZED_PRO_ENABLED', 'int') ? '1' : '0';
            $sync->setConst('WBS_GERMANIZED_PRO_ENABLED', $gzdEnabled, 'yesno');
            $sync->setConst('WBS_DOCUMENT_SYNC_ENABLED',  ($gzdEnabled === '1' && GETPOST('WBS_DOCUMENT_SYNC_ENABLED', 'int')) ? '1' : '0', 'yesno');
            $sync->setConst('WBS_BANK_EXTRAFIELD_ENABLED', ($gzdEnabled === '1' && GETPOST('WBS_BANK_EXTRAFIELD_ENABLED', 'int')) ? '1' : '0', 'yesno');
            $sync->setConst('WBS_BANK_EXTRAFIELD_CODE',   GETPOST('WBS_BANK_EXTRAFIELD_CODE', 'aZ09'), 'chaine');
            $sync->setConst('WBS_DOCUMENT_FOLDER_ID',     GETPOST('WBS_DOCUMENT_FOLDER_ID', 'int'), 'chaine');
            $sync->setConst('WBS_PDF_DOWNLOAD_ENABLED',   ($gzdEnabled === '1' && GETPOST('WBS_PDF_DOWNLOAD_ENABLED', 'int')) ? '1' : '0', 'yesno');
            setEventMessages('Germanized settings saved.', null, 'mesgs');
            return true;
        }
        if ($action === 'create_invoice_extrafield') {
            list($ok, $msg) = $sync->createAndMapInvoiceBankExtraField();
            setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
            return true;
        }
        if ($action === 'createdocs') {
            list($ok, $msg) = $this->createDocumentFolder($sync);
            setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
            return true;
        }
        if ($action === 'detect_storeabill') {
            list($ok, $msg) = $this->detectStoreaBillFolder($sync);
            setEventMessages($msg, null, $ok ? 'mesgs' : 'errors');
            return true;
        }
        if ($action === 'save_storeabill') {
            $folder = trim(GETPOST('WBS_STOREABILL_FOLDER', 'restricthtml'));
            if ($folder !== '' && !preg_match('/^storeabill-[a-z0-9]+$/i', $folder)) {
                setEventMessages('Invalid folder name. Expected: storeabill-xxxxxxxx', null, 'errors');
            } else {
                $sync->setConst('WBS_STOREABILL_FOLDER', $folder, 'chaine');
                setEventMessages($folder !== '' ? 'StoreaBill folder saved: ' . $folder : 'StoreaBill folder cleared.', null, 'mesgs');
            }
            return true;
        }
        return false;
    }

    public function handleAjaxAction($action, $conf, $db, $langs, $sync)
    {
        if ($action === 'setup_test_pdf') {
            if (!$GLOBALS['user']->admin) { echo json_encode(array('ok' => false, 'error' => 'Access denied', 'log' => array())); exit; }
            $testUrl = trim(GETPOST('pdf_url', 'restricthtml'));
            $orderId = trim(GETPOST('woo_order_id', 'alphanohtml'));
            if ($orderId !== '') {
                $content = $this->testFetchSabPdf((int) $orderId);
                $sync->pdfLog = $this->pdfLog;
                echo json_encode(array(
                    'ok' => ($content !== false), 'mode' => 'sab', 'order_id' => $orderId,
                    'invoice_number' => $this->lastSabInvoiceNumber,
                    'bytes' => ($content !== false ? strlen($content) : 0),
                    'is_pdf' => ($content !== false && substr($content, 0, 4) === '%PDF'),
                    'log' => $this->pdfLog,
                ));
                exit;
            }
            if ($testUrl === '') {
                echo json_encode(array('ok' => false, 'error' => 'Enter a WooCommerce order ID or a direct PDF URL.', 'log' => array())); exit;
            }
            $content = $this->testFetchUrl($testUrl);
            $sync->pdfLog = $this->pdfLog;
            echo json_encode(array(
                'ok' => ($content !== false), 'mode' => 'url', 'url' => $testUrl,
                'bytes' => ($content !== false ? strlen($content) : 0),
                'is_pdf' => ($content !== false && substr($content, 0, 4) === '%PDF'),
                'log' => $this->pdfLog,
            ));
            exit;
        }
        if ($action === 'setup_pdf_status') {
            if (!$GLOBALS['user']->admin) { echo json_encode(array('ok' => false, 'error' => 'Access denied')); exit; }
            $sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'woobanksync_log'
                . ' WHERE entity=' . (int) $conf->entity . " AND sync_status='synced' ORDER BY rowid DESC LIMIT 500";
            $rows  = array();
            $resql = $db->query($sql);
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    $rows[] = array(
                        'id' => (string) ($obj->woo_order_id ?? ''), 'number' => (string) ($obj->woo_order_number ?? ''),
                        'invoice' => (string) ($obj->woo_invoice_number ?? ''), 'payment' => (string) ($obj->payment_method ?? ''),
                        'date' => (string) ($obj->date_sync ?? ''),
                        'pdf_url' => (string) ($obj->woo_invoice_pdf_url ?? ''), 'pdf_ecm' => (string) ($obj->pdf_ecm_filepath ?? ''),
                    );
                }
            }
            echo json_encode(array('ok' => true, 'rows' => $rows));
            exit;
        }
        return null;
    }

    public function renderSetupHtml($conf, $db, $langs, $token, $sync = null)
    {
        $gzdEnabled           = !empty($conf->global->WBS_GERMANIZED_PRO_ENABLED);
        $labelEnabled         = !empty($conf->global->WBS_DOCUMENT_SYNC_ENABLED);
        $extraEnabled         = !empty($conf->global->WBS_BANK_EXTRAFIELD_ENABLED);
        $pdfDownloadEnabled   = !empty($conf->global->WBS_PDF_DOWNLOAD_ENABLED);
        $mappedBankExtraField = (string) ($conf->global->WBS_BANK_EXTRAFIELD_CODE ?? '');
        $mappedFolderId       = (string) ($conf->global->WBS_DOCUMENT_FOLDER_ID ?? '');
        $storeabillFolder     = (string) ($conf->global->WBS_STOREABILL_FOLDER ?? '');
        $bankExtraFields      = $sync ? $sync->getBankExtraFields() : array();
        $self                 = $_SERVER['PHP_SELF'];
        ?>
<form method="POST" action="<?php echo $self; ?>">
<input type="hidden" name="token" value="<?php echo $token; ?>">
<input type="hidden" name="action" value="save_invoice">
<table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">Germanized / Germanized Pro — invoice integration</td></tr>
<tr><td class="titlefield">Enable Germanized integration</td><td>
<label><input type="checkbox" id="wbsGzdToggle" name="WBS_GERMANIZED_PRO_ENABLED" value="1"<?php echo $gzdEnabled ? ' checked' : ''; ?>
 onchange="document.getElementById('wbsGzdSub').style.display=this.checked?'table-row-group':'none';">
Enable Germanized Pro invoice extraction</label>
<br><span class="opacitymedium">Reads invoice number from <code>invoices[0].formatted_number</code> in the WooCommerce order response (StoreaBill / Germanized Pro).</span>
</td></tr>
</table>
<table class="noborder centpercent" id="wbsGzdSub" style="<?php echo $gzdEnabled ? '' : 'display:none;'; ?>">
<tr><td class="titlefield">Add invoice number to label</td><td>
<label><input type="checkbox" name="WBS_DOCUMENT_SYNC_ENABLED" value="1"<?php echo $labelEnabled ? ' checked' : ''; ?>>
 Append invoice number to bank entry label (title)</label>
</td></tr>
<tr><td class="titlefield">Store in a custom field</td><td>
<label><input type="checkbox" name="WBS_BANK_EXTRAFIELD_ENABLED" value="1"<?php echo $extraEnabled ? ' checked' : ''; ?>>
 Also write the invoice number into a mapped bank-entry custom field</label>
</td></tr>
<tr><td class="titlefield">Bank-entry custom field</td><td>
<select class="flat minwidth300" name="WBS_BANK_EXTRAFIELD_CODE"><option value="">-- not mapped --</option>
<?php foreach ($bankExtraFields as $code => $fieldLabel): ?>
<option value="<?php echo dol_escape_htmltag($code); ?>"<?php echo $code === $mappedBankExtraField ? ' selected' : ''; ?>><?php echo dol_escape_htmltag($fieldLabel . ' (' . $code . ')'); ?></option>
<?php endforeach; ?>
</select>
</td></tr>
<tr><td class="titlefield">Download PDF invoices during sync</td><td>
<label><input type="checkbox" name="WBS_PDF_DOWNLOAD_ENABLED" value="1"<?php echo $pdfDownloadEnabled ? ' checked' : ''; ?>>
 Automatically download and save invoice PDFs during sync</label>
<br><span class="opacitymedium">Requires a mapped ECM folder below. Each synced order triggers one extra HTTP request.</span>
</td></tr>
<tr><td class="titlefield">Invoice PDF document folder</td><td>
<?php if (function_exists('wbs_ecm_folder_select')) { echo wbs_ecm_folder_select('WBS_DOCUMENT_FOLDER_ID', $mappedFolderId); } ?>
<br><span class="opacitymedium">ECM folder where Woo invoice PDFs will be stored.</span>
</td></tr>
</table>
<div class="center"><input class="button button-save" type="submit" value="Save Germanized settings"></div>
</form>
<form method="POST" action="<?php echo $self; ?>" class="center" style="margin-top:6px;">
<input type="hidden" name="token" value="<?php echo $token; ?>"><input type="hidden" name="action" value="create_invoice_extrafield">
<input class="button" type="submit" value="Create invoice-number custom field and map it automatically"<?php echo $mappedBankExtraField !== '' ? ' disabled' : ''; ?>>
</form>
<form method="POST" action="<?php echo $self; ?>" class="center" style="margin-top:4px;">
<input type="hidden" name="token" value="<?php echo $token; ?>"><input type="hidden" name="action" value="createdocs">
<input class="button" type="submit" value="Create Woo Invoices ECM folder if missing and map it"<?php echo $mappedFolderId !== '' ? ' disabled' : ''; ?>>
</form>

<?php if ($gzdEnabled): ?>
<br><table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">StoreaBill PDF directory</td></tr>
<tr><td class="titlefield">Detected folder</td><td>
<?php if ($storeabillFolder !== ''): ?>
<code><?php echo dol_escape_htmltag($storeabillFolder); ?></code> <span style="color:green;">&#10003; detected</span>
<?php else: ?><span class="opacitymedium">Not detected yet.</span><?php endif; ?>
</td></tr>
<tr><td class="titlefield">Auto-detect</td><td>
<form method="POST" action="<?php echo $self; ?>" style="display:inline-block;">
<input type="hidden" name="token" value="<?php echo $token; ?>"><input type="hidden" name="action" value="detect_storeabill">
<input class="button" type="submit" value="Detect StoreaBill folder">
</form>
<br><span class="opacitymedium">Reads PDF URLs from local cache first; probes Germanized document endpoint if none found.</span>
</td></tr>
<tr><td class="titlefield">Manual override</td><td>
<form method="POST" action="<?php echo $self; ?>" style="display:inline-block;"
  onsubmit="return confirm('Changing this manually may break PDF downloads. Continue?');">
<input type="hidden" name="token" value="<?php echo $token; ?>"><input type="hidden" name="action" value="save_storeabill">
<input class="flat" type="text" name="WBS_STOREABILL_FOLDER" value="<?php echo dol_escape_htmltag($storeabillFolder); ?>" placeholder="storeabill-xxxxxxxx" style="width:220px;">
 <input class="button" type="submit" value="Save">
</form>
<br><span style="color:#c05000;font-size:0.88em;">&#9888; Warning: manual changes may break PDF downloads.</span>
</td></tr>
</table>

<br><table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">PDF invoices</td></tr>
<tr><td class="titlefield">Download past PDFs</td><td>
<button class="button" type="button" onclick="wbsGzdOpenPdfModal()">&#128196; Download invoice PDFs</button>
<label title="Force re-download all even if already saved" style="cursor:pointer;font-size:0.9em;vertical-align:middle;margin-left:8px;"><input type="checkbox" id="wbsGzdForceDownload" style="vertical-align:middle;margin-right:3px;">Force re-download all</label>
&nbsp;
<button class="button" type="button" onclick="wbsGzdOpenPdfStatusModal()" title="See which orders have PDFs saved, URL known, or missing">&#128202; PDF download status</button>
</td></tr>
</table>

<br><table class="noborder centpercent">
<tr class="liste_titre"><td colspan="2">PDF download test</td></tr>
<tr><td class="titlefield">Order ID or PDF URL</td><td>
<input type="text" id="wbsGzdTestPdfInput" class="flat" placeholder="WooCommerce order ID (e.g. 30955)  OR  https://… direct PDF URL" style="width:500px;max-width:100%;">
<br><button class="button" type="button" onclick="wbsGzdTestPdf()" style="margin-top:6px;">Test PDF download</button>
<span id="wbsGzdTestPdfResult" style="margin-left:12px;font-size:.88em;"></span>
<pre id="wbsGzdTestPdfLog" style="background:#f4f4f4;padding:8px;font-size:.8em;white-space:pre-wrap;margin-top:8px;display:none;max-height:200px;overflow-y:auto;"></pre>
</td></tr>
</table>

<!-- PDF download modal -->
<div id="wbsGzdPdfModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.55);z-index:9998;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:82%;max-width:860px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.28);">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
      <strong>&#128196; Download past invoice PDFs</strong>
      <span onclick="wbsGzdEsc('wbsGzdPdfModal')" style="cursor:pointer;font-size:22px;color:#666;">&times;</span>
    </div>
    <div style="padding:14px 20px 10px;">
      <div style="background:#e8e8e8;border-radius:4px;height:10px;overflow:hidden;"><div id="wbsGzdPdfBar" style="width:0%;height:10px;background:#28a745;border-radius:4px;transition:width .4s;"></div></div>
      <div id="wbsGzdPdfStatus" style="margin-top:6px;font-size:.88em;color:#555;">Preparing&hellip;</div>
    </div>
    <div id="wbsGzdPdfList" style="flex:1;overflow-y:auto;padding:4px 20px 10px;font-size:.9em;"></div>
    <div style="padding:12px 20px;border-top:1px solid #ddd;display:flex;gap:10px;align-items:center;">
      <button id="wbsGzdPdfDoneBtn" class="button" style="display:none;" onclick="wbsGzdEsc('wbsGzdPdfModal')">Close</button>
      <span id="wbsGzdPdfSummary" style="font-size:.88em;color:#666;"></span>
    </div>
  </div>
</div>

<!-- PDF status modal -->
<div id="wbsGzdStatusModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:6px;width:95%;max-width:1100px;max-height:90vh;display:flex;flex-direction:column;">
    <div style="padding:14px 20px;border-bottom:1px solid #ddd;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <b style="flex:1;font-size:1.1em;">&#128202; PDF download status</b>
      <input type="text" id="wbsGzdStatusSearch" placeholder="Search order #, invoice…" class="flat" style="width:240px;" oninput="wbsGzdFilterStatus()">
      <label style="font-size:0.85em;cursor:pointer;white-space:nowrap;"><input type="checkbox" id="wbsGzdStatusMissingOnly" onchange="wbsGzdFilterStatus()"> Missing only</label>
      <span id="wbsGzdStatusCount" style="font-size:0.85em;color:#888;white-space:nowrap;"></span>
      <button class="button" type="button" onclick="wbsGzdEsc('wbsGzdStatusModal')">Close</button>
    </div>
    <div style="overflow:auto;flex:1;">
      <table class="liste centpercent" style="font-size:0.82em;">
        <thead><tr class="liste_titre">
          <th>Date sync</th><th>Order #</th><th>Invoice #</th><th>Payment</th><th style="text-align:center;">PDF status</th><th>ECM path / URL</th>
        </tr></thead>
        <tbody id="wbsGzdStatusBody"><tr><td colspan="6" style="text-align:center;padding:20px;color:#888;">Loading…</td></tr></tbody>
      </table>
    </div>
    <div style="padding:10px 20px;border-top:1px solid #ddd;font-size:0.82em;color:#666;">
      ✅ = PDF saved in Dolibarr ECM &nbsp;&nbsp; &#128196; = URL known, not yet downloaded &nbsp;&nbsp; ❌ = No PDF info
    </div>
  </div>
</div>

<script>
var _wbsGzdStatusRows=[];
function wbsGzdEsc(id){var el=document.getElementById(id);if(el){el.style.display='none';}}
function wbsGzdOpenPdfModal(){
  var force=document.getElementById('wbsGzdForceDownload')&&document.getElementById('wbsGzdForceDownload').checked?'1':'0';
  document.getElementById('wbsGzdPdfModal').style.display='flex';
  document.getElementById('wbsGzdPdfBar').style.width='0%';
  document.getElementById('wbsGzdPdfDoneBtn').style.display='none';
  document.getElementById('wbsGzdPdfSummary').textContent='';
  document.getElementById('wbsGzdPdfList').innerHTML='';
  document.getElementById('wbsGzdPdfStatus').textContent='Fetching pending orders…';
  fetch(_wbsIndexAjaxUrl+'?action=pending_pdfs&force='+force+'&token='+encodeURIComponent(_wbsSetupToken))
    .then(function(r){return r.json();})
    .then(function(data){
      if(!data.orders||!data.orders.length){
        document.getElementById('wbsGzdPdfStatus').textContent='No pending PDFs found.';
        document.getElementById('wbsGzdPdfDoneBtn').style.display='';
        return;
      }
      wbsGzdRunPdfQueue(data.orders,0,force,0,0);
    }).catch(function(e){document.getElementById('wbsGzdPdfStatus').textContent='Error: '+e;});
}
function wbsGzdRunPdfQueue(orders,idx,force,ok,fail){
  if(idx>=orders.length){
    document.getElementById('wbsGzdPdfBar').style.width='100%';
    document.getElementById('wbsGzdPdfStatus').textContent='Done: '+ok+' downloaded, '+fail+' failed.';
    document.getElementById('wbsGzdPdfSummary').textContent=ok+' PDFs saved, '+fail+' failed.';
    document.getElementById('wbsGzdPdfDoneBtn').style.display='';
    return;
  }
  var o=orders[idx];
  document.getElementById('wbsGzdPdfBar').style.width=Math.round(idx/orders.length*100)+'%';
  document.getElementById('wbsGzdPdfStatus').textContent='Downloading PDF for order #'+o.number+' ('+idx+'/'+orders.length+')…';
  var body='action=download_pdf_single&woo_order_id='+encodeURIComponent(o.id)+'&force='+(force||'0')+'&token='+encodeURIComponent(_wbsSetupToken);
  fetch(_wbsIndexAjaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
    .then(function(r){return r.json();})
    .then(function(res){
      var li='<div style="padding:2px 0;font-size:.88em;">';
      li+=res.ok?'<span style="color:#090;">&#10003;</span>':'<span style="color:#c00;">&#10007;</span>';
      li+=' #'+o.number+(res.invoice?' / '+res.invoice:'')+(res.already?' (already saved)':'')+(res.ok&&!res.already?' &#10003; saved':'')+'</div>';
      document.getElementById('wbsGzdPdfList').innerHTML+=li;
      wbsGzdRunPdfQueue(orders,idx+1,force,ok+(res.ok?1:0),fail+(res.ok?0:1));
    }).catch(function(){wbsGzdRunPdfQueue(orders,idx+1,force,ok,fail+1);});
}
function wbsGzdOpenPdfStatusModal(){
  document.getElementById('wbsGzdStatusModal').style.display='flex';
  document.getElementById('wbsGzdStatusBody').innerHTML='<tr><td colspan="6" style="text-align:center;padding:20px;color:#888;">Loading…</td></tr>';
  var fd=new FormData();fd.append('token',_wbsSetupToken);fd.append('action','setup_pdf_status');
  fetch(location.href,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
    _wbsGzdStatusRows=data.rows||[];
    wbsGzdFilterStatus();
  });
}
function wbsGzdFilterStatus(){
  var q=(document.getElementById('wbsGzdStatusSearch').value||'').toLowerCase().trim();
  var missingOnly=document.getElementById('wbsGzdStatusMissingOnly').checked;
  var rows=_wbsGzdStatusRows.filter(function(r){
    if(missingOnly&&(r.pdf_ecm||r.pdf_url))return false;
    return !q||(r.number+r.invoice+r.payment).toLowerCase().indexOf(q)>=0;
  });
  var saved=_wbsGzdStatusRows.filter(function(r){return!!r.pdf_ecm;}).length;
  var urlOnly=_wbsGzdStatusRows.filter(function(r){return!r.pdf_ecm&&!!r.pdf_url;}).length;
  document.getElementById('wbsGzdStatusCount').textContent=rows.length+' of '+_wbsGzdStatusRows.length+' — '+saved+' saved, '+urlOnly+' URL only';
  var html='';
  rows.forEach(function(r,i){
    var icon,detail;
    if(r.pdf_ecm){icon='<span style="color:#090;" title="Saved in ECM">✅</span>';detail='<span style="color:#090;font-size:.85em;">'+r.pdf_ecm+'</span>';}
    else if(r.pdf_url){icon='<span style="color:#e67e22;" title="URL known, not downloaded">&#128196;</span>';detail='<a href="'+r.pdf_url+'" target="_blank" style="font-size:.85em;word-break:break-all;">'+r.pdf_url+'</a>';}
    else{icon='<span style="color:#c00;" title="No PDF info">❌</span>';detail='<span style="color:#aaa;font-size:.85em;">—</span>';}
    html+='<tr class="'+(i%2===0?'impair':'pair')+'">'
      +'<td style="white-space:nowrap;">'+(r.date||'').substring(0,16)+'</td><td>#'+r.number+'</td><td>'+r.invoice+'</td><td>'+r.payment+'</td>'
      +'<td style="text-align:center;">'+icon+'</td><td>'+detail+'</td></tr>';
  });
  document.getElementById('wbsGzdStatusBody').innerHTML=html||'<tr><td colspan="6" style="text-align:center;color:#888;">No rows.</td></tr>';
}
function wbsGzdTestPdf(){
  var val=document.getElementById('wbsGzdTestPdfInput').value.trim();
  if(!val){document.getElementById('wbsGzdTestPdfLog').textContent='Enter an order ID or PDF URL first.';document.getElementById('wbsGzdTestPdfLog').style.display='block';return;}
  document.getElementById('wbsGzdTestPdfResult').textContent='Testing…';
  document.getElementById('wbsGzdTestPdfLog').style.display='none';
  var fd=new FormData();
  fd.append('token',_wbsSetupToken);fd.append('action','setup_test_pdf');
  if(/^\d+$/.test(val))fd.append('woo_order_id',val); else fd.append('pdf_url',val);
  fetch(location.href,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
    document.getElementById('wbsGzdTestPdfResult').innerHTML=d.ok
      ?'<span style="color:#090;">&#10003; PDF OK — '+d.bytes+' bytes</span>'+(d.invoice_number?' ('+d.invoice_number+')':'')
      :'<span style="color:#c00;">&#10007; Failed</span>';
    if(d.log&&d.log.length){document.getElementById('wbsGzdTestPdfLog').textContent=d.log.join('\n');document.getElementById('wbsGzdTestPdfLog').style.display='block';}
  }).catch(function(e){document.getElementById('wbsGzdTestPdfResult').textContent='Error: '+e;});
}
</script>
<?php
        endif; // if ($gzdEnabled)
    }

    // ── Private utilities (duplicated from WooBankSync for self-containment) ──

    private function getConst($name, $default = '')
    {
        return !empty($this->conf->global->$name) ? $this->conf->global->$name : $default;
    }

    private function getJsonConst($name, $default = array())
    {
        $v = $this->getConst($name, '');
        if ($v === '') return $default;
        $decoded = @json_decode($v, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function sqlDateNow()
    {
        return "'" . $this->db->idate(dol_now()) . "'";
    }

    private function getTableColumns($table)
    {
        $cols  = array();
        $resql = $this->db->query("SHOW COLUMNS FROM $table");
        if (!$resql) return $cols;
        while ($obj = $this->db->fetch_object($resql)) $cols[] = (string) $obj->Field;
        return $cols;
    }

    private function addDataIfColumn(&$data, $fields, $key, $value, $numeric)
    {
        if (!in_array($key, $fields, true)) return;
        $data[$key] = $numeric ? (int) $value : (string) $value;
    }
}
