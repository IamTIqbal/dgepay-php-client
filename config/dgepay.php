<?php

/**
 * DGePay PHP SDK — Configuration
 *
 * @developer Tamim Iqbal — IT Manager & AI Developer
 * @website   https://tamimiqbal.com
 * @license   MIT
 */

return [
    /*
    |--------------------------------------------------------------------------
    | DGePay Client ID
    |--------------------------------------------------------------------------
    |
    | Your DGePay merchant client ID. Obtain this from DGePay dashboard.
    |
    */
    'client_id' => env('DGEPAY_CLIENT_ID'),

    /*
    |--------------------------------------------------------------------------
    | DGePay Client Secret
    |--------------------------------------------------------------------------
    |
    | Your DGePay client secret. This is also used as the AES-128-ECB
    | encryption key for payload encryption and callback decryption.
    |
    */
    'client_secret' => env('DGEPAY_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | DGePay API Key
    |--------------------------------------------------------------------------
    |
    | Your DGePay API key. Used for HMAC-SHA256 signature generation.
    |
    */
    'client_api_key' => env('DGEPAY_CLIENT_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | DGePay Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the DGePay API. Default is the production v3 endpoint.
    | Change this for sandbox/testing if DGePay provides a sandbox URL.
    |
    */
    'base_url' => env('DGEPAY_BASE_URL', 'https://apiv2.dgepay.net/dipon/v3'),
];
