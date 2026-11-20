<?php

namespace App\Services;

use App\Models\EnterpriseWebhook;
use App\Support\PartnerSignature;
use Illuminate\Support\Facades\Http;

/**
 * Delivers the enterprise webhook outbox to each deployment's callback_url:
 * X-Enterprise-Signature: t=<unix>,v1=hex(HMAC-SHA256(callback_secret, t . "." . body)),
 * exponential backoff, parked after the last attempt.
 */
class EnterpriseWebhookService
{
    public function deliverDue(int $limit = 100): array
    {
        $stats = ['delivered' => 0, 'retrying' => 0, 'failed' => 0];
        $due = EnterpriseWebhook::whereNull('delivered_at')->whereNull('failed_at')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->with('deployment')->orderBy('created_at')->limit($limit)->get();
        foreach ($due as $hook) {
            $stats[$this->deliver($hook)]++;
        }

        return $stats;
    }

    public function deliver(EnterpriseWebhook $hook): string
    {
        $dep = $hook->deployment;
        $attempts = $hook->attempts + 1;
        if (! $dep?->callback_url) {
            $hook->update(['failed_at' => now(), 'attempts' => $attempts, 'last_error' => 'deployment has no callback URL']);

            return 'failed';
        }
        $body = json_encode(['id' => $hook->id] + $hook->payload, JSON_UNESCAPED_SLASHES);
        $error = null;
        try {
            $res = Http::timeout(10)->withBody($body, 'application/json')->withHeaders([
                'X-Enterprise-Event' => $hook->event,
                'X-Enterprise-Event-Id' => $hook->id,
                'X-Enterprise-Deployment' => $dep->id,
                'X-Enterprise-Signature' => PartnerSignature::webhookHeader($dep->callback_secret, $body),
            ])->post($dep->callback_url);
            if ($res->successful()) {
                $hook->update(['delivered_at' => now(), 'attempts' => $attempts, 'last_error' => null]);

                return 'delivered';
            }
            $error = 'HTTP '.$res->status();
        } catch (\Throwable $e) {
            $error = substr($e->getMessage(), 0, 250);
        }
        if ($attempts >= (int) config('enterprise.webhook_max_attempts', 12)) {
            $hook->update(['failed_at' => now(), 'attempts' => $attempts, 'last_error' => $error]);

            return 'failed';
        }
        $delay = min(6 * 3600, (int) config('enterprise.webhook_backoff', 30) * (2 ** ($attempts - 1)));
        $hook->update(['attempts' => $attempts, 'next_attempt_at' => now()->addSeconds($delay), 'last_error' => $error]);

        return 'retrying';
    }
}
