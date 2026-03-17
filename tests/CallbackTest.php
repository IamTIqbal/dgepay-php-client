<?php

/**
 * DGePay PHP SDK — Callback Tests
 *
 * @developer Tamim Iqbal — IT Manager & AI Developer
 * @website   https://tamimiqbal.com
 */

namespace DgePay\Tests;

use DgePay\DgePay;
use PHPUnit\Framework\TestCase;

class CallbackTest extends TestCase
{
    protected DgePay $dgepay;

    protected function setUp(): void
    {
        $this->dgepay = new DgePay([
            'client_id'      => 'test_client_id',
            'client_secret'  => 'test_secret_16ch',
            'client_api_key' => 'test_api_key',
        ]);
    }

    public function testParseSuccessfulCallback(): void
    {
        $params = [
            'status_code'    => 3,
            'unique_txn_id'  => 'DG20260317112339128',
            'message'        => 'TRANSACTION SUCCESS',
            'txn_number'     => '46629357',
            'payment_method' => 'bKash',
            'amount'         => 100,
            'metadata'       => ['custom_field_1' => 'pro'],
        ];

        $result = $this->dgepay->parseCallbackResult($params);

        $this->assertTrue($result['is_success']);
        $this->assertFalse($result['is_cancelled']);
        $this->assertEquals('DG20260317112339128', $result['unique_txn_id']);
        $this->assertEquals('46629357', $result['txn_number']);
        $this->assertEquals('bKash', $result['payment_method']);
    }

    public function testParseCancelledCallback(): void
    {
        $params = [
            'status_code'   => 8,
            'unique_txn_id' => 'DG20260317113021690',
            'message'       => 'TRANSACTION CANCELLED',
            'txn_number'    => '47031183',
        ];

        $result = $this->dgepay->parseCallbackResult($params);

        $this->assertFalse($result['is_success']);
        $this->assertTrue($result['is_cancelled']);
    }

    public function testParsePlainQueryParamSuccess(): void
    {
        // Some callbacks may come as plain query params instead of encrypted
        $params = [
            'status'        => 'success',
            'unique_txn_id' => 'ORDER123',
            'txn_number'    => '12345',
        ];

        $result = $this->dgepay->parseCallbackResult($params);
        $this->assertTrue($result['is_success']);
    }

    public function testParseEmptyCallback(): void
    {
        $result = $this->dgepay->parseCallbackResult([]);

        $this->assertFalse($result['is_success']);
        $this->assertFalse($result['is_cancelled']);
        $this->assertEquals('', $result['unique_txn_id']);
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $original = ['amount' => 100, 'note' => 'Test payment'];

        $encrypted = $this->dgepay->encryptPayload($original);
        $this->assertNotEmpty($encrypted);
        $this->assertNotEquals(json_encode($original), $encrypted);

        $decrypted = $this->dgepay->decryptPayload($encrypted);
        $this->assertNotFalse($decrypted);
        $this->assertEquals($original, json_decode($decrypted, true));
    }

    public function testDecryptCallbackDataRoundtrip(): void
    {
        $original = [
            'status_code'   => 3,
            'unique_txn_id' => 'ORDER123',
            'message'       => 'TRANSACTION SUCCESS',
            'txn_number'    => '12345',
        ];

        $encrypted = $this->dgepay->encryptPayload($original);
        $result    = $this->dgepay->decryptCallbackData($encrypted);

        $this->assertNotNull($result);
        $this->assertEquals($original, $result);
    }

    public function testDecryptCallbackDataInvalidData(): void
    {
        $result = $this->dgepay->decryptCallbackData('not-valid-encrypted-data');
        $this->assertNull($result);
    }
}
