<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\FinanceService;
use App\Support\Payments as Providers;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** FIN-9: tax, currency, invoice numbering, dunning -- and which gateways are live. */
class SettingsController extends Controller
{
    public function __construct(private FinanceService $finance, private Providers $providers) {}

    public function show()
    {
        return $this->data([
            'settings' => $this->finance->settings(),
            'gateways' => $this->gateways(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'currency' => ['sometimes', 'string', 'size:3'],
            'invoice_prefix' => ['sometimes', 'string', 'max:10'],
            'invoice_number_padding' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'invoice_due_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'invoice_footer' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tax_enabled' => ['sometimes', 'boolean'],
            'tax_name' => ['sometimes', 'string', 'max:40'],
            'tax_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_inclusive' => ['sometimes', 'boolean'],
            'auto_generate_invoices' => ['sometimes', 'boolean'],
            'auto_collect' => ['sometimes', 'boolean'],
            'reminder_days_before_due' => ['sometimes', 'array', 'max:5'],
            'reminder_days_before_due.*' => ['integer', 'min:0', 'max:90'],
            'reminder_days_after_due' => ['sometimes', 'array', 'max:5'],
            'reminder_days_after_due.*' => ['integer', 'min:0', 'max:180'],
            'retry_attempts' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'retry_interval_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'refund_auto_approve_under_cents' => ['sometimes', 'integer', 'min:0'],
            'refund_require_reason' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        // The finance settings are platform-wide, so they live on the first
        // organisation the way the rest of the platform settings do.
        $org = Organization::query()->orderBy('created_at')->firstOrFail();
        $settings = $org->settings ?? [];
        $settings['finance'] = array_replace(
            config('platform.default_settings.finance'),
            is_array($settings['finance'] ?? null) ? $settings['finance'] : [],
            $data,
        );
        $org->update(['settings' => $settings]);
        app(AuditLogger::class)->log('finance.settings_updated', 'finance', 'settings', array_keys($data), $org->id, $request->user()?->id);

        return $this->data(['settings' => $this->finance->settings(), 'gateways' => $this->gateways()]);
    }

    /**
     * Which providers can take money right now.
     *
     * Configuration is reported as a yes or no, never as the credential: a
     * secret that can be read back is a secret that can leak through a screen
     * share, so the screens show "configured" and nothing more.
     */
    private function gateways(): array
    {
        $available = $this->providers->available();

        return array_values(array_map(fn (string $name) => [
            'name' => $name,
            'label' => Providers::LABELS[$name] ?? ucfirst($name),
            'configured' => true,
            'default' => $name === app(\App\Contracts\PaymentGateway::class)->name(),
        ], $available));
    }

    /** A dry run against the configured gateway, so a credential can be checked without a charge. */
    public function testGateway(Request $request)
    {
        $data = $request->validate(['gateway' => ['required', Rule::in(array_keys(Providers::LABELS))]]);
        try {
            $gateway = $this->providers->make($data['gateway']);

            return $this->data(['ok' => true, 'gateway' => $gateway->name()]);
        } catch (\Throwable $e) {
            return $this->data(['ok' => false, 'gateway' => $data['gateway'], 'message' => $e->getMessage()]);
        }
    }
}
