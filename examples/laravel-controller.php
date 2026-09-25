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
     *
     * SECURITY: The callback arrives via the customer's browser and cannot be
     * trusted on its own. A payment is only marked completed after DGePay's API
     * (getTransactionStatus) confirms a successful status for this order at the
     * amount we stored. Only the verified server data is recorded.
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
            // The callback is unverified, so it must not change the order on its own either.
            // Only close the order if DGePay confirms it was cancelled; otherwise leave it
            // pending so a genuine payment can still be credited.
            if ($result['unique_txn_id']) {
                $statusCheck = $this->dgepay->getTransactionStatus($result['unique_txn_id']);

                if (($statusCheck['success'] ?? false) === true
                    && DgePay::isCancelledStatus((string) ($statusCheck['data']['status_code'] ?? ''))) {
                    DB::table('payments')
                        ->where('order_id', $result['unique_txn_id'])
                        ->where('status', 'pending')
                        ->update(['status' => 'cancelled', 'updated_at' => now()]);
                }
            }

            $msg = $result['is_cancelled']
                ? 'You cancelled the payment.'
                : 'Payment was not completed.';

            return redirect()->route('payment.plans')->with('error', $msg);
        }

        // Find the open payment ('pending_review' orders can still be completed by a
        // later callback, since every verification check below runs again)
        $payment = DB::table('payments')
            ->where('order_id', $result['unique_txn_id'])
            ->whereIn('status', ['pending', 'pending_review'])
            ->first();

        if (! $payment) {
            return redirect()->route('payment.plans')
                ->with('error', 'Payment record not found. Contact support.');
        }

        $orderId = $payment->order_id;

        // Verify server-to-server with DGePay. Never credit based on the callback alone.
        $statusCheck = $this->dgepay->getTransactionStatus($orderId);

        if (($statusCheck['success'] ?? false) !== true || ! is_array($statusCheck['data'] ?? null)) {
            // Could not verify (network/API error). The customer may have paid, so hold for review.
            return $this->rejectUnverifiedPayment($orderId, 'pending_review', 'Status check failed', [
                'message' => $statusCheck['message'] ?? null,
            ]);
        }

        $verified = $statusCheck['data'];

        if (! DgePay::isSuccessStatus((string) ($verified['status_code'] ?? ''))) {
            // Only a confirmed cancellation is final; any other status (e.g. still processing) needs review.
            $status = DgePay::isCancelledStatus((string) ($verified['status_code'] ?? '')) ? 'cancelled' : 'pending_review';

            return $this->rejectUnverifiedPayment($orderId, $status, 'DGePay did not confirm success', [
                'status_code' => $verified['status_code'] ?? null,
                'message'     => $verified['message'] ?? null,
            ]);
        }

        if ((string) ($verified['unique_txn_id'] ?? '') !== (string) $orderId) {
            return $this->rejectUnverifiedPayment($orderId, 'pending_review', 'Verified unique_txn_id mismatch', [
                'verified_unique_txn_id' => $verified['unique_txn_id'] ?? null,
            ]);
        }

        // Compare the verified amount with what we stored for this order (in paisa, to avoid float issues).
        if (! is_numeric($verified['amount'] ?? null)
            || $this->toMinorUnits($verified['amount']) !== $this->toMinorUnits($payment->amount)) {
            return $this->rejectUnverifiedPayment($orderId, 'pending_review', 'Verified amount mismatch', [
                'expected_amount' => $payment->amount,
                'verified_amount' => $verified['amount'] ?? null,
            ]);
        }

        $trxId = (string) ($verified['txn_number'] ?? '');

        // Activate the payment using ONLY verified server data.
        // The status guard makes this idempotent if the callback is replayed.
        $updated = DB::table('payments')
            ->where('order_id', $orderId)
            ->whereIn('status', ['pending', 'pending_review'])
            ->update([
                'status'     => 'completed',
                'trx_id'     => $trxId,
                'gateway'    => (string) ($verified['payment_method'] ?? ''),
                'amount'     => $verified['amount'],
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return redirect()->route('payment.plans')
                ->with('error', "Payment record not found. Contact support with your order ID: {$orderId}");
        }

        return redirect()->route('payment.success')
            ->with('success', "Payment successful! Transaction: {$trxId}");
    }

    /**
     * Mark a pending payment as not completed after verification failed,
     * log it, and send the customer to support with their order ID.
     */
    protected function rejectUnverifiedPayment(string $orderId, string $status, string $reason, array $context = [])
    {
        DB::table('payments')
            ->where('order_id', $orderId)
            ->whereIn('status', ['pending', 'pending_review'])
            ->update(['status' => $status, 'updated_at' => now()]);

        Log::error("DGePay payment verification failed: {$reason}", ['order_id' => $orderId, 'status' => $status] + $context);

        return redirect()->route('payment.plans')
            ->with('error', "We couldn't confirm your payment. Please contact support with your order ID: {$orderId}");
    }

    /**
     * Convert a taka amount to integer paisa for exact comparison.
     */
    protected function toMinorUnits(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
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
