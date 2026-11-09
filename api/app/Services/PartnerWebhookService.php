<?php

namespace App\Services;

use App\Models\PartnerWebhook;
use App\Support\PartnerSignature;
use Illuminate\Support\Facades\Http;

/** Delivers the partner webhook outbox: signed POSTs, exponential backoff, parked after the last attempt. */
class PartnerWebhookService
{
    public function deliverDue(int $limit = 100): array
    {
        $stats = ['delivered' => 0, 'retrying' => 0, 'failed' => 0];
        $due = PartnerWebhook::whereNull('delivered_at')->whereNull('failed_at')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->with('client')->orderBy('created_at')->limit($limit)->get();
        foreach ($due as $hook) {
            $stats[$this->deliver($hook)]++;
        }

        return $stats;
    }

    public function deliver(PartnerWebhook $hook): string
    {
        $client = $hook->client;
        $attempts = $hook->attempts + 1;
        if (! $client?->webhook_url || ! $client->active) {
            $hook->update(['failed_at' => now(), 'attempts' => $attempts, 'last_error' => 'partner has no active webhook URL']);

            return 'failed';
        }
        $body = json_encode(['id' => $hook->id] + $hook->payload, JSON_UNESCAPED_SLASHES);
        $error = null;
        try {
            $res = Http::timeout(10)->withBody($body, 'application/json')->withHeaders([
                'X-Livezen-Event' => $hook->event,
                'X-Livezen-Event-Id' => $hook->id,
                'X-Livezen-Signature' => PartnerSignature::webhookHeader($client->webhook_secret, $body),
            ])->post($client->webhook_url);
            if ($res->successful()) {
                $hook->update(['delivered_at' => now(), 'attempts' => $attempts, 'last_error' => null]);

                return 'delivered';
            }
            $error = 'HTTP '.$res->status();
        } catch (\Throwable $e) {
            $error = substr($e->getMessage(), 0, 250);
        }
        if ($attempts >= (int) config('partner.webhook_max_attempts', 12)) {
            $hook->update(['failed_at' => now(), 'attempts' => $attempts, 'last_error' => $error]);

            return 'failed';
        }
        $delay = min(6 * 3600, (int) config('partner.webhook_backoff', 30) * (2 ** ($attempts - 1)));
        $hook->update(['attempts' => $attempts, 'next_attempt_at' => now()->addSeconds($delay), 'last_error' => $error]);

        return 'retrying';
    }
}
