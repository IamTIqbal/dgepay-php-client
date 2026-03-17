# DGePay PHP Client

⚠️ Disclaimer  
This is an unofficial community-driven integration for DGePay.  
This project is not affiliated with or endorsed by DGePay or Bangladesh Bank.

> **Developer:** [Tamim Iqbal](https://tamimiqbal.com) — IT Manager & AI Developer

A complete PHP SDK for the **DGePay Payment Gateway API** (Bangladesh). Supports **bKash**, **Nagad**, and other MFS (Mobile Financial Service) providers through the DGePay payment aggregator.

Built from real-world production integration, with all the undocumented gotchas already handled.

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Laravel Integration](#laravel-integration)
- [API Reference](#api-reference)
  - [Authentication](#authentication)
  - [Initiate Payment](#initiate-payment)
  - [Handle Callback](#handle-callback)
  - [Check Transaction Status](#check-transaction-status)
  - [Utilities](#utilities)
- [Payment Flow](#payment-flow)
- [Status Codes](#status-codes)
- [Gotchas & Troubleshooting](#gotchas--troubleshooting)
- [Security Notes](#security-notes)
- [Testing](#testing)
- [License](#license)

---

## Features

- **Complete API coverage** — Authentication, payment initiation, callback handling, and transaction status checks
- **AES-128-ECB encryption** — Automatic payload encryption/decryption as required by DGePay
- **HMAC-SHA256 signatures** — Correct signature generation matching DGePay API documentation v1.9
- **Callback decryption** — Handles encrypted callback data with base64 `+` character fix
- **Laravel integration** — Service provider, facade, and config publishing out of the box
- **Framework-agnostic** — Works with any PHP 8.1+ project (uses cURL internally)
- **Production-tested** — Built from a live integration, with all edge cases and undocumented behaviors handled

---

## Requirements

- PHP 8.1 or higher
- `ext-curl` — For HTTP requests
- `ext-json` — For JSON encoding/decoding
- `ext-openssl` — For AES encryption/decryption

---

## Installation

### Via Composer

```bash
composer require tamimiqbal/dgepay-php
```

### Manual Installation

Clone or download the repository, then include the autoloader:

```php
require_once 'path/to/dgepay-api/src/DgePay.php';
```

---

## Quick Start

```php
use DgePay\DgePay;

// Initialize
$dgepay = new DgePay([
    'client_id'      => 'your_client_id',
    'client_secret'  => 'your_client_secret',
    'client_api_key' => 'your_api_key',
]);

// Create a payment
$orderId = DgePay::generateTransactionId(); // "DG20260317112339128"

$result = $dgepay->initiatePayment([
    'amount'      => 2499.00,
    'description' => 'Pro Plan - 1 Year',
    'orderId'     => $orderId,
    'redirectUrl' => 'https://yoursite.com/payment/callback',
]);

if ($result['success']) {
    // Redirect user to DGePay payment page
    header('Location: ' . $result['payment_url']);
    exit;
}
```

---

## Laravel Integration

### Auto-Discovery

If you're using Laravel 5.5+, the service provider and facade are auto-discovered.

### Manual Registration

Add to `config/app.php`:

```php
'providers' => [
    DgePay\Laravel\DgePayServiceProvider::class,
],

'aliases' => [
    'DgePay' => DgePay\Laravel\Facades\DgePay::class,
],
```

### Publish Config

```bash
php artisan vendor:publish --tag=dgepay-config
```

### Environment Variables

Add to your `.env` file:

```env
DGEPAY_CLIENT_ID=your_client_id
DGEPAY_CLIENT_SECRET=your_client_secret
DGEPAY_CLIENT_API_KEY=your_api_key
DGEPAY_BASE_URL=https://apiv2.dgepay.net/dipon/v3
```

### Usage in Controllers

```php
use DgePay\DgePay;

class PaymentController extends Controller
{
    public function __construct(
        protected DgePay $dgepay,
    ) {}

    public function initiate(Request $request)
    {
        $result = $this->dgepay->initiatePayment([
            'amount'      => 2499.00,
            'description' => 'Pro Plan',
            'orderId'     => DgePay::generateTransactionId(),
            'redirectUrl' => route('payment.callback'),
        ]);

        if ($result['success']) {
            return redirect()->away($result['payment_url']);
        }

        return back()->with('error', $result['message']);
    }
}
```

---

## API Reference

### Authentication

```php
$auth = $dgepay->authenticate();

if ($auth['success']) {
    echo $auth['access_token']; // JWT token
}
```

**How it works:**

- Sends HTTP Basic Auth header: `base64(client_id:client_secret)`
- POST body contains `client_id` and `client_secret`
- Returns a JWT access token used in subsequent requests

> **Note:** You don't need to call `authenticate()` manually. It's called automatically by `initiatePayment()` and `getTransactionStatus()`.

---

### Initiate Payment

```php
$result = $dgepay->initiatePayment([
    // Required
    'amount'      => 2499.00,          // Payment amount (BDT)
    'redirectUrl' => 'https://...',     // Where to redirect after payment
    'orderId'     => 'DG20260317...',   // Your unique transaction ID

    // Optional
    'description'           => 'Plan subscription',
    'payment_method'        => null,    // Force: "bKash", "Nagad", etc.
    'customer_token'        => null,    // Returning customer token
    'payee_information'     => null,    // Payee info
    'unique_user_reference' => '123',   // Your user ID
    'meta_data'             => [        // Up to 3 custom fields
        'custom_field_1' => 'pro',
        'custom_field_2' => 'user@example.com',
        'custom_field_3' => 'COUPON50',
    ],
]);
```

**Returns on success:**

```php
[
    'success'        => true,
    'payment_url'    => 'https://checkout.dgepay.net/payment-methods?data=...',
    'transaction_id' => 'DG20260317112339128',
]
```

**Returns on failure:**

```php
[
    'success' => false,
    'message' => 'Error description',
]
```

---

### Handle Callback

After payment, DGePay redirects the user to your `redirectUrl` with encrypted data:

```
https://yoursite.com/callback?data=<AES-encrypted-base64>
```

⚠️ **CRITICAL**: PHP converts `+` to spaces in query strings. You MUST fix this before decryption.

```php
// Step 1: Get and fix the raw data
$rawData = str_replace(' ', '+', $_GET['data']);

// Step 2: Decrypt
$decrypted = $dgepay->decryptCallbackData($rawData);

// Step 3: Parse the result
$result = $dgepay->parseCallbackResult($decrypted ?? $_GET);

if ($result['is_success']) {
    // Payment successful!
    $orderId   = $result['unique_txn_id'];  // Your order ID
    $txnNumber = $result['txn_number'];     // DGePay txn number
    $method    = $result['payment_method']; // "bKash", "Nagad", etc.

    // RECOMMENDED: Verify with API before activating
    $status = $dgepay->getTransactionStatus($orderId);
    if ($status['success'] && $status['data']['status_code'] == 3) {
        // Verified! Activate the order.
    }
} elseif ($result['is_cancelled']) {
    // User cancelled
} else {
    // Payment failed
}
```

**Decrypted callback data structure:**

```php
[
    'status_code'            => 3,                           // 3 = success, 8 = cancelled
    'customer_token'         => null,
    'unique_txn_id'          => 'DG20260317112339128',       // Your order ID
    'payment_method'         => 'bKash',
    'txn_number'             => '46629357',                  // DGePay txn number
    'third_party_txn_number' => 'TR0011ITaWLiz1773746629790', // MFS provider txn
    'message'                => 'TRANSACTION SUCCESS',
    'txn_id'                 => '42836aac54c4...',           // DGePay internal ID
    'amount'                 => 100,
    'created_date'           => 1773746629,                  // Unix timestamp
    'metadata'               => [
        'custom_field_1' => 'pro',
        'custom_field_2' => 'user@example.com',
        'custom_field_3' => 'COUPON50',
    ],
]
```

---

### Check Transaction Status

```php
$result = $dgepay->getTransactionStatus('DG20260317112339128');

if ($result['success']) {
    $data = $result['data'];

    echo $data['status_code'];     // "3" = success
    echo $data['message'];         // "TRANSACTION SUCCESS"
    echo $data['txn_number'];      // Gateway transaction number
    echo $data['payment_method'];  // "bKash", "Nagad", etc.
    echo $data['amount'];          // Payment amount
}
```

---

### Utilities

#### Generate Transaction ID

```php
$txnId = DgePay::generateTransactionId();        // "DG20260317112339128"
$txnId = DgePay::generateTransactionId('PAY');    // "PAY20260317112339128"
```

#### Status Constants

```php
DgePay::STATUS_SUCCESS;    // "3"
DgePay::STATUS_CANCELLED;  // "8"

DgePay::isSuccessStatus('3');    // true
DgePay::isCancelledStatus('8');  // true
```

#### Encrypt/Decrypt Payloads

```php
$encrypted = $dgepay->encryptPayload(['key' => 'value']);
$decrypted = $dgepay->decryptPayload($encrypted);
```

#### Signature Generation

```php
$signature = $dgepay->generateSignature($data);
```

#### Custom Logger

```php
$dgepay->setLogger(function (string $level, string $message, array $context) {
    error_log("[{$level}] {$message}: " . json_encode($context));
});
```

---

## Payment Flow

```
┌──────────┐       ┌──────────┐       ┌──────────┐       ┌──────────┐
│  Your    │       │  DGePay  │       │  MFS     │       │  Your    │
│  Server  │       │  API     │       │ Provider │       │  Server  │
└────┬─────┘       └────┬─────┘       └────┬─────┘       └────┬─────┘
     │                  │                  │                   │
     │  1. authenticate │                  │                   │
     │─────────────────>│                  │                   │
     │  JWT token       │                  │                   │
     │<─────────────────│                  │                   │
     │                  │                  │                   │
     │  2. initiate     │                  │                   │
     │     payment      │                  │                   │
     │─────────────────>│                  │                   │
     │  webview_url     │                  │                   │
     │<─────────────────│                  │                   │
     │                  │                  │                   │
     │  3. Redirect user to webview_url    │                   │
     │─────────────────────────────────────>                   │
     │                  │   User pays via  │                   │
     │                  │   bKash/Nagad    │                   │
     │                  │<─────────────────│                   │
     │                  │                  │                   │
     │  4. Redirect to redirect_url?data=<encrypted>          │
     │<────────────────────────────────────────────────────────│
     │                  │                  │                   │
     │  5. Decrypt callback data           │                   │
     │  6. Verify via getTransactionStatus │                   │
     │─────────────────>│                  │                   │
     │  status_code: 3  │                  │                   │
     │<─────────────────│                  │                   │
     │                  │                  │                   │
     │  7. Activate order                  │                   │
     ▼                  ▼                  ▼                   ▼
```

**Authentication → Initiate Payment → User Pays → Callback → Verify → Activate**

---

## Status Codes

| Code | Constant           | Meaning                            |
| ---- | ------------------ | ---------------------------------- |
| `3`  | `STATUS_SUCCESS`   | Transaction completed successfully |
| `8`  | `STATUS_CANCELLED` | Transaction cancelled by user      |

---

## Gotchas & Troubleshooting

These are real issues encountered during production integration that are **not documented** in the official DGePay API docs.

### 1. Request Body Must Be AES Encrypted

The API requires all POST request bodies to be encrypted with **AES-128-ECB** using your `client_secret` as the key. Sending plain JSON will return:

```
REQUESTED_PARAMETERS_ARE_MISMATCHED
```

This SDK handles encryption automatically.

### 2. PHP Converts `+` to Spaces in Query Strings

DGePay sends encrypted callback data as a base64-encoded query parameter:

```
?data=abc+def/ghi==
```

PHP's `$_GET` and Laravel's `$request->query()` automatically convert `+` to spaces, breaking the base64 decoding. **You must restore `+` before decryption:**

```php
$rawData = str_replace(' ', '+', $_GET['data']);
```

This SDK's examples and documentation always include this fix.

### 3. Callback Uses `status_code`, Not `status`

The encrypted callback data uses `status_code` (integer: `3` for success, `8` for cancelled), **not** `status`. The `parseCallbackResult()` method handles both cases.

### 4. Signature — Numbers Must Be Formatted as Floats

Integer values (like `amount: 15`) must be formatted as `"15.0"` in the signature string. This SDK handles this automatically.

⚠️ **Important**: String values that look like numbers (phone numbers like `"+8801712345678"`) must NOT be converted to floats. This SDK uses `is_int() || is_float()` type checks (not `is_numeric()`) to handle this correctly.

### 5. Signature — Nested Object Key Handling

For nested objects like `meta_data`, the parent key is printed once, then children are recursively flattened:

```
meta_datacustom_field_1value1custom_field_2value2custom_field_3value3
```

Not:

```
meta_data_custom_field_1value1...  (WRONG — don't prefix children)
```

### 6. Authentication Uses Both Basic Auth AND Body Params

The `/authenticate` endpoint requires:

- `Authorization: Basic base64(client_id:client_secret)` header
- `client_id` and `client_secret` in the POST body

Both must be present.

### 7. LOG_LEVEL May Suppress Your Logs

If your Laravel app has `LOG_LEVEL=warning` (common in production), `Log::info()` calls will be silently suppressed. Use `Log::warning()` for important payment debugging.

### 8. The JS SDK Has Wrong Endpoints

DGePay's JavaScript SDK references endpoints like `/payment/initiate` which return 404. The correct API v3 endpoints are:

| Endpoint         | Path                                        |
| ---------------- | ------------------------------------------- |
| Authenticate     | `/payment_gateway/authenticate`             |
| Initiate Payment | `/payment_gateway/initiate_payment`         |
| Check Status     | `/payment_gateway/check_transaction_status` |

---

## Security Notes

- **Always verify callbacks server-side** — Don't trust the callback data alone. Call `getTransactionStatus()` to verify with DGePay's API before activating orders.
- **AES-128-ECB limitation** — ECB mode doesn't use an IV and identical plaintext blocks produce identical ciphertext. This is DGePay's requirement, not a choice. Don't reuse this pattern in your own systems.
- **Protect your credentials** — Never commit `client_id`, `client_secret`, or `client_api_key` to version control. Use environment variables.

---

## Testing

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit
```

Tests cover:

- Signature generation (verified against official documentation example)
- Numeric string handling (phone numbers not converted to floats)
- Integer/float normalization
- Callback parsing (success, cancelled, plain query params, empty)
- AES encryption/decryption roundtrip
- Transaction ID generation
- Status code constants

---

## Project Structure

```
dgepay-api/
├── src/
│   ├── DgePay.php                      # Main SDK class
│   └── Laravel/
│       ├── DgePayServiceProvider.php    # Laravel service provider
│       └── Facades/
│           └── DgePay.php              # Laravel facade
├── config/
│   └── dgepay.php                      # Configuration file
├── examples/
│   ├── initiate-payment.php            # Plain PHP: initiate payment
│   ├── handle-callback.php             # Plain PHP: handle callback
│   ├── check-status.php                # Plain PHP: check transaction status
│   └── laravel-controller.php          # Laravel: complete controller example
├── tests/
│   ├── SignatureTest.php               # Signature generation tests
│   └── CallbackTest.php                # Callback handling tests
├── composer.json
├── phpunit.xml
├── LICENSE
├── .gitignore
└── README.md
```

---

## Contributing

1. Fork the repository
2. Create your feature branch: `git checkout -b feature/my-feature`
3. Commit your changes: `git commit -am 'Add my feature'`
4. Push to the branch: `git push origin feature/my-feature`
5. Submit a pull request

---

## License

This project is licensed under the MIT License — see the [LICENSE](LICENSE) file for details.

---

## Credits

- **Developer:** [Tamim Iqbal](https://tamimiqbal.com) — IT Manager & AI Developer
- **Website:** [tamimiqbal.com](https://tamimiqbal.com)
- **DGePay API Documentation:** v1.9
- Built with real-world production testing against DGePay's live API
