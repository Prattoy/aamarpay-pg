<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AamarpayController extends Controller
{
//Better Flow (With Webhook):
//1. Service A → Your Gateway API → Aamarpay
//2. User pays on Aamarpay
//3. Aamarpay → Your Gateway (callback)
//4. Your Gateway verifies payment
//5. Your Gateway → Service A's webhook URL (HTTP POST with payment details)
//6. Service A receives notification, updates order status immediately
//7. Your Gateway → Redirects user back to Service A
//8. Service A already knows payment succeeded!

    protected $aamarpay_store_id;
    protected $aamarpay_signature_key;
    protected $aamarpay_url;
    protected $aamarpay_search_url;
    protected $aamarpay_success_url;
    protected $aamarpay_fail_url;
    protected $aamarpay_cancel_url;

    public function __construct()
    {
        $sandbox = config('aamarpay.sandbox_mode', true);

        $this->aamarpay_store_id = $sandbox
            ? config('aamarpay.sandbox.store_id')
            : config('aamarpay.live.store_id');

        $this->aamarpay_signature_key = $sandbox
            ? config('aamarpay.sandbox.signature_key')
            : config('aamarpay.live.signature_key');

        $this->aamarpay_url = $sandbox
            ? config('aamarpay.sandbox.url')
            : config('aamarpay.live.url');

        $this->aamarpay_search_url = $sandbox
            ? config('aamarpay.sandbox.search_url')
            : config('aamarpay.live.search_url');

        $this->aamarpay_success_url = config('aamarpay.app_url').'/api/payments/callback/success';
        $this->aamarpay_fail_url = config('aamarpay.app_url').'/api/payments/callback/fail';
        $this->aamarpay_cancel_url = config('aamarpay.app_url').'/api/payments/callback/cancel';
    }

    public function initiate(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'required|string',
            'reference_id' => 'required|string',
            'service_from' => 'required|string',
            'return_url' => 'required|string',
        ]);

        // Checks if payment data exists for this product
        $payment = Payment::where('reference_id', $request->reference_id)->first();
        if (!$payment) {
            $payment = new Payment();
            $payment->service_from = $request->service_from;
            $payment->reference_id = $request->reference_id;
            $payment->amount = (float)$request->amount;
            $payment->currency = $request->currency ?? 'BDT';
            $payment->name = $request->name;
            $payment->email = $request->email;
            $payment->phone = $request->phone;
            $payment->return_url = $request->return_url;
            $payment->webhook_url = $request->webhook_url;
            $payment->save();
        } elseif ($payment->verified_yn == 'Y') {
            return response()->json(['status' => 'error', 'message' => 'Payment for this product is already done'], 500);
        }
        //

        $tranId = $request->reference_id ?? uniqid('TXN_');

        $payload = [
            'store_id' => $this->aamarpay_store_id,
            'signature_key' => $this->aamarpay_signature_key,
            'tran_id' => $tranId,
            'success_url' => $this->aamarpay_success_url,
            'fail_url' => $this->aamarpay_fail_url,
            'cancel_url' => $this->aamarpay_cancel_url,
            'amount' => $request->amount,
            'currency' => $request->currency ?? 'BDT',
            'desc' => 'Payment via centralized API',
            'cus_name' => $request->name,
            'cus_email' => $request->email,
            'cus_phone' => $request->phone,
            'type' => 'json',
            'opt_a' => $request->service_from,
            'opt_b' => $request->return_url,
        ];

        $logPayload = $payload;
        $logPayload['signature_key'] = '********';
        Log::channel('aamarpay')->info('Payment Initiated', $logPayload);

        try {
            $response = Http::asForm()->post($this->aamarpay_url, $payload);
//            dd($response->json());

            // Update payment log table
            DB::table('payment_logs')
                ->where('id', $request->log_id)
                ->update([
                    'api_response' => $response->json(),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            Log::channel('aamarpay')->error('Aamarpay Request Exception', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'message' => 'Connection error'], 500);
        }

        Log::channel('aamarpay')->info('Aamarpay Response', ['body' => $response->body()]);
        if ($response->failed()) {
            return response()->json(['status' => 'error', 'message' => 'Aamarpay failed'], 500);
        }

        $data = json_decode($response->body());

        if (isset($data->result) && $data->result === 'true') {
            // Update payment status
            $trackId = Str::after($data->payment_url, 'track=');
            $payment->pg_txn_id = $trackId;
            $payment->initiated_yn = 'Y';
            $payment->initiated_at = now();
            $payment->save();
            //

            try {
                return redirect()->away($data->payment_url);
            } catch (\Throwable $e) {
                Log::channel('aamarpay')->error('Redirect Error', [
                    'error' => $e->getMessage(),
                    'url' => $data->payment_url ?? null
                ]);
                return response()->json(['status' => 'error', 'message' => 'Redirect failed'], 500);
            }
        } else {
            return back()->with('error', 'Payment gateway error occurred');
        }
    }

    public function success(Request $request)
    {
        $request_id = $request->mer_txnid; // Transaction ID sent by Aamarpay

        // Update payment in DB
        $payment = Payment::where('reference_id', $request_id)
            ->where('service_from', $request->opt_a)
            ->first();
        $payment->succeed_yn = 'Y';
        $payment->succeed_at = now();
        $payment->save();
        //

        // Prepare query parameters
        $params = [
            'request_id' => $request_id,
            'store_id' => $this->aamarpay_store_id,
            'signature_key' => $this->aamarpay_signature_key,
            'type' => 'json',
        ];

        /***************Verification Starts*************/
        // Call Aamarpay transaction verify API
        try {
            $response = Http::timeout(10)
                ->retry(3, 200)
                ->get($this->aamarpay_search_url, $params);
//            dd($response->body());

            // Payment Log
            $this->saveToPaymentLog($request, $params, $response);

        } catch (\Throwable $e) {
            Log::channel('aamarpay')->error('Transaction Verify Error', [
                'request_id' => $request_id,
                'error' => $e->getMessage(),
            ]);
            return redirect()->away($request->opt_b. '?message=' . urlencode('Payment Verification Failed') . '&status=fail');
        }

        $data = $response->json();

        if ($data['pay_status'] != 'Successful') {
            return redirect()->away($request->opt_b. '?message=' . urlencode('Payment Verification Failed') . '&status=fail');
        }

        // Validate amount matches (CRITICAL SECURITY CHECK)
        if (isset($data['amount_currency']) && (float)$data['amount_currency'] !== $payment->amount) {
            Log::channel('aamarpay')->critical('Amount Mismatch Detected', [
                'reference_id' => $request_id,
                'expected_amount' => $payment->amount,
                'received_amount' => $data['amount_currency']
            ]);
            throw new \Exception('Payment amount mismatch');
        }

        // Log response
        Log::channel('aamarpay')->info('Transaction Verified', [
            'request_id' => $request_id,
            'response' => $data,
            'request' => $request->all(),
            'res_' => $response
        ]);

        // Update payment in DB
        $payment->bank_txn_id = $data['bank_trxid'];
        $payment->verified_yn = 'Y';
        $payment->verified_at = now();
        $payment->save();
        //
        /***************Verification Ends*************/

        /***************Service Provide Starts*************/
        if ($payment->webhook_url) {
            $service_notify = $this->notifyService($payment, 'success', $data['date_processed']);
            if (!$service_notify['status']) {
                return redirect()->away($request->opt_b. '?message=' . urlencode('Service update failed') . '&status=fail');
            }
        }
        /***************Service Provide Ends*************/

        try {
            return redirect()->away($request->opt_b. '?message=' . urlencode('Payment Successful') . '&status=success');
        } catch (\Throwable $e) {
            Log::channel('aamarpay')->error('Redirect Error', [
                'error' => $e->getMessage(),
                'url' => $data->payment_url ?? null
            ]);
            return response()->json(['status' => 'error', 'message' => 'Redirect failed'], 500);
        }
    }

    public function fail(Request $request)
    {
        Log::channel('aamarpay')->warning('Aamarpay Fail Callback', $request->all());
        try {
            return redirect()->away($request->opt_b. '?message=' . urlencode('Payment Failed') . '&status=fail');
        } catch (\Throwable $e) {
            Log::channel('aamarpay')->error('Redirect Error', [
                'error' => $e->getMessage(),
                'url' => $data->payment_url ?? null
            ]);
            return response()->json(['status' => 'error', 'message' => 'Redirect failed'], 500);
        }
    }

    public function cancel(Request $request)
    {
        Log::channel('aamarpay')->warning('Aamarpay Cancel Callback', $request->all());
        return response()->json(['status' => 'cancel', 'data' => $request->all()]);
    }

    /**
     * Save verify payment log
     */
    public function saveToPaymentLog($request, $params, $response, $webhook=false)
    {
        try {
            $state = 'Verified';
            $bank_txn_id = $request->bank_txn;
            if ($webhook) {
                $state = 'Verified by Webhook';
                $bank_txn_id = $request->bank_trxid;
            }
            $params['signature_key'] = '********';
            $log = [
                'state' => $state,
                'reference_id' => $request->mer_txnid,
                'service_from' => $request->opt_a,
                'pg_txn_id' => $request->pg_txnid,
                'bank_txn_id' => $bank_txn_id,
                'request_payload' => json_encode($params),
            ];

            $log['response_payload'] = $response->body();
            DB::table('payment_logs')->insert($log);
        } catch (\Throwable $e) {
            Log::channel('aamarpay')->error('Aamarpay Verify Payment Log Exception', [
                'reference_id' => $request->mer_txnid,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send webhook with HMAC signature
     */
    protected function notifyService(Payment $payment, string $status, string $payment_date): array
    {
        if (!$payment->webhook_url) {
            return ['status' => false, 'message' => 'Webhook URL missing'];
        }

        //Save log in db
        $payment_log = new PaymentLog();
        $payment_log->state = 'Notify Service';
        $payment_log->service_from = $payment->service_from;
        $payment_log->reference_id = $payment->reference_id;
        $payment_log->pg_txn_id = $payment->pg_txn_id;
        $payment_log->bank_txn_id = $payment->bank_txn_id;
        $payment_log->save();

        try {
            // Get service's webhook secret from config
            $service = $this->getServiceConfig($payment->service_from);

            if (!$service || !isset($service['webhook_secret'])) {
                throw new \Exception('Service webhook secret not configured');
            }

            $webhookSecret = $service['webhook_secret'];

            // Prepare payload
            $payload = [
                'event' => 'payment.' . $status,
                'timestamp' => now()->timestamp, // Unix timestamp
                'reference_id' => $payment->reference_id,
                'amount' => (float) $payment->amount,
                'status' => $status,
                'bank_txn_id' => $payment->bank_txn_id,
                'pg_txn_id' => $payment->pg_txn_id,
                'verified_at' => $payment_date,
                'metadata' => $payment->metadata ? json_decode($payment->metadata, true) : null,
            ];

            //Save log in db
            $payment_log->request_payload = json_encode($payload);
            $payment_log->save();

            // Generate HMAC signature
            $signature = $this->generateWebhookSignature($payload, $webhookSecret);

            // Send webhook with signature in header
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Timestamp' => $payload['timestamp'],
                    'Content-Type' => 'application/json',
                ])
                ->retry(3, 200) // Retry 3 times with 200ms delay
                ->post($payment->webhook_url, $payload);

            //Save log in db
            $payment_log->response_payload = $response->body();
            $payment_log->save();

            $service_provided = 'N';
            if ($response->json()['status'] === 'success') {
                $service_provided = 'Y';
            }

            // Log success
            Log::channel('aamarpay')->info('Service Webhook delivered', [
                'payment_id' => $payment->id,
                'service_from' => $payment->service_from,
                'webhook_url' => $payment->webhook_url,
                'response_status' => $response->status(),
                'attempts' => 1
            ]);

            // Update payment record
            $payment->service_provided_yn = $service_provided;
            if ($service_provided === 'Y') {
                $payment->service_provided_at = now();
            }
            $payment->webhook_sent_at = now();
            $payment->webhook_attempts = DB::raw('webhook_attempts + 1');
            $payment->save();

            if ($service_provided === 'Y') {
                return ['status' => true, 'message' => 'Service provided successfully'];
            }
            return ['status' => false, 'message' => $response->status()];
        } catch (\Throwable $e) {

            Log::channel('aamarpay')->error('Service Webhook delivery failed', [
                'payment_id' => $payment->id,
                'service_from' => $payment->service_from,
                'webhook_url' => $payment->webhook_url,
                'error' => $e->getMessage()
            ]);

            //Save log in db
            $payment_log->response_payload = $e->getMessage();
            $payment_log->save();

            return ['status' => false, 'message' => 'Service Webhook delivery exception occurred'];
            // Queue for retry (optional but recommended)
            // dispatch(new RetryWebhookJob($payment->id))->delay(now()->addMinutes(5));
        }
    }

    /**
     * Generate HMAC-SHA256 signature for webhook
     */
    protected function generateWebhookSignature(array $payload, string $secret): string
    {
        // Create canonical string from payload
        $canonicalString = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Generate HMAC signature
        return hash_hmac('sha256', $canonicalString, $secret);
    }

    /**
     * Get service configuration
     */
    protected function getServiceConfig(string $serviceName): ?array
    {
        $services = config('aamarpay.authorized_services', []);

        foreach ($services as $service) {
            if ($service['name'] === $serviceName || $service['service_from'] === $serviceName) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Webhook endpoint - Called by Aamarpay for async notifications
     * This is different from success callback (which redirects user)
     * This is server-to-server communication
     */
    public function pgWebhook(Request $request)
    {
        Log::channel('aamarpay')->info('PG Webhook Received', [
            'ip' => $request->ip(),
            'data' => $request->all()
        ]);

        // Validate required fields
        if (!$request->has('mer_txnid') || !$request->has('opt_a')) {
            Log::channel('aamarpay')->error('PG Webhook Missing Required Fields', [
                'received_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Missing required fields'
            ], 400);
        }

        $request_id = $request->mer_txnid;
        $service_from = $request->opt_a;
        $date_processed = $request->date_processed;

        DB::beginTransaction();
        try {
            // Find payment record
            $payment = Payment::where('reference_id', $request_id)
                ->where('service_from', $service_from)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                Log::channel('aamarpay')->error('PG Webhook Payment Not Found', [
                    'reference_id' => $request_id,
                    'service_from' => $service_from
                ]);

                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Payment record not found'
                ], 404);
            }

            // Check if already service provided (prevent double processing)
            if ($payment->service_provided_yn === 'Y') {
                Log::channel('aamarpay')->info('PG Webhook Service Already Provided', [
                    'reference_id' => $request_id,
                    'date_processed' => $payment->date_processed
                ]);

                DB::rollBack();
                return response()->json([
                    'status' => 'success',
                    'message' => 'Service already provided',
                    'reference_id' => $request_id
                ], 200);
            }

            // Check if already verified (prevent double processing)
            if ($payment->verified_yn !== 'Y') {
                /***************Verification Starts*************/
                // Prepare verification parameters
                $params = [
                    'request_id' => $request_id,
                    'store_id' => $this->aamarpay_store_id,
                    'signature_key' => $this->aamarpay_signature_key,
                    'type' => 'json',
                ];

                // Call Aamarpay transaction verify API
                $response = Http::timeout(10)
                    ->retry(3, 200)
                    ->get($this->aamarpay_search_url, $params);

                // Save to payment log
                $this->saveToPaymentLog($request, $params, $response, true);

                if ($response->failed()) {
                    throw new \Exception('PG Webhook Aamarpay verification API failed');
                }

                $data = $response->json();
                $date_processed = $data['date_processed'];

                // Validate payment status
                if (!isset($data['pay_status']) || $data['pay_status'] !== 'Successful') {
                    throw new \Exception('PG Webhook Payment verification failed: Status is ' . ($data['pay_status'] ?? 'Unknown'));
                }

                // Validate amount matches (CRITICAL SECURITY CHECK)
                if (isset($data['amount_currency']) && (float)$data['amount_currency'] !== $payment->amount) {
                    Log::channel('aamarpay')->critical('PG Webhook Amount Mismatch Detected in Webhook', [
                        'reference_id' => $request_id,
                        'expected_amount' => $payment->amount,
                        'received_amount' => $data['amount_currency']
                    ]);
                    throw new \Exception('PG Webhook Payment amount mismatch');
                }

                // Update payment record
                $payment->bank_txn_id = $data['bank_trxid'] ?? null;
                $payment->verified_yn = 'Y';
                $payment->verified_at = now();
                $payment->save();

                Log::channel('aamarpay')->info('PG Webhook - Transaction Verified', [
                    'reference_id' => $request_id,
                    'bank_txn_id' => $data['bank_trxid'] ?? null,
                    'amount' => $data['amount'] ?? null,
                    'service_from' => $service_from
                ]);

                /***************Verification Ends*************/
            } else {
                Log::channel('aamarpay')->info('PG Webhook Payment Already Verified', [
                    'reference_id' => $request_id,
                    'verified_at' => $payment->verified_at
                ]);
            }

            DB::commit();

            /***************Service Notification Starts*************/
            // Notify service via their webhook (async)
            if ($payment->webhook_url) {
                try {
                    $service_notify = $this->notifyService($payment, 'success',(string) $date_processed);

                    Log::channel('aamarpay')->info('PG Webhook Service Notified Successfully', [
                        'payment_id' => $payment->id,
                        'webhook_url' => $payment->webhook_url
                    ]);

                    if (!$service_notify['status']) {
                        // Return 500 so Aamarpay can retry
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Webhook processing failed',
                            'error' => config('app.debug') ? $service_notify['message'] : 'Internal server error-1'
                        ], 500);
                    }
                } catch (\Throwable $e) {
                    // Don't fail the webhook if service notification fails
                    // Just log it for retry later
                    Log::channel('aamarpay')->error('PG Webhook Service Notification Failed', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage()
                    ]);

                    // Return 500 so Aamarpay can retry
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Webhook processing failed',
                        'error' => config('app.debug') ? $e->getMessage() : 'Internal server error-2'
                    ], 500);
                }
            }
            /***************Service Notification Ends*************/

            // Return success response to Aamarpay
            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed successfully',
                'reference_id' => $request_id,
                'bank_txn_id' => $request['bank_trxid'] ?? null
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::channel('aamarpay')->error('PG Webhook Processing Failed', [
                'reference_id' => $request_id ?? null,
                'service_from' => $service_from ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return 500 so Aamarpay can retry
            return response()->json([
                'status' => 'error',
                'message' => 'Webhook processing failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error-3'
            ], 500);
        }
    }
}
