<?php

/**
 * DGePay PHP SDK — Laravel Facade
 *
 * @developer Tamim Iqbal — IT Manager & AI Developer
 * @website   https://tamimiqbal.com
 * @license   MIT
 */

namespace DgePay\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array authenticate()
 * @method static array initiatePayment(array $paymentData)
 * @method static array getTransactionStatus(string $uniqueTxnId)
 * @method static array|null decryptCallbackData(string $encryptedData)
 * @method static array parseCallbackResult(array $params)
 * @method static string generateSignature(array $data)
 * @method static string encryptPayload(array $data)
 * @method static string|false decryptPayload(string $encrypted)
 * @method static string generateTransactionId(string $prefix = 'DG')
 *
 * @see \DgePay\DgePay
 */
class DgePay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \DgePay\DgePay::class;
    }
}
