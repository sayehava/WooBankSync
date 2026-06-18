<?php

/**
 * WbsGermanizedClient
 *
 * Retrieves invoice number and PDF download URL from WooCommerce Germanized Pro
 * via the WooCommerce REST API and Germanized-specific document endpoints.
 *
 * Germanized Pro stores invoices as a separate post type (wc_gzd_document).
 * They are NOT available in the standard order meta_data. This client tries
 * the known Germanized document endpoints and falls back through multiple
 * response shapes across plugin versions.
 *
 * Known working endpoints (Germanized Pro 3.x / 4.x):
 *   GET /wp-json/wc/v3/orders/{id}/gzd-documents
 *
 * Document object shape:
 *   id, type ("invoice"), number, formatted_number, date, download_url, file_url
 */
class WbsGermanizedClient
{
    private $baseUrl;
    private $consumerKey;
    private $consumerSecret;
    public $error = '';
    public $lastDocumentEndpoint = '';

    private static $documentEndpoints = array(
        '/wp-json/wc/v3/orders/{id}/gzd-documents',
        '/wp-json/wc/v3/orders/{id}/documents',
        '/wp-json/wc/v1/orders/{id}/gzd-documents',
        '/wp-json/wc/v1/orders/{id}/documents',
    );

    private static $invoiceTypes = array('invoice', 'wc_gzd_invoice', 'wc_gzdp_invoice', 'simple_invoice');

