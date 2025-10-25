<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentLogger
{
    public function handle(Request $request, Closure $next)
    {
        $state = 'Error';
        $reference_id = '';
        $service_from = '';
        $pg_txn_id = null;
        $bank_txn_id = null;
//        dd($request);
        if ($request->path() == 'api/payments/initiate') {
            $state = 'Initiate';
            $reference_id = $request->reference_id;
            $service_from = $request->service_from;
        } elseif ($request->path() == 'api/payments/callback/success') {
            $state = 'Success';
            $reference_id = $request->mer_txnid;
            $service_from = $request->opt_a;
            $pg_txn_id = $request->pg_txnid;
            $bank_txn_id = $request->bank_txn;
        } elseif ($request->path() == 'api/payments/callback/fail') {
            $state = 'Fail';
            $reference_id = $request->mer_txnid;
            $service_from = $request->opt_a;
            $pg_txn_id = $request->pg_txnid;
            $bank_txn_id = $request->bank_txn;
        } elseif ($request->path() == 'api/payments/callback/cancel') {
            $state = 'Cancel';
            $reference_id = $request->mer_txnid;
            $service_from = $request->opt_a;
            $pg_txn_id = $request->pg_txnid;
            $bank_txn_id = $request->bank_txn;
        } elseif ($request->path() == 'api/payments/callback/pg-webhook') {
            $state = 'PG Webhook';
            $reference_id = $request->mer_txnid;
            $service_from = $request->opt_a;
            $pg_txn_id = $request->pg_txnid;
            $bank_txn_id = $request->bank_trxid;
        }
        // Save incoming request payload
        $log = [
            'state' => $state,
            'reference_id' => $reference_id,
            'service_from' => $service_from,
            'pg_txn_id' => $pg_txn_id,
            'bank_txn_id' => $bank_txn_id,
            'request_payload' => json_encode($request->all()),
        ];

        $log_id = DB::table('payment_logs')->insertGetId($log);
        $request->merge(['log_id' => $log_id]);

        // Proceed with the request and capture response
        $response = $next($request);

        // --- Handle large responses (redirects, binary, etc.) ---
        if ($response->isRedirection()) {
            $response_payload = 'Redirected to: ' . $response->headers->get('Location');
            if ($state == 'Initiate') {
                $pg_txn_id = Str::contains($response_payload, 'track=')
                    ? Str::after($response_payload, 'track=')
                    : null;;
            }
        } elseif (
            str_contains($response->headers->get('Content-Type'), 'text/html') &&
            strlen($response->getContent()) > 5000
        ) {
            $response_payload = 'HTML Response (truncated)';
        } else {
            $response_payload = $response->getContent();
        }

        DB::table('payment_logs')
            ->where('id', $log_id)
            ->update([
                'response_payload' => $response_payload,
                'pg_txn_id' => $pg_txn_id,
                'updated_at'  => now(),
            ]);

        return $response;
    }
}
