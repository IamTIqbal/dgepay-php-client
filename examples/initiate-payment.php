<?php

/**
 * DGePay PHP SDK — Initiate Payment Example
 *
 * @developer Tamim Iqbal — IT Manager & AI Developer
 * @website   https://tamimiqbal.com
 *
 * This example shows how to use the DGePay SDK without any framework.
 * It demonstrates the complete payment flow:
 *   1. Initiate a payment → redirect user to DGePay
 *   2. Handle the callback → decrypt & verify
 *   3. Check transaction status
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DgePay\DgePay;

// ─────────────────────────────────────────────────
// Configuration
// ─────────────────────────────────────────────────
$dgepay = new DgePay([
    'client_id'      => 'your_client_id_here',
    'client_secret'  => 'your_client_secret_here',
    'client_api_key' => 'your_api_key_here',
    'base_url'       => 'https://apiv2.dgepay.net/dipon/v3',
]);

// Optional: Set a logger
$dgepay->setLogger(function (string $level, string $message, array $context) {
    echo "[{$level}] {$message}: " . json_encode($context) . "\n";
});

// ─────────────────────────────────────────────────
// Step 1: Initiate a Payment
// ─────────────────────────────────────────────────
$orderId = DgePay::generateTransactionId(); // e.g. "DG20260317112339128"

$result = $dgepay->initiatePayment([
    'amount'      => 100.00,
    'description' => 'Premium Plan - 1 Year',
    'orderId'     => $orderId,
    'redirectUrl'  => 'https://yoursite.com/payment/callback',

    // Optional fields
    'unique_user_reference' => 'user_123',
    'meta_data' => [
        'custom_field_1' => 'premium',
        'custom_field_2' => 'user@example.com',
        'custom_field_3' => 'COUPON50',
    ],
]);

if ($result['success']) {
    // Save $orderId to your database with 'pending' status
    // Then redirect the user:
    header('Location: ' . $result['payment_url']);
    exit;
} else {
    echo "Payment initiation failed: " . $result['message'];
}
