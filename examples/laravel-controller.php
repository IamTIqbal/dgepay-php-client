<?php

/**
 * DGePay PHP SDK — Laravel Controller Example
 *
 * @developer Tamim Iqbal — IT Manager & AI Developer
 * @website   https://tamimiqbal.com
 *
 * Complete example of a Laravel payment controller integrated with DGePay.
 * Copy and adapt this to your application.
 */

namespace App\Http\Controllers;

use DgePay\DgePay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        protected DgePay $dgepay,
    ) {}

    /**
     * POST /payment/initiate
     *
     * Start a new DGePay payment.
     */
    public function initiate(Request $request)
    {
        $request->validate([
            'amount'      => 'required|numeric|min:1',
            'description' => 'required|string|max:255',
        ]);

        $user    = $request->user();
        $orderId = DgePay::generateTransactionId();

        // Save pending payment to database
        DB::table('payments')->insert([
            'user_id'    => $user->id,
            'order_id'   => $orderId,
            'amount'     => $request->amount,
            'status'     => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Initiate DGePay payment
        $result = $this->dgepay->initiatePayment([
            'amount'                => $request->amount,
            'description'           => $request->description,
            'orderId'               => $orderId,
            'redirectUrl'           => route('payment.callback'),
            'unique_user_reference' => (string) $user->id,
            'meta_data'             => [
                'custom_field_1' => $user->email,
            ],
        ]);

        if (! $result['success']) {
            DB::table('payments')
                ->where('order_id', $orderId)
                ->update(['status' => 'failed', 'updated_at' => now()]);

            return back()->with('error', $result['message']);
        }

        return redirect()->away($result['payment_url']);
    }

    /**
     * GET /payment/callback
     *
     * DGePay redirects here after payment.
     */
    public function callback(Request $request)
    {
        $callbackParams = $request->query();

        // Decrypt the encrypted callback data
        if ($request->has('data')) {
            // CRITICAL: Restore '+' characters that PHP converted to spaces
            $rawData   = str_replace(' ', '+', $request->query('data'));
            $decrypted = $this->dgepay->decryptCallbackData($rawData);

            if ($decrypted) {
                $callbackParams = $decrypted;
            }
        }

        // Parse the callback result
        $result = $this->dgepay->parseCallbackResult($callbackParams);

        Log::warning('DGePay callback', $result);

        if (! $result['is_success']) {
            $status = $result['is_cancelled'] ? 'cancelled' : 'failed';

            if ($result['unique_txn_id']) {
                DB::table('payments')
                    ->where('order_id', $result['unique_txn_id'])
                    ->where('status', 'pending')
                    ->update(['status' => $status, 'updated_at' => now()]);
            }

            $msg = $result['is_cancelled']
                ? 'You cancelled the payment.'
                : 'Payment was not completed.';

            return redirect()->route('payment.plans')->with('error', $msg);
        }

        // Find the pending payment
        $payment = DB::table('payments')
            ->where('order_id', $result['unique_txn_id'])
            ->where('status', 'pending')
            ->first();

        if (! $payment) {
            return redirect()->route('payment.plans')
                ->with('error', 'Payment record not found. Contact support.');
        }

        // Verify with DGePay API for security
        $statusCheck = $this->dgepay->getTransactionStatus($result['unique_txn_id']);
        $verifiedData = $statusCheck['data'] ?? $callbackParams;
        $trxId = $verifiedData['txn_number'] ?? $result['txn_number'];

        // Activate the payment
        DB::table('payments')
            ->where('order_id', $result['unique_txn_id'])
            ->update([
                'status'     => 'completed',
                'trx_id'     => $trxId,
                'gateway'    => $result['payment_method'],
                'updated_at' => now(),
            ]);

        return redirect()->route('payment.success')
            ->with('success', "Payment successful! Transaction: {$trxId}");
    }

    /**
     * GET /payment/status/{orderId}
     *
     * Manually check a payment's status with DGePay.
     */
    public function checkStatus(string $orderId)
    {
        $result = $this->dgepay->getTransactionStatus($orderId);

        if ($result['success']) {
            return response()->json([
                'status'         => DgePay::isSuccessStatus($result['data']['status_code'] ?? '') ? 'success' : 'other',
                'message'        => $result['data']['message'] ?? '',
                'txn_number'     => $result['data']['txn_number'] ?? '',
                'payment_method' => $result['data']['payment_method'] ?? '',
                'amount'         => $result['data']['amount'] ?? 0,
            ]);
        }

        return response()->json(['error' => $result['message']], 400);
    }
}
