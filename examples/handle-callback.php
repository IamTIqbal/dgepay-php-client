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
 *
 * SECURITY: Never trust the callback on its own. Always confirm it with
 * getTransactionStatus() (status AND amount) before crediting an order.
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
    // The callback CLAIMS success. It is not trusted until DGePay's API confirms it.
    $orderId = $result['unique_txn_id']; // Your original order ID

    // Load the amount YOU stored for this order when initiating the payment:
    //   SELECT amount FROM payments WHERE order_id = ? AND status = 'pending'
    $storedAmount = 100.00; // placeholder: replace with your DB lookup

    // REQUIRED: verify server-to-server with DGePay before crediting anything
    $statusCheck = $dgepay->getTransactionStatus($orderId);
    $verified    = is_array($statusCheck['data'] ?? null) ? $statusCheck['data'] : [];

    $isVerified = ($statusCheck['success'] ?? false) === true
        && DgePay::isSuccessStatus((string) ($verified['status_code'] ?? ''))
        && (string) ($verified['unique_txn_id'] ?? '') === (string) $orderId
        && is_numeric($verified['amount'] ?? null)
        && (int) round((float) $verified['amount'] * 100) === (int) round((float) $storedAmount * 100);

    if ($isVerified) {
        // Use ONLY the verified server data, never the callback params
        $txnNumber = (string) ($verified['txn_number'] ?? '');     // DGePay transaction number
        $method    = (string) ($verified['payment_method'] ?? ''); // "bKash", "Nagad", etc.

        //   UPDATE payments SET status = 'completed', trx_id = ?, gateway = ?
        //   WHERE order_id = ? AND status = 'pending'
        echo 'Payment successful! Transaction: ' . htmlspecialchars($txnNumber, ENT_QUOTES)
            . ', Method: ' . htmlspecialchars($method, ENT_QUOTES);
    } else {
        // Never mark completed here. Hold for review and log the details:
        //   UPDATE payments SET status = 'pending_review' WHERE order_id = ? AND status = 'pending'
        error_log('DGePay verification failed for order ' . $orderId . ': ' . json_encode([
            'success'       => $statusCheck['success'] ?? null,
            'message'       => $statusCheck['message'] ?? $verified['message'] ?? null,
            'status_code'   => $verified['status_code'] ?? null,
            'unique_txn_id' => $verified['unique_txn_id'] ?? null,
            'amount'        => $verified['amount'] ?? null,
        ]));
        echo "We couldn't confirm your payment. Please contact support with your order ID: "
            . htmlspecialchars($orderId, ENT_QUOTES);
    }

} elseif ($result['is_cancelled']) {
    // The callback says cancelled, but it is unverified too. Only close the order if
    // getTransactionStatus() confirms DgePay::isCancelledStatus(); otherwise leave it pending.
    //   UPDATE payments SET status = 'cancelled' WHERE order_id = ? AND status = 'pending'
    echo "Payment was cancelled.";

} else {
    // Payment not completed. Don't change the order based on this unverified callback;
    // leave it pending and reconcile later with getTransactionStatus().
    echo 'Payment failed: ' . htmlspecialchars($result['message'] ?: 'Unknown error', ENT_QUOTES);
}
