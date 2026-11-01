<?php

namespace App\Drivers;

use App\Contracts\PaymentGateway;
use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PayPal (UBIL-003). One PayPal Subscription per organisation on the plan's PayPal plan id
 * (config/billing.php); the customer approves it on PayPal, so card and account data never touch
 * our servers. Monthly usage is charged on the subscription (capture); webhooks are verified with
 * PayPal's verify-webhook-signature API and applied once per event id.
 */
class PayPalPaymentGateway implements PaymentGateway
{
    public function __construct(private array $config) {}

    /** config/billing.php, falling back to Settings → Services → PayPal for anything the env leaves empty. */
    public static function config(): array
    {
        $cfg = config('billing.paypal');
        if (empty($cfg['client_id']) || empty($cfg['secret'])) {
            $row = rescue(fn () => \App\Models\Integration::allTenants()->where('provider', 'paypal')->orderBy('created_at')->first(), null, report: false);
            if ($row) {
                $cfg['client_id'] = $cfg['client_id'] ?: ($row->config['client_id'] ?? null);
                $cfg['secret'] = $cfg['secret'] ?: ($row->credentials['client_secret'] ?? null);
                $cfg['webhook_id'] = $cfg['webhook_id'] ?: ($row->config['webhook_id'] ?? null);
                $cfg['mode'] = $row->config['mode'] ?? $cfg['mode'];
            }
        }

        return $cfg;
    }

    public function name(): string
    {
        return 'paypal';
    }

