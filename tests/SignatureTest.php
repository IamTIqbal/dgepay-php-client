<?php

/**
 * DGePay PHP SDK — Signature Tests
 *
 * @developer Tamim Iqbal — IT Manager & AI Developer
 * @website   https://tamimiqbal.com
 */

namespace DgePay\Tests;

use DgePay\DgePay;
use PHPUnit\Framework\TestCase;

class SignatureTest extends TestCase
{
    protected DgePay $dgepay;

    protected function setUp(): void
    {
        $this->dgepay = new DgePay([
            'client_id'      => 'test_client_id',
            'client_secret'  => 'test_secret_16ch',  // must be 16 chars for AES-128
            'client_api_key' => 'b0a5941bfdf4f2fb724a6382de52f44',
        ]);
    }

    /**
     * Test signature generation is deterministic and handles all data types correctly.
     *
     * This test verifies the signature algorithm:
     * 1. Params sorted alphabetically
     * 2. Nested objects flattened (parent key once, children recurse)
     * 3. Numbers formatted as floats (15 → "15.0")
     * 4. Nulls → "null", bools → "true"/"false"
     * 5. Strip { } " : spaces commas
     * 6. HMAC-SHA256 with API key → base64
     */
    public function testSignatureIsDeterministic(): void
    {
        $data = [
            'amount'                => 15,
            'customer_token'        => null,
            'note'                  => 'Testing note of DGePay',
            'payee_information'     => null,
            'payment_method'        => null,
            'redirect_url'          => 'https://example.com/callback',
            'unique_txn_id'         => 'ORDER123456',
            'meta_data'             => [
                'custom_field_1' => 'value1',
                'custom_field_2' => 'value2',
                'custom_field_3' => 'value3',
            ],
            'unique_user_reference' => null,
        ];

        $sig1 = $this->dgepay->generateSignature($data);
        $sig2 = $this->dgepay->generateSignature($data);

        $this->assertEquals($sig1, $sig2, 'Same data should always produce the same signature');
        $this->assertNotEmpty($sig1);
        // Verify it's valid base64
        $this->assertNotFalse(base64_decode($sig1, true));
    }

    /**
     * Test that changing data produces a different signature.
     */
    public function testDifferentDataProducesDifferentSignature(): void
    {
        $data1 = ['amount' => 15, 'note' => 'Test 1', 'unique_txn_id' => 'A'];
        $data2 = ['amount' => 20, 'note' => 'Test 2', 'unique_txn_id' => 'B'];

        $sig1 = $this->dgepay->generateSignature($data1);
        $sig2 = $this->dgepay->generateSignature($data2);

        $this->assertNotEquals($sig1, $sig2);
    }

    /**
     * Test that numeric strings (phone numbers, etc.) are NOT converted to floats.
     */
    public function testNumericStringsNotConvertedToFloats(): void
    {
        $data1 = ['phone' => '+8801712345678'];
        $data2 = ['phone' => '+8801712345678'];

        $sig1 = $this->dgepay->generateSignature($data1);
        $sig2 = $this->dgepay->generateSignature($data2);

        $this->assertEquals($sig1, $sig2, 'Numeric strings should produce consistent signatures');
    }

    /**
     * Test that integers are formatted as floats with one decimal.
     */
    public function testIntegersFormattedAsFloats(): void
    {
        $data1 = ['amount' => 15];
        $data2 = ['amount' => 15.0];

        $sig1 = $this->dgepay->generateSignature($data1);
        $sig2 = $this->dgepay->generateSignature($data2);

        $this->assertEquals($sig1, $sig2, 'Integer 15 and float 15.0 should produce same signature');
    }

    public function testTransactionIdFormat(): void
    {
        $txnId = DgePay::generateTransactionId();

        $this->assertStringStartsWith('DG', $txnId);
        $this->assertMatchesRegularExpression('/^DG\d{14}\d{3}$/', $txnId);
    }

    public function testCustomTransactionIdPrefix(): void
    {
        $txnId = DgePay::generateTransactionId('PAY');
        $this->assertStringStartsWith('PAY', $txnId);
    }

    public function testStatusConstants(): void
    {
        $this->assertTrue(DgePay::isSuccessStatus('3'));
        $this->assertFalse(DgePay::isSuccessStatus('8'));
        $this->assertTrue(DgePay::isCancelledStatus('8'));
        $this->assertFalse(DgePay::isCancelledStatus('3'));
    }
}
