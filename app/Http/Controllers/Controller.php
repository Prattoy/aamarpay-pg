<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function webhook_test(Request $request)
    {
        $validate = $this->verifyWebhookSignature($request);

        if ($validate['status']) {
            return response()->json(['status' => true, 'message' => $validate['message'], 'data' => $request->all()]);
        }
        return response()->json(['status' => false, 'message' => $validate['message'], 'data' => $request->all()]);
    }

    /**
     * Verify HMAC-SHA256 signature
     */
    protected function verifyWebhookSignature(Request $request)
    {
        try {
            // Get signature from header
            $receivedSignature = $request->header('X-Webhook-Signature');

            if (!$receivedSignature) {
                Log::error('No webhook signature provided');
                return false;
            }

            // Get webhook secret from config
            $webhookSecret = 'RMYVuo7859qmCssGi4rCB9PqzOnm7dwmqP0yVNFVowLqyqSybrbu0Av1MiX9Qj2K';

            if (!$webhookSecret) {
                Log::critical('Webhook secret not configured in services.payment_gateway.webhook_secret');
                return false;
            }

            // Get raw request body
            $payload = $request->getContent();

            if (empty($payload)) {
                Log::error('Empty webhook payload');
                return false;
            }

            // Generate expected signature
            $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

            // Compare signatures (timing-attack safe)
            $isValid = hash_equals($expectedSignature, $receivedSignature);

            if (!$isValid) {
                Log::error('Signature mismatch', [
                    'expected' => $expectedSignature,
                    'received' => $receivedSignature,
                    'payload_length' => strlen($payload),
                ]);
            }

            return ['status' => $isValid,  'message' => $expectedSignature .'+' . $receivedSignature];
        } catch (\Exception $e) {
            return ['status' => false,  'message' => $e->getMessage()];
        }
    }
}
