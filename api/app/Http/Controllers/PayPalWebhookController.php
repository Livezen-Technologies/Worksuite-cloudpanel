<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Drivers\PayPalPaymentGateway;
use Illuminate\Http\Request;

/** POST /api/v1/webhooks/paypal — verified with PayPal, idempotent per event id (UBIL-003). */
class PayPalWebhookController extends Controller
{
    public function handle(Request $request, PaymentGateway $payments)
    {
        if (! $payments instanceof PayPalPaymentGateway) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'PayPal is not the active payment driver.']], 404);
        }
        $headers = collect($request->headers->all())->map(fn ($v) => $v[0] ?? '')->all();
        if (! $payments->verify($headers, $request->getContent())) {
            return response()->json(['error' => ['code' => 'invalid_signature', 'message' => 'Webhook signature verification failed.']], 400);
        }
        $applied = $payments->handle(json_decode($request->getContent(), true));

        return response()->json(['received' => true, 'duplicate' => ! $applied]);
    }
}
