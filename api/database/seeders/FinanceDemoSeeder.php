<?php

namespace Database\Seeders;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FIN-11: a panel full enough to judge the finance screens by.
 *
 * Deliberately not random: the generator is seeded, so two runs produce the
 * same figures and a screenshot can be compared with the one before it. The
 * shape is chosen to exercise the edges the screens have to survive -- part
 * payments, failures, disputes, refunds in every state, invoices that are
 * overdue by a day and by a quarter -- rather than a tidy ledger where
 * everything is paid.
 *
 * It refuses to run against a database that already has real customers
 * unless forced, because the one thing worse than no demo data is demo data
 * mixed into somebody's books.
 */
class FinanceDemoSeeder extends Seeder
{
    public const CUSTOMERS = 100;

    public const SUBSCRIPTIONS = 150;

    public const INVOICES = 350;

    public const PAYMENTS = 300;

    public const REFUNDS = 25;

    public const OVERDUE = 50;

    public const MONTHS = 12;

    /** A fixed seed, so the demo looks the same every time it is built. */
    private \Random\Randomizer $rng;

    private array $names = [
        'Aurora', 'Beacon', 'Cedar', 'Delta', 'Ember', 'Fathom', 'Granite', 'Harbour', 'Indigo', 'Juniper',
        'Kestrel', 'Lantern', 'Meridian', 'Northwind', 'Orchard', 'Pinnacle', 'Quarry', 'Ridge', 'Summit', 'Thicket',
    ];

    private array $suffixes = ['Systems', 'Labs', 'Digital', 'Works', 'Group', 'Software', 'Media', 'Studio', 'Hosting', 'Retail'];

    private array $countries = ['LK', 'GB', 'DE', 'NL', 'AE', 'IN', 'AU', 'US', 'FR', 'SG'];

