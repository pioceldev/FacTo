<?php
// Helper d'intégration FedaPay (API REST)
// https://docs.fedapay.com/api-reference/transactions/create

$config = $config ?? require __DIR__ . '/config.php';

function fedapayConfig()
{
    global $config;
    return $config['fedapay'] ?? [];
}

function fedapayEnabled()
{
    $c = fedapayConfig();
    return !empty($c['enabled']) && !empty($c['secret_key']);
}

function fedapayBaseUrl()
{
    $c = fedapayConfig();
    return ($c['environment'] ?? 'sandbox') === 'live'
        ? 'https://api.fedapay.com/v1'
        : 'https://sandbox-api.fedapay.com/v1';
}

function fedapayRequest(string $method, string $path, array $body = null)
{
    $c = fedapayConfig();
    $ch = curl_init(fedapayBaseUrl() . $path);
    $headers = [
        'Authorization: Bearer ' . ($c['secret_key'] ?? ''),
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $data = ['raw' => $raw, 'curl_error' => $error];
    }

    return ['code' => $code, 'data' => $data];
}

// Le prix des abonnements est désormais stocké directement en FCFA (XOF) :
// plus de conversion EUR -> XOF, on passe le montant tel quel (entier).
function fedapayAmount($xofAmount)
{
    return (int) round((float) $xofAmount);
}

// Crée une transaction FedaPay.
function fedapayCreateTransaction(int $amount, string $description, string $callbackUrl, array $customer = [])
{
    $c = fedapayConfig();
    $body = [
        'description' => $description,
        'amount' => $amount,
        'currency' => ['iso' => $c['currency'] ?? 'XOF'],
        'callback_url' => $callbackUrl,
    ];
    if (!empty($customer)) {
        $body['customer'] = $customer;
    }
    return fedapayRequest('POST', '/transactions', $body);
}

// Génère le lien de la page de paiement sécurisée FedaPay.
function fedapayGeneratePaymentUrl(int $transactionId)
{
    $res = fedapayRequest('POST', '/transactions/' . $transactionId . '/token');
    if (($res['code'] ?? 0) >= 400) {
        return null;
    }
    $data = $res['data'] ?? [];
    if (isset($data['token']['url'])) {
        return $data['token']['url'];
    }
    if (isset($data['url'])) {
        return $data['url'];
    }
    if (isset($data['payment_url'])) {
        return $data['payment_url'];
    }
    return null;
}

// Récupère le statut réel d'une transaction depuis l'API.
function fedapayGetTransaction(int $transactionId)
{
    return fedapayRequest('GET', '/transactions/' . $transactionId);
}
