<?php

class DchStripeClient
{
    private $secretKey;
    private $accountId;
    public $error = '';

    public function __construct($secretKey, $accountId = '')
    {
        $this->secretKey = trim((string) $secretKey);
        $this->accountId = trim((string) $accountId);
    }

    public function getFee(array $references, $expectedCurrency = '')
    {
        if ($this->secretKey === '') {
            $this->error = 'Stripe secret key is not configured.';
            return false;
        }
        foreach (array_values(array_unique(array_filter(array_map('trim', $references)))) as $reference) {
            if (!preg_match('/^(ch|py|pi)_[A-Za-z0-9_]+$/', $reference)) continue;
            $charge = strpos($reference, 'pi_') === 0
                ? $this->paymentIntentCharge($reference)
                : $this->request('/v1/charges/' . rawurlencode($reference), array('expand[]' => 'balance_transaction'));
            if (!is_array($charge)) continue;
            $balance = $charge['balance_transaction'] ?? null;
            if (is_string($balance) && $balance !== '') $balance = $this->request('/v1/balance_transactions/' . rawurlencode($balance));
            if (!is_array($balance) || !isset($balance['fee'])) continue;
            $currency = strtolower((string) ($balance['currency'] ?? ''));
            if ($expectedCurrency !== '' && $currency !== '' && strtolower((string) $expectedCurrency) !== $currency) {
                $this->error = 'Stripe fee currency does not match the WooCommerce order currency.';
                return false;
            }
            return abs((float) $balance['fee']) / pow(10, $this->currencyExponent($currency));
        }
        if ($this->error === '') $this->error = 'No Stripe charge with an expanded balance transaction was found.';
        return false;
    }

    private function paymentIntentCharge($reference)
    {
        $intent = $this->request('/v1/payment_intents/' . rawurlencode($reference), array('expand[]' => 'latest_charge.balance_transaction'));
        if (!is_array($intent)) return false;
        $charge = $intent['latest_charge'] ?? null;
        if (is_string($charge) && $charge !== '') return $this->request('/v1/charges/' . rawurlencode($charge), array('expand[]' => 'balance_transaction'));
        return is_array($charge) ? $charge : false;
    }

    private function request($path, array $params = array())
    {
        $this->error = '';
        $url = 'https://api.stripe.com' . $path . (!empty($params) ? '?' . http_build_query($params) : '');
        $headers = array('Accept: application/json', 'Authorization: Bearer ' . $this->secretKey);
        if ($this->accountId !== '') $headers[] = 'Stripe-Account: ' . $this->accountId;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Commerce-Automation-Hub/3.0');
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $json = json_decode((string) $body, true);
        if ($body === false || $curlError !== '') $this->error = 'Stripe connection failed: ' . $curlError;
        elseif ($httpCode < 200 || $httpCode >= 300) $this->error = 'Stripe HTTP ' . $httpCode . ': ' . (string) ($json['error']['message'] ?? 'request failed');
        elseif (!is_array($json)) $this->error = 'Stripe returned invalid JSON.';
        return $this->error === '' ? $json : false;
    }

    private function currencyExponent($currency)
    {
        if (in_array($currency, array('bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'), true)) return 0;
        if (in_array($currency, array('bhd', 'jod', 'kwd', 'omr', 'tnd'), true)) return 3;
        return 2;
    }
}