    public function __construct()
    {
        $this->rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20261103));
    }

    public function run(): void
    {
        $plans = $this->plans();
        $orgs = $this->customers();
        $subs = $this->subscriptions($orgs, $plans);
        $invoices = $this->invoices($orgs, $subs);
        $this->payments($invoices);
        $this->refunds($invoices);

        $this->command?->info(sprintf(
            'Demo finance data: %d customers, %d packages, %d subscriptions, %d invoices, %d payments, %d refunds.',
            count($orgs), count($plans), count($subs), count($invoices),
            Payment::allTenants()->count(), RefundRequest::allTenants()->count(),
        ));
    }

    /** @return Plan[] */
    private function plans(): array
    {
        $specs = [
            ['starter', 'Starter', 1, 1024, 10, 500],
            ['small', 'Small', 1, 2048, 20, 1200],
            ['standard', 'Standard', 2, 4096, 50, 2900],
            ['business', 'Business', 4, 8192, 120, 5900],
            ['pro', 'Pro', 8, 16384, 250, 11900],
            ['enterprise', 'Enterprise', 16, 32768, 600, 24900],
        ];

        return array_map(fn (array $s, int $i) => Plan::updateOrCreate(['code' => $s[0]], [
            'name' => $s[1], 'vcpu' => $s[2], 'ram_mb' => $s[3], 'storage_gb' => $s[4],
            'price_cents' => $s[5], 'currency' => 'EUR', 'sort' => $i,
            'limits' => ['apps' => $s[2] * 5, 'bandwidth_gb' => $s[4] * 10, 'backups_per_day' => 1, 'backup_retention_days' => 7],
        ]), $specs, array_keys($specs));
    }

    /** @return Organization[] */
    private function customers(): array
    {
        $orgs = [];
        for ($i = 0; $i < self::CUSTOMERS; $i++) {
            $name = $this->names[$i % count($this->names)].' '.$this->suffixes[intdiv($i, count($this->names)) % count($this->suffixes)];
            $slug = Str::slug($name).'-'.($i + 1);
            if ($existing = Organization::where('slug', $slug)->first()) {
                $orgs[] = $existing;

                continue;
            }
            $user = User::create([
                'name' => 'Owner of '.$name,
                'email' => $slug.'@demo.invalid',
                'password' => Str::random(32),
            ]);
            $org = app(OrganizationService::class)->create($name, $user);
            $org->forceFill(['slug' => $slug])->save();

            BillingProfile::updateOrCreate(['organization_id' => $org->id], [
                'company_name' => $name.' Ltd',
                'contact_name' => 'Accounts',
                'email' => 'billing@'.Str::slug($name).'.invalid',
                'address_line1' => (10 + $i).' Example Street',
                'city' => 'Colombo', 'postal_code' => '0'.(1000 + $i),
                'country' => $this->countries[$i % count($this->countries)],
                'tax_id' => 'TAX'.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
                'currency' => 'EUR',
                'balance_cents' => $i % 17 === 0 ? $this->rng->getInt(500, 5000) : 0,
            ]);
            $orgs[] = $org;
        }

        return $orgs;
    }

    /**
     * @param  Organization[]  $orgs
     * @param  Plan[]  $plans
     * @return Subscription[]
     */
    private function subscriptions(array $orgs, array $plans): array
    {
        // Creating an organisation already gives it a subscription, so the
        // first one for each customer is adopted rather than added -- adding
        // would leave every demo customer subscribed twice.
        $subs = [];
        $seen = [];
        for ($i = 0; $i < self::SUBSCRIPTIONS; $i++) {
            $org = $orgs[$i % count($orgs)];
            $plan = $plans[$this->rng->getInt(0, count($plans) - 1)];
            $yearly = $i % 5 === 0;
            // Two months to a year ago, so the twelve-month charts have shape.
            $start = Carbon::now()->subDays($this->rng->getInt(30, 365))->startOfDay();
            $end = $yearly ? $start->copy()->addYear() : $start->copy()->addMonthNoOverflow();
            while ($end->isPast()) {
                $start = $end->copy();
                $end = $yearly ? $end->copy()->addYear() : $end->copy()->addMonthNoOverflow();
            }

            // A spread of states, so every filter on the screen has rows.
            [$status, $cancelled, $trialEnds] = match (true) {
                $i % 20 === 7 => ['cancelled', Carbon::now()->subDays($this->rng->getInt(1, 120)), null],
                $i % 15 === 4 => ['suspended', null, null],
                $i % 11 === 3 => ['trialing', null, Carbon::now()->addDays($this->rng->getInt(1, 14))],
                default => ['active', null, null],
            };

            $attributes = [
                'organization_id' => $org->id,
                'plan_id' => $plan->id,
                'status' => $status,
                'billing_cycle' => $yearly ? 'yearly' : 'monthly',
                'discount_percent' => $i % 9 === 0 ? $this->rng->getInt(5, 25) : 0,
                'trial_ends_at' => $trialEnds,
                'cancelled_at' => $cancelled,
                'auto_renew' => $status === 'active',
                'current_period_start' => $start,
                'current_period_end' => $end,
                'created_at' => $start,
                'updated_at' => $start,
            ];

            $existing = isset($seen[$org->id])
                ? null
                : Subscription::allTenants()->where('organization_id', $org->id)->first();
            $seen[$org->id] = true;
            if ($existing) {
                $existing->update($attributes);
                $subs[] = $existing->fresh();
            } else {
                $subs[] = Subscription::create($attributes);
            }
        }

        return $subs;
    }

    /**
     * @param  Organization[]  $orgs
     * @param  Subscription[]  $subs
     * @return Invoice[]
     */
    private function invoices(array $orgs, array $subs): array
    {
        $invoices = [];
        $n = 0;
        for ($i = 0; $i < self::INVOICES; $i++) {
            $sub = $subs[$i % count($subs)];
            $org = $orgs[array_search($sub->organization_id, array_column(array_map(fn ($o) => ['id' => $o->id], $orgs), 'id'), true) ?: 0];
            $raised = Carbon::now()->subDays($this->rng->getInt(1, self::MONTHS * 30))->startOfDay();
            $subtotal = (int) round(($sub->plan?->price_cents ?? 1200) * $this->rng->getInt(90, 160) / 100);
            $tax = 0;

            // The last 50 are deliberately overdue, which is what the overdue
            // filter and the dunning figures on the overview are there for.
            $overdue = $i >= self::INVOICES - self::OVERDUE;
            $due = $overdue
                ? Carbon::now()->subDays($this->rng->getInt(1, 90))
                : $raised->copy()->addDays(14);
            $status = $overdue ? 'open' : ($this->rng->getInt(1, 10) <= 7 ? 'paid' : 'open');

            $invoices[] = Invoice::create([
                'organization_id' => $sub->organization_id,
                'number' => 'INV-'.str_pad((string) (++$n), 5, '0', STR_PAD_LEFT),
                'kind' => 'invoice',
                'period_start' => $raised->copy()->startOfMonth()->toDateString(),
                'period_end' => $raised->copy()->endOfMonth()->toDateString(),
                'line_items' => [[
                    'description' => ($sub->plan?->name ?? 'Hosting').' hosting',
                    'quantity' => 1, 'unit_cents' => $subtotal, 'amount_cents' => $subtotal,
                ]],
                'subtotal_cents' => $subtotal,
                'tax_cents' => $tax,
                'tax_rate' => 0,
                'amount_cents' => $subtotal + $tax,
                'currency' => 'EUR',
                'status' => $status,
                'due_at' => $due,
                'paid_at' => $status === 'paid' ? $raised->copy()->addDays($this->rng->getInt(0, 10)) : null,
                'created_at' => $raised,
                'updated_at' => $raised,
            ]);
            unset($org);
        }

        return $invoices;
    }

    /** @param Invoice[] $invoices */
    private function payments(array $invoices): void
    {
        $gateways = ['stripe', 'stripe', 'stripe', 'paypal', 'paypal', 'manual'];
        // The last OVERDUE invoices are the ones nobody paid, so the payments
        // land on the rest -- exactly PAYMENTS of them.
        $payable = array_slice($invoices, 0, self::PAYMENTS);

        foreach ($payable as $i => $invoice) {
            $gateway = $gateways[$i % count($gateways)];
            // A tenth are part payments and a twentieth failed, so the screens
            // have to show something other than a column of green.
            $part = $i % 10 === 5;
            $failed = $i % 20 === 11;
            $disputed = ! $failed && $i % 33 === 7;
            $amount = $part ? intdiv((int) $invoice->amount_cents, 2) : (int) $invoice->amount_cents;
            $at = $invoice->paid_at ?? $invoice->created_at ?? Carbon::now();

            Payment::create([
                'organization_id' => $invoice->organization_id,
                'invoice_id' => $invoice->id,
                'amount_cents' => $amount,
                'currency' => $invoice->currency,
                'gateway' => $gateway,
                'method' => $gateway === 'paypal' ? 'paypal' : ($gateway === 'manual' ? 'bank_transfer' : 'card'),
                'status' => $failed ? 'failed' : ($disputed ? 'disputed' : 'succeeded'),
                'gateway_reference' => $gateway === 'manual' ? null : strtoupper(substr($gateway, 0, 2)).'_'.Str::random(16),
                'failure_reason' => $failed ? 'The card issuer declined the charge.' : null,
                'paid_at' => $failed ? null : $at,
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            if ($part) {
                $invoice->update(['status' => 'partially_paid', 'paid_at' => null]);
            } elseif ($failed) {
                $invoice->update([
                    'status' => 'open',
                    'paid_at' => null,
                    'collection_attempts' => $this->rng->getInt(1, 3),
                ]);
            } elseif ($invoice->status !== 'paid') {
                $invoice->update(['status' => 'paid', 'paid_at' => $at]);
            }
        }
    }

    /** @param Invoice[] $invoices */
    private function refunds(array $invoices): void
    {
        $paid = array_values(array_filter($invoices, fn (Invoice $i) => $i->status === 'paid'));
        $states = ['pending', 'pending', 'pending', 'refunded', 'refunded', 'rejected', 'failed'];
        $reasons = [
            'Billed after the service was cancelled.',
            'Duplicate charge for the same month.',
            'The upgrade was not what was ordered.',
            'Downtime during the period.',
            'Goodwill after a support delay.',
        ];

        for ($i = 0; $i < min(self::REFUNDS, count($paid)); $i++) {
            $invoice = $paid[$i * 3 % count($paid)];
            $status = $states[$i % count($states)];
            $amount = min((int) $invoice->amount_cents, $this->rng->getInt(200, (int) $invoice->amount_cents));
            $asked = Carbon::parse($invoice->created_at)->addDays($this->rng->getInt(1, 20));

            $refund = RefundRequest::create([
                'organization_id' => $invoice->organization_id,
                'invoice_id' => $invoice->id,
                'amount_cents' => $amount,
                'currency' => $invoice->currency,
                'status' => $status,
                'reason' => $reasons[$i % count($reasons)],
                'admin_notes' => in_array($status, ['refunded', 'rejected'], true) ? 'Checked against the gateway.' : null,
                'gateway_reference' => $status === 'refunded' ? 'RE_'.Str::random(14) : null,
                'failure_reason' => $status === 'failed' ? 'The gateway reported the charge is too old to refund.' : null,
                'decided_at' => $status === 'pending' ? null : $asked->copy()->addDay(),
                'refunded_at' => $status === 'refunded' ? $asked->copy()->addDays(2) : null,
                'created_at' => $asked,
                'updated_at' => $asked,
            ]);

            // A refund that happened has to show in the books, or the overview
            // would report money the panel never gave back.
            if ($status === 'refunded') {
                $invoice->update(['refunded_cents' => (int) $invoice->refunded_cents + $amount]);
                DB::table('payments')->where('invoice_id', $invoice->id)->where('status', 'succeeded')
                    ->limit(1)->update(['refunded_cents' => $amount, 'status' => $amount >= (int) $invoice->amount_cents ? 'refunded' : 'partially_refunded']);
            }
            unset($refund);
        }
    }
}