    private function base(): string
    {
        return ($this->config['mode'] ?? 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function token(): string
    {
        if (! ($this->config['client_id'] ?? null) || ! ($this->config['secret'] ?? null)) {
            throw new \RuntimeException('PAYPAL_CLIENT_ID / PAYPAL_SECRET are not configured.');
        }

        return Cache::remember('paypal.token.'.md5($this->config['client_id']), 3000, function () {
            $r = Http::asForm()->withBasicAuth($this->config['client_id'], $this->config['secret'])->timeout(15)
                ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            if (! $r->successful() || ! $r->json('access_token')) {
                throw new \RuntimeException('PayPal did not issue an access token.');
            }

            return $r->json('access_token');
        });
    }

    private function api(string $method, string $path, array $body = [])
    {
        $req = Http::withToken($this->token())->acceptJson()->timeout(20)->withHeaders(['PayPal-Request-Id' => (string) Str::uuid()]);
        $r = $method === 'get' ? $req->get($this->base().$path) : $req->{$method}($this->base().$path, $body);
        if (! $r->successful()) {
            throw ApiException::conflict('PayPal refused the request: '.($r->json('message') ?? $r->json('error_description') ?? 'HTTP '.$r->status()).'.');
        }

        return $r;
    }

    private function planId(Plan $plan): string
    {
        return $this->config['plans'][$plan->code] ?? throw ApiException::conflict("No PayPal plan is configured for the {$plan->name} plan (PAYPAL_PLAN_".strtoupper($plan->code).').');
    }

    private function currentId(Organization $organization): ?string
    {
        return Subscription::allTenants()->where('organization_id', $organization->id)->where('provider_subscription_id', 'like', 'I-%')->latest()->value('provider_subscription_id');
    }

    public function ensureCustomer(Organization $organization): string
    {
        // PayPal has no customer object; the subscription carries custom_id = organisation id.
        if (! $organization->payment_customer_id || ! str_starts_with($organization->payment_customer_id, 'paypal_')) {
            $organization->forceFill(['payment_customer_id' => 'paypal_'.$organization->id])->save();
        }

        return $organization->payment_customer_id;
    }

    public function attachPaymentMethod(Organization $organization, string $token): array
    {
        throw ApiException::conflict('With PayPal, the payment method is chosen on PayPal. Use "Manage billing" to approve the subscription.');
    }

    public function subscribe(Organization $organization, Plan $plan): string
    {
        $this->ensureCustomer($organization);
        $planId = $this->planId($plan);
        if ($current = $this->currentId($organization)) {
            $sub = $this->api('get', "/v1/billing/subscriptions/{$current}")->json();
            if (in_array($sub['status'] ?? '', ['ACTIVE', 'SUSPENDED', 'APPROVAL_PENDING', 'APPROVED'], true)) {
                if (($sub['plan_id'] ?? null) !== $planId) {
                    $r = $this->api('post', "/v1/billing/subscriptions/{$current}/revise", ['plan_id' => $planId])->json();
                    $this->rememberApproval($organization, $r);
                }

                return $current;
            }
        }
        $r = $this->api('post', '/v1/billing/subscriptions', [
            'plan_id' => $planId, 'custom_id' => $organization->id,
            'subscriber' => array_filter(['email_address' => $organization->billing_email]),
            'application_context' => [
                'brand_name' => config('billing.seller.name'), 'user_action' => 'SUBSCRIBE_NOW', 'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $this->config['return_url'].'?checkout=success', 'cancel_url' => $this->config['return_url'],
            ],
        ])->json();
        $this->rememberApproval($organization, $r);

        return $r['id'];
    }

    private function rememberApproval(Organization $organization, array $response): void
    {
        $link = collect($response['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;
        $link ? Cache::put("paypal.approve.{$organization->id}", $link, now()->addHours(3)) : Cache::forget("paypal.approve.{$organization->id}");
    }

    public function portalUrl(Organization $organization, string $returnUrl): string
    {
        if ($link = Cache::get("paypal.approve.{$organization->id}")) {
            return $link;
        }
        if ($this->currentId($organization)) {
            return ($this->config['mode'] ?? 'sandbox') === 'live' ? 'https://www.paypal.com/myaccount/autopay/' : 'https://www.sandbox.paypal.com/myaccount/autopay/';
        }
        $plan = Subscription::allTenants()->where('organization_id', $organization->id)->latest()->first()?->plan;
        if (! $plan) {
            throw ApiException::conflict('Choose a plan first.');
        }
        $id = $this->subscribe($organization, $plan);
        Subscription::allTenants()->where('organization_id', $organization->id)->latest()->first()?->update(['provider_subscription_id' => $id]);

        return Cache::get("paypal.approve.{$organization->id}") ?? throw ApiException::conflict('PayPal returned no approval link.');
    }

    public function cancel(Organization $organization, string $subscriptionId): void
    {
        // PayPal stops future charges; the current period is already paid.
        $this->api('post', "/v1/billing/subscriptions/{$subscriptionId}/cancel", ['reason' => 'Cancelled by the customer in the panel']);
        Cache::forget("paypal.approve.{$organization->id}");
    }

    public function collect(Organization $organization, Invoice $invoice): array
    {
        $items = array_filter($invoice->line_items ?? [], fn ($l) => ($l['kind'] ?? '') !== 'plan' && $l['amount_cents'] > 0);
        $usage = (int) array_sum(array_column($items, 'amount_cents'));
        $total = $usage + ($usage > 0 ? (int) round($usage * $invoice->tax_rate) : 0);
        $current = $this->currentId($organization);
        if ($total <= 0) {
            return ['status' => 'open', 'provider_invoice_id' => null];   // the plan line is charged by the subscription
        }
        if (! $current) {
            return ['status' => 'open', 'provider_invoice_id' => null];
        }
        $r = Http::withToken($this->token())->acceptJson()->timeout(20)->withHeaders(['PayPal-Request-Id' => 'dmp-'.$invoice->id])
            ->post($this->base()."/v1/billing/subscriptions/{$current}/capture", [
                'note' => 'Usage '.$invoice->number, 'capture_type' => 'OUTSTANDING_BALANCE',
                'amount' => ['currency_code' => strtoupper($invoice->currency), 'value' => number_format($total / 100, 2, '.', '')],
            ]);

        return $r->successful()
            ? ['status' => $invoice->amount_cents === $total ? 'paid' : 'open', 'provider_invoice_id' => $r->json('id')]
            : ['status' => 'failed', 'provider_invoice_id' => null];
    }

    /** Asks PayPal whether the webhook headers sign this body for our webhook id. */
    public function verify(array $headers, string $payload): bool
    {
        if (! ($this->config['webhook_id'] ?? null)) {
            throw new \RuntimeException('PAYPAL_WEBHOOK_ID is not configured.');
        }
        $event = json_decode($payload, true);
        if (! is_array($event)) {
            return false;
        }
        $r = Http::withToken($this->token())->acceptJson()->timeout(15)->post($this->base().'/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '', 'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '', 'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '', 'webhook_id' => $this->config['webhook_id'], 'webhook_event' => $event,
        ]);

        return $r->successful() && $r->json('verification_status') === 'SUCCESS';
    }

    /** Applies a verified event once (payment_events.event_id is unique). Returns false for duplicates. */
    public function handle(array $event): bool
    {
        $resource = $event['resource'] ?? [];
        $subId = str_starts_with($event['event_type'] ?? '', 'BILLING.SUBSCRIPTION') ? ($resource['id'] ?? null) : ($resource['billing_agreement_id'] ?? null);
        $orgId = $resource['custom_id'] ?? ($subId ? Subscription::allTenants()->where('provider_subscription_id', $subId)->value('organization_id') : null);
        $org = $orgId ? Organization::find($orgId) : null;
        $fresh = DB::table('payment_events')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'provider' => 'paypal', 'event_id' => (string) ($event['id'] ?? Str::uuid()), 'type' => (string) ($event['event_type'] ?? ''),
            'organization_id' => $org?->id, 'created_at' => now(),
        ]);
        if (! $fresh || ! $org) {
            return (bool) $fresh;
        }
        app(Tenant::class)->as($org->id, function () use ($event, $resource, $subId, $org) {
            $local = $subId ? Subscription::where('provider_subscription_id', $subId)->first() : null;
            switch ($event['event_type']) {
                case 'BILLING.SUBSCRIPTION.ACTIVATED':
                case 'BILLING.SUBSCRIPTION.UPDATED':
                case 'BILLING.SUBSCRIPTION.RE-ACTIVATED':
                    Cache::forget("paypal.approve.{$org->id}");
                    $local?->update(array_filter(['status' => 'active',
                        'current_period_end' => isset($resource['billing_info']['next_billing_time']) ? Carbon::parse($resource['billing_info']['next_billing_time']) : null]));
                    $payer = $resource['subscriber']['email_address'] ?? null;
                    $org->update(['payment_method' => ['brand' => 'paypal', 'last4' => '', 'email' => $payer, 'exp_month' => null, 'exp_year' => null]]);
                    break;
                case 'BILLING.SUBSCRIPTION.SUSPENDED':
                case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
                    $local?->update(['status' => 'past_due']);
                    Invoice::whereIn('status', ['open'])->update(['status' => 'failed']);
                    break;
                case 'BILLING.SUBSCRIPTION.CANCELLED':
                case 'BILLING.SUBSCRIPTION.EXPIRED':
                    $local?->update(['status' => 'canceled']);
                    $org->update(['payment_method' => null]);
                    break;
                case 'PAYMENT.SALE.COMPLETED':
                    $local?->update(['status' => 'active']);
                    Invoice::whereIn('status', ['open', 'failed'])->where('period_end', '<=', now()->toDateString())
                        ->update(['status' => 'paid', 'paid_at' => now()]);
                    break;
            }
        });

        return true;
    }
}
