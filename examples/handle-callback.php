<?php

/**
 * DGePay PHP SDK — Callback Handler Example
 *
 * @developer Tamim Iqbal — IT Manager & AI Developer
 * @website   https://tamimiqbal.com
 *
 * After payment, DGePay redirects the user to your redirect_url with
 * encrypted callback data in the query string: ?data=<AES-encrypted-base64>
 *
 * CRITICAL: PHP's query string parsing converts '+' to spaces.
 * You must restore '+' before decryption.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DgePay\DgePay;

$dgepay = new DgePay([
    'client_id'      => 'your_client_id_here',
    'client_secret'  => 'your_client_secret_here',
    'client_api_key' => 'your_api_key_here',
]);

// ─────────────────────────────────────────────────
// Step 2: Handle the Callback
// ─────────────────────────────────────────────────

$callbackParams = $_GET;

if (isset($_GET['data'])) {
    // CRITICAL: Restore '+' characters that PHP converted to spaces
    $rawData = str_replace(' ', '+', $_GET['data']);

    $decrypted = $dgepay->decryptCallbackData($rawData);

    if ($decrypted !== null) {
        $callbackParams = $decrypted;
    }
}

// Parse the result
$result = $dgepay->parseCallbackResult($callbackParams);

if ($result['is_success']) {
    // Payment successful!
    $orderId    = $result['unique_txn_id']; // Your original order ID
    $txnNumber  = $result['txn_number'];    // DGePay transaction number
    $method     = $result['payment_method']; // "bKash", "Nagad", etc.

    // Verify with DGePay API (recommended for security)
    $statusCheck = $dgepay->getTransactionStatus($orderId);

    if ($statusCheck['success'] && ($statusCheck['data']['status_code'] ?? '') == '3') {
        // Double-verified! Update your database:
        //   UPDATE payments SET status = 'completed', trx_id = ? WHERE order_id = ?
        echo "Payment successful! Transaction: {$txnNumber}, Method: {$method}";
    } else {
        echo "Payment verification failed. Contact support.";
    }

} elseif ($result['is_cancelled']) {
    // User cancelled the payment
    //   UPDATE payments SET status = 'cancelled' WHERE order_id = ?
    echo "Payment was cancelled.";

} else {
    // Payment failed
    //   UPDATE payments SET status = 'failed' WHERE order_id = ?
    echo "Payment failed: " . ($result['message'] ?: 'Unknown error');
}