    public function __construct($baseUrl, $consumerKey, $consumerSecret)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->consumerKey = (string) $consumerKey;
        $this->consumerSecret = (string) $consumerSecret;
    }

    /**
     * Return invoice number for an order.
     * Tries Germanized document endpoint first, then top-level order fields.
     * Pass $orderData if you already have the full single-order response to avoid
     * a redundant API call.
     */
    public function getInvoiceNumber($orderId, $orderData = null)
    {
        $docs = $this->getOrderDocuments($orderId);
        if ($docs !== false) {
            $number = $this->pickInvoiceField($docs, array('number', 'formatted_number', 'document_number', 'invoice_number'));
            if ($number !== '') return $number;
        }

        if ($orderData !== null) {
            $number = $this->extractFromOrderTopLevel($orderData, 'number');
            if ($number !== '') return $number;
        }

        return '';
    }

    /**
     * Return the PDF download URL for an order's invoice.
     * Pass $orderData if you already have the full single-order response.
     */
    public function getInvoicePdfUrl($orderId, $orderData = null)
    {
        $docs = $this->getOrderDocuments($orderId);
        if ($docs !== false) {
            $url = $this->pickInvoiceField($docs, array('download_url', 'file_url', 'file', 'pdf_url', 'url'));
            if ($url !== '') return $url;
        }

        if ($orderData !== null) {
            $url = $this->extractFromOrderTopLevel($orderData, 'url');
            if ($url !== '') return $url;
        }

        return '';
    }

    /**
     * Fetch Germanized documents for an order.
     * Tries all known endpoint paths, stops at the first that returns data.
     * Returns array of document objects or false when none found.
     */
    public function getOrderDocuments($orderId)
    {
        $this->lastDocumentEndpoint = '';
        foreach (self::$documentEndpoints as $pattern) {
            $path = str_replace('{id}', (int) $orderId, $pattern);
            $result = $this->request($path);
            if ($result !== false && is_array($result)) {
                // Some endpoints wrap results: {"documents": [...]}
                if (isset($result['documents'])) $result = $result['documents'];
                if (!empty($result)) {
                    $this->lastDocumentEndpoint = $path;
                    return $result;
                }
            }
        }
        return false;
    }

    /**
     * Fetch the full single-order response.
     * The list endpoint (/orders) may omit Germanized additions; the single-order
     * endpoint typically includes all registered REST fields.
     */
    public function getFullOrder($orderId)
    {
        return $this->request('/wp-json/wc/v3/orders/' . (int) $orderId);
    }

    /**
     * Diagnostic probe: returns a structured summary of what is available for a
     * given order — top-level non-standard keys, Germanized document endpoint
     * result, and full document objects. Feed the return value to the setup page
     * diagnostic modal.
     */
    public function probeOrder($orderId)
    {
        $result = array(
            'order_id' => (int) $orderId,
            'document_endpoint_tried' => self::$documentEndpoints,
            'document_endpoint_found' => null,
            'documents' => null,
            'invoice_number' => null,
            'invoice_pdf_url' => null,
            'order_non_standard_keys' => null,
            'order_non_standard_data' => null,
            'errors' => array(),
        );

        // 1. Documents endpoint
        $docs = $this->getOrderDocuments($orderId);
        if ($docs !== false) {
            $result['document_endpoint_found'] = $this->lastDocumentEndpoint;
            $result['documents'] = $docs;
            $result['invoice_number'] = $this->pickInvoiceField($docs, array('number', 'formatted_number', 'document_number'));
            $result['invoice_pdf_url'] = $this->pickInvoiceField($docs, array('download_url', 'file_url', 'file', 'pdf_url'));
        } else {
            $result['errors'][] = 'No Germanized document endpoint returned data. Error: ' . $this->error;
        }

        // 2. Full single-order response — check for non-standard top-level keys
        $order = $this->getFullOrder($orderId);
        if ($order !== false) {
            $standardKeys = array(
                'id','parent_id','number','order_key','created_via','version','status','currency',
                'date_created','date_created_gmt','date_modified','date_modified_gmt','discount_total',
                'discount_tax','shipping_total','shipping_tax','cart_tax','total','total_tax',
                'prices_include_tax','customer_id','customer_ip_address','customer_user_agent',
                'customer_note','billing','shipping','payment_method','payment_method_title',
                'transaction_id','date_paid','date_paid_gmt','date_completed','date_completed_gmt',
                'cart_hash','meta_data','line_items','tax_lines','shipping_lines','fee_lines',
                'coupon_lines','refunds','currency_symbol',
            );
            $extraKeys = array_values(array_diff(array_keys($order), $standardKeys));
            $result['order_non_standard_keys'] = $extraKeys;
            if (!empty($extraKeys)) {
                foreach ($extraKeys as $key) {
                    $result['order_non_standard_data'][$key] = $order[$key];
                }
                // Try to pull invoice number from top-level if documents failed
                if (empty($result['invoice_number'])) {
                    $result['invoice_number'] = $this->extractFromOrderTopLevel($order, 'number');
                }
                if (empty($result['invoice_pdf_url'])) {
                    $result['invoice_pdf_url'] = $this->extractFromOrderTopLevel($order, 'url');
                }
            }
        } else {
            $result['errors'][] = 'Full order fetch failed: ' . $this->error;
        }

        return $result;
    }

    // ─── private helpers ────────────────────────────────────────────────────

    /**
     * Walk a documents array, find the first invoice-type document, and return
     * the first non-empty value from the given field priority list.
     */
    private function pickInvoiceField(array $docs, array $fields)
    {
        // Accept flat array OR nested: {'invoice': {...}, ...}
        $list = isset($docs[0]) ? $docs : array_values($docs);
        foreach ($list as $doc) {
            if (!is_array($doc)) continue;
            $type = strtolower((string) ($doc['type'] ?? ''));
            if ($type !== '' && !in_array($type, self::$invoiceTypes, true)) continue;
            foreach ($fields as $field) {
                if (!empty($doc[$field])) return (string) $doc[$field];
            }
        }
        // If no type field present, try all documents
        foreach ($list as $doc) {
            if (!is_array($doc)) continue;
            if (!empty($doc['type'])) continue; // already checked above
            foreach ($fields as $field) {
                if (!empty($doc[$field])) return (string) $doc[$field];
            }
        }
        return '';
    }

    /**
     * Scan non-standard top-level order fields for invoice number / PDF URL.
     * $want is 'number' or 'url'.
     */
    private function extractFromOrderTopLevel(array $order, $want)
    {
        $numberKeys = array('invoice_number', 'invoice_no', 'document_number', 'formatted_number');
        $urlKeys = array('invoice_download_url', 'invoice_url', 'invoice_pdf', 'document_url', 'download_url');
        $keys = ($want === 'url') ? $urlKeys : $numberKeys;

        foreach ($keys as $key) {
            if (!empty($order[$key]) && is_scalar($order[$key])) return (string) $order[$key];
        }

        // Check if there is a top-level 'documents' or '_germanized' or 'germanized' object
        foreach (array('documents', '_germanized', 'germanized', 'invoice', 'gzd_documents') as $topKey) {
            if (empty($order[$topKey])) continue;
            $sub = is_array($order[$topKey]) ? $order[$topKey] : array();
            if (!empty($sub)) {
                // It might be a single document object or an array of documents
                $list = isset($sub[0]) ? $sub : array($sub);
                $result = $this->pickInvoiceField($list, ($want === 'url') ? $urlKeys : $numberKeys);
                if ($result !== '') return $result;
            }
        }

        return '';
    }

    private function request($path, $params = array())
    {
        $this->error = '';
        if (empty($this->baseUrl) || empty($this->consumerKey) || empty($this->consumerSecret)) {
            $this->error = 'API credentials missing.';
            return false;
        }

        $params['consumer_key'] = $this->consumerKey;
        $params['consumer_secret'] = $this->consumerSecret;
        $url = $this->baseUrl . $path . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
        curl_setopt($ch, CURLOPT_USERAGENT, 'Dolibarr WooBankSync/1.1');
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || !empty($curlError)) {
            $this->error = 'cURL error: ' . $curlError;
            return false;
        }
        $json = json_decode($body, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = is_array($json) && !empty($json['message']) ? $json['message'] : substr($body, 0, 200);
            $this->error = 'HTTP ' . $httpCode . ': ' . $msg;
            return false;
        }
        if (!is_array($json)) {
            $this->error = 'Invalid JSON response.';
            return false;
        }
        return $json;
    }
}
