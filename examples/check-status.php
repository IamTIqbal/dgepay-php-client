<?php

/**
 * DGePay PHP SDK — Check Transaction Status Example
 *
 * @developer Tamim Iqbal — IT Manager & AI Developer
 * @website   https://tamimiqbal.com
 *
 * Use this to manually verify a payment's status with the DGePay API.
 * Useful for reconciliation, admin panels, or webhook verification.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DgePay\DgePay;

$dgepay = new DgePay([
    'client_id'      => 'your_client_id_here',
    'client_secret'  => 'your_client_secret_here',
    'client_api_key' => 'your_api_key_here',
]);

// ─────────────────────────────────────────────────
// Check Transaction Status
// ─────────────────────────────────────────────────
$orderId = 'DG20260317112339128'; // Your unique_txn_id / order ID

$result = $dgepay->getTransactionStatus($orderId);

if ($result['success']) {
    $data = $result['data'];

    echo "Transaction Status:\n";
    echo "  Status Code:    {$data['status_code']}\n";
    echo "  Message:        {$data['message']}\n";
    echo "  TXN Number:     {$data['txn_number']}\n";
    echo "  Payment Method: {$data['payment_method']}\n";
    echo "  Amount:         {$data['amount']}\n";
    echo "  Order ID:       {$data['unique_txn_id']}\n";
    echo "  3rd Party TXN:  {$data['third_party_txn_number']}\n";
    echo "\n";

    // Check result using constants
    if (DgePay::isSuccessStatus((string) $data['status_code'])) {
        echo "✓ PAYMENT SUCCESSFUL\n";
    } elseif (DgePay::isCancelledStatus((string) $data['status_code'])) {
        echo "✗ PAYMENT CANCELLED\n";
    } else {
        echo "? UNKNOWN STATUS: {$data['status_code']}\n";
    }

    // Access metadata
    if (! empty($data['metadata'])) {
        echo "\nMetadata:\n";
        foreach ($data['metadata'] as $key => $value) {
            echo "  {$key}: {$value}\n";
        }
    }
} else {
    echo "Failed to check status: {$result['message']}\n";
}
