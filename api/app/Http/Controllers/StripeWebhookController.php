<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Drivers\StripePaymentGateway;
use Illuminate\Http\Request;

/** POST /api/v1/webhooks/stripe — signature-verified, idempotent per event id (UBIL-003). */
class StripeWebhookController extends Controller
{
    public function handle(Request $request, PaymentGateway $payments)
    {
        if (! $payments instanceof StripePaymentGateway) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Stripe is not the active payment driver.']], 404);
        }
        try {
            $event = $payments->constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (\Stripe\Exception\SignatureVerificationException|\UnexpectedValueException $e) {
            return response()->json(['error' => ['code' => 'invalid_signature', 'message' => 'Webhook signature verification failed.']], 400);
        }
        $applied = $payments->handle($event);

        return response()->json(['received' => true, 'duplicate' => ! $applied]);
    }
}
