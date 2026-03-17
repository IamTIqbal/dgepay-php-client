<?php

/**
 * DGePay PHP SDK
 *
 * @developer Tamim Iqbal — IT Manager & AI Developer
 * @website   https://tamimiqbal.com
 * @license   MIT
 */

namespace DgePay;

/**
 * DgePay Payment Gateway SDK for PHP
 *
 * Handles authentication, payment initiation, transaction status checks,
 * signature generation, AES-128-ECB payload encryption, and callback decryption
 * for the DGePay Payment Gateway API v3.
 *
 * @see https://apiv2.dgepay.net/dipon/v3
 */
class DgePay
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $apiKey;
    protected string $baseUrl;

    /** @var callable|null Custom logger callback: function(string $level, string $message, array $context) */
    protected $logger = null;

    /**
     * @param array $config {
     *     @type string $client_id      Your DGePay client ID
     *     @type string $client_secret  Your DGePay client secret (also used as AES key)
     *     @type string $client_api_key Your DGePay API key (used for HMAC signature)
     *     @type string $base_url       API base URL (default: https://apiv2.dgepay.net/dipon/v3)
     * }
     */
    public function __construct(array $config)
    {
        $this->clientId     = $config['client_id'] ?? '';
        $this->clientSecret = $config['client_secret'] ?? '';
        $this->apiKey       = $config['client_api_key'] ?? '';
        $this->baseUrl      = rtrim($config['base_url'] ?? 'https://apiv2.dgepay.net/dipon/v3', '/');

        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->apiKey)) {
            throw new \InvalidArgumentException('DGePay: client_id, client_secret, and client_api_key are required.');
        }
    }

    /**
     * Set a custom logger callback.
     *
     * @param callable $logger function(string $level, string $message, array $context): void
     */
    public function setLogger(callable $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    // ─────────────────────────────────────────────────────────────
    // Authentication
    // POST /payment_gateway/authenticate
    // ─────────────────────────────────────────────────────────────

    /**
     * Authenticate with DGePay and obtain a JWT access token.
     *
     * Uses HTTP Basic Auth (base64 of client_id:client_secret) and sends
     * client_id + client_secret in the POST body.
     *
     * @return array{success: bool, access_token?: string, message?: string}
     */
    public function authenticate(): array
    {
        try {
            $basicAuth = base64_encode($this->clientId . ':' . $this->clientSecret);

            $response = $this->httpPost(
                $this->baseUrl . '/payment_gateway/authenticate',
                json_encode([
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]),
                [
                    'Authorization: Basic ' . $basicAuth,
                    'Content-Type: application/json',
                ]
            );

            $data = json_decode($response['body'], true) ?? [];

            if ($response['http_code'] < 200 || $response['http_code'] >= 300 || empty($data['data']['access_token'])) {
                $this->log('warning', 'Authentication failed', ['status' => $response['http_code'], 'body' => $data]);
                return ['success' => false, 'message' => $data['error'][0] ?? 'DGePay authentication failed.'];
            }

            return [
                'success'      => true,
                'access_token' => $data['data']['access_token'],
            ];
        } catch (\Throwable $e) {
            $this->log('error', 'Authentication exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not connect to DGePay: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Initiate Payment
    // POST /payment_gateway/initiate_payment
    // ─────────────────────────────────────────────────────────────

    /**
     * Initiate a payment with DGePay.
     *
     * Authenticates, builds payload, generates HMAC signature, encrypts body
     * with AES-128-ECB, and sends the request.
     *
     * @param array $paymentData {
     *     @type float  $amount                Required. Payment amount.
     *     @type string $redirectUrl           Required. URL to redirect after payment.
     *     @type string $orderId               Required. Your unique transaction/order ID.
     *     @type string $description           Optional. Payment note/description.
     *     @type string $payment_method        Optional. Force a specific method (bKash, Nagad, etc.).
     *     @type string $customer_token        Optional. Returning customer token.
     *     @type string $payee_information     Optional. Payee info.
     *     @type string $unique_user_reference Optional. Unique user identifier.
     *     @type array  $meta_data             Optional. Custom fields (custom_field_1..3).
     * }
     *
     * @return array{success: bool, payment_url?: string, transaction_id?: string, message?: string}
     */
    public function initiatePayment(array $paymentData): array
    {
        try {
            $auth = $this->authenticate();
            if (! $auth['success']) {
                return $auth;
            }

            $payload = [
                'amount'                => (float) ($paymentData['amount'] ?? 0),
                'customer_token'        => $paymentData['customer_token'] ?? null,
                'note'                  => $paymentData['description'] ?? 'Payment',
                'payee_information'     => $paymentData['payee_information'] ?? null,
                'payment_method'        => $paymentData['payment_method'] ?? null,
                'redirect_url'          => $paymentData['redirectUrl'],
                'unique_txn_id'         => $paymentData['orderId'],
                'meta_data'             => $paymentData['meta_data'] ?? null,
                'unique_user_reference' => $paymentData['unique_user_reference'] ?? null,
            ];

            $signature     = $this->generateSignature($payload);
            $encryptedBody = $this->encryptPayload($payload);

            $response = $this->httpPost(
                $this->baseUrl . '/payment_gateway/initiate_payment',
                $encryptedBody,
                [
                    'Authorization: Bearer ' . $auth['access_token'],
                    'Signature: ' . $signature,
                    'Content-Type: application/json',
                ]
            );

            $data = json_decode($response['body'], true) ?? [];

            if ($response['http_code'] < 200 || $response['http_code'] >= 300 || empty($data['data']['webview_url'])) {
                $this->log('warning', 'Initiate payment failed', ['status' => $response['http_code'], 'body' => $data]);
                return [
                    'success' => false,
                    'message' => $data['error'][0] ?? $data['message'] ?? 'Failed to initiate DGePay payment.',
                ];
            }

            return [
                'success'        => true,
                'payment_url'    => $data['data']['webview_url'],
                'transaction_id' => $data['data']['unique_txn_id'] ?? $paymentData['orderId'],
            ];
        } catch (\Throwable $e) {
            $this->log('error', 'Initiate payment exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not connect to DGePay: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Check Transaction Status
    // POST /payment_gateway/check_transaction_status
    // ─────────────────────────────────────────────────────────────

    /**
     * Check the status of a transaction.
     *
     * @param string $uniqueTxnId The unique_txn_id used when initiating payment.
     *
     * @return array{success: bool, data?: array, message?: string}
     *
     * Response data on success:
     *   - status_code: 3 = success, 8 = cancelled
     *   - message: "TRANSACTION SUCCESS" or "TRANSACTION CANCELLED"
     *   - txn_number: Gateway transaction number
     *   - payment_method: "bKash", "Nagad", etc.
     *   - amount: Payment amount
     *   - unique_txn_id: Your original order ID
     *   - third_party_txn_number: MFS provider transaction ID
     *   - metadata: Your custom fields
     */
    public function getTransactionStatus(string $uniqueTxnId): array
    {
        try {
            $auth = $this->authenticate();
            if (! $auth['success']) {
                return $auth;
            }

            $payload       = ['unique_txn_id' => $uniqueTxnId];
            $signature     = $this->generateSignature($payload);
            $encryptedBody = $this->encryptPayload($payload);

            $response = $this->httpPost(
                $this->baseUrl . '/payment_gateway/check_transaction_status',
                $encryptedBody,
                [
                    'Authorization: Bearer ' . $auth['access_token'],
                    'Signature: ' . $signature,
                    'Content-Type: application/json',
                ]
            );

            $data = json_decode($response['body'], true) ?? [];

            if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
                $this->log('warning', 'Status check failed', ['unique_txn_id' => $uniqueTxnId, 'body' => $data]);
                return [
                    'success' => false,
                    'message' => $data['message'] ?? 'Failed to check payment status.',
                ];
            }

            return ['success' => true, 'data' => $data['data'] ?? $data];
        } catch (\Throwable $e) {
            $this->log('error', 'Status check exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not connect to DGePay: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Callback Handling
    // ─────────────────────────────────────────────────────────────

    /**
     * Decrypt the encrypted callback data from DGePay.
     *
     * DGePay redirects to your redirect_url with ?data=<AES-encrypted-base64>.
     *
     * IMPORTANT: PHP's query string parsing converts '+' to spaces.
     * You MUST restore '+' characters before calling this method:
     *
     *     $rawData = str_replace(' ', '+', $_GET['data']);
     *     $result  = $dgepay->decryptCallbackData($rawData);
     *
     * @param string $encryptedData The base64-encoded AES-encrypted callback data.
     *
     * @return array|null Decoded callback data or null on failure.
     *
     * Callback data structure:
     *   - status_code:  3 = success, 8 = cancelled
     *   - message:      "TRANSACTION SUCCESS" or "TRANSACTION CANCELLED"
     *   - unique_txn_id: Your original order ID
     *   - txn_number:   Gateway transaction number
     *   - txn_id:       DGePay internal transaction ID
     *   - payment_method: "bKash", "Nagad", etc.
     *   - amount:       Payment amount
     *   - created_date: Unix timestamp
     *   - third_party_txn_number: MFS provider transaction ID
     *   - customer_token: Returning customer token (if applicable)
     *   - metadata:     Your custom fields {custom_field_1, custom_field_2, custom_field_3}
     */
    public function decryptCallbackData(string $encryptedData): ?array
    {
        try {
            // Try 1: input is base64 — let openssl_decrypt handle base64 decoding
            $decrypted = openssl_decrypt($encryptedData, 'AES-128-ECB', $this->clientSecret, 0);

            // Try 2: manually base64-decode first, then decrypt raw
            if ($decrypted === false) {
                $decoded = base64_decode($encryptedData, true);
                if ($decoded !== false) {
                    $decrypted = openssl_decrypt($decoded, 'AES-128-ECB', $this->clientSecret, OPENSSL_RAW_DATA);
                }
            }

            if ($decrypted === false || $decrypted === null) {
                $this->log('warning', 'Callback decryption failed', [
                    'data_length'  => strlen($encryptedData),
                    'data_preview' => substr($encryptedData, 0, 80),
                ]);
                return null;
            }

            $parsed = json_decode($decrypted, true);
            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            $this->log('warning', 'Callback decryption exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Parse callback data and determine the payment outcome.
     *
     * Handles both encrypted (?data=...) and plain query-param callbacks.
     *
     * @param array $params The callback parameters (decrypted or raw query params).
     *
     * @return array{
     *     is_success: bool,
     *     is_cancelled: bool,
     *     unique_txn_id: string,
     *     txn_number: string,
     *     message: string,
     *     status_code: string,
     *     payment_method: string,
     *     amount: mixed,
     *     metadata: array
     * }
     */
    public function parseCallbackResult(array $params): array
    {
        $statusCode = (string) ($params['status_code'] ?? '');
        $status     = (string) ($params['status'] ?? '');
        $message    = (string) ($params['message'] ?? '');

        $isSuccess = ($params['unique_txn_id'] ?? '') !== '' && (
            $statusCode === '3'
            || in_array($status, ['1', 'success', 'completed', 'COMPLETED'], true)
        );

        $isCancelled = $statusCode === '8'
            || in_array($status, ['cancel', 'cancelled'], true)
            || stripos($message, 'cancel') !== false;

        return [
            'is_success'     => $isSuccess,
            'is_cancelled'   => $isCancelled,
            'unique_txn_id'  => (string) ($params['unique_txn_id'] ?? ''),
            'txn_number'     => (string) ($params['txn_number'] ?? ''),
            'txn_id'         => (string) ($params['txn_id'] ?? ''),
            'message'        => $message,
            'status_code'    => $statusCode,
            'payment_method' => (string) ($params['payment_method'] ?? ''),
            'amount'         => $params['amount'] ?? null,
            'metadata'       => $params['metadata'] ?? [],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Signature Generation (DGePay API Doc v1.9)
    // ─────────────────────────────────────────────────────────────

    /**
     * Generate HMAC-SHA256 signature for API requests.
     *
     * Algorithm:
     * 1. Sort all parameters alphabetically by key
     * 2. Flatten nested objects (parent key printed once, then children recurse)
     * 3. Concatenate all key+value pairs into a single string
     * 4. Strip characters: { } " : spaces commas
     * 5. HMAC-SHA256 with the API key
     * 6. Base64-encode the result
     *
     * Number handling:
     * - Integers and floats are formatted as "X.0" (one decimal place)
     * - String values that look like numbers ("+88...", phone numbers) are NOT converted
     *
     * @param array $data The payload parameters.
     * @return string Base64-encoded HMAC-SHA256 signature.
     */
    public function generateSignature(array $data): string
    {
        $flatString = $this->flattenParams($data);
        $flatString = str_replace(['{', '}', '"', ':', ' ', ','], '', $flatString);

        $hmac = hash_hmac('sha256', $flatString, $this->apiKey, true);
        return base64_encode($hmac);
    }

    /**
     * Recursively flatten params into a concatenated string for signature.
     */
    protected function flattenParams(array $data): string
    {
        ksort($data);

        $result = '';
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result .= $key . $this->flattenParams($value);
            } else {
                if (is_null($value)) {
                    $strValue = 'null';
                } elseif (is_bool($value)) {
                    $strValue = $value ? 'true' : 'false';
                } elseif (is_int($value) || is_float($value)) {
                    $strValue = number_format((float) $value, 1, '.', '');
                } else {
                    $strValue = (string) $value;
                }
                $result .= $key . $strValue;
            }
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────
    // AES-128-ECB Encryption
    // ─────────────────────────────────────────────────────────────

    /**
     * Encrypt a payload with AES-128-ECB using the client secret.
     *
     * The DGePay API requires all request bodies to be encrypted.
     * The encrypted output is base64-encoded.
     *
     * @param array $data The payload to encrypt.
     * @return string Base64-encoded encrypted payload.
     */
    public function encryptPayload(array $data): string
    {
        $json = json_encode($data);
        return openssl_encrypt($json, 'AES-128-ECB', $this->clientSecret, 0);
    }

    /**
     * Decrypt an AES-128-ECB encrypted string using the client secret.
     *
     * @param string $encrypted Base64-encoded encrypted data.
     * @return string|false Decrypted string or false on failure.
     */
    public function decryptPayload(string $encrypted): string|false
    {
        return openssl_decrypt($encrypted, 'AES-128-ECB', $this->clientSecret, 0);
    }

    // ─────────────────────────────────────────────────────────────
    // Utility
    // ─────────────────────────────────────────────────────────────

    /**
     * Generate a unique transaction/invoice ID.
     *
     * Format: DG + YmdHis + 3 random digits
     * Example: DG20260317112339128
     *
     * @param string $prefix Custom prefix (default: "DG")
     * @return string
     */
    public static function generateTransactionId(string $prefix = 'DG'): string
    {
        return $prefix . date('YmdHis') . random_int(100, 999);
    }

    /**
     * DGePay status code constants.
     */
    public const STATUS_SUCCESS   = '3';
    public const STATUS_CANCELLED = '8';

    /**
     * Check if a status code indicates a successful transaction.
     */
    public static function isSuccessStatus(string $statusCode): bool
    {
        return $statusCode === self::STATUS_SUCCESS;
    }

    /**
     * Check if a status code indicates a cancelled transaction.
     */
    public static function isCancelledStatus(string $statusCode): bool
    {
        return $statusCode === self::STATUS_CANCELLED;
    }

    // ─────────────────────────────────────────────────────────────
    // Internal HTTP & Logging
    // ─────────────────────────────────────────────────────────────

    /**
     * Send an HTTP POST request using cURL.
     *
     * @param string   $url     Full URL.
     * @param string   $body    Request body (JSON or encrypted string).
     * @param string[] $headers Array of header strings.
     *
     * @return array{http_code: int, body: string}
     */
    protected function httpPost(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL error: ' . $error);
        }

        curl_close($ch);

        return ['http_code' => $httpCode, 'body' => $response];
    }

    /**
     * Log a message via the custom logger if set.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            call_user_func($this->logger, $level, 'DGePay: ' . $message, $context);
        }
    }
}
