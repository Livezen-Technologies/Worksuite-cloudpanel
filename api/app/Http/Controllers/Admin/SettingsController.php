<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant;
use Illuminate\Http\Request;

/** SET-001/002. */
class SettingsController extends Controller
{
    public function show()
    {
        return $this->data($this->present());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'general' => 'sometimes|array',
            'general.panel_url' => 'sometimes|url|max:255',
            'general.default_region' => 'sometimes|string|max:60',
            'general.audit_retention_days' => 'sometimes|integer|min:30|max:3650',
            'general.heartbeat_interval' => 'sometimes|integer|min:15|max:30',
            'general.refresh_interval' => 'sometimes|integer|min:5|max:15',
            'smtp' => 'sometimes|array',
            'smtp.host' => 'sometimes|nullable|string|max:190',
            'smtp.port' => 'sometimes|integer|min:1|max:65535',
            'smtp.username' => 'sometimes|nullable|string|max:190',
            'smtp.password' => 'sometimes|nullable|string|max:255',
            'smtp.encryption' => 'sometimes|nullable|in:tls,ssl,none',
            'smtp.from_address' => 'sometimes|nullable|email|max:190',
            'smtp.from_name' => 'sometimes|nullable|string|max:120',
            'antivirus' => 'sometimes|array',
            'antivirus.schedule' => 'sometimes|in:daily,weekly,off',
            'antivirus.day' => 'sometimes|integer|min:0|max:6',
            'antivirus.time' => 'sometimes|date_format:H:i',
            'antivirus.notify' => 'sometimes|boolean',
            'security' => 'sometimes|array',
            'security.*' => 'boolean',
            'firewall' => 'sometimes|array',
            'firewall.challenge_type' => 'sometimes|in:managed,javascript,recaptcha',
            'firewall.country_mode' => 'sometimes|in:block,allow',
            'firewall.provider' => 'sometimes|in:turnstile,hcaptcha,recaptcha',
            'firewall.site_key' => 'sometimes|nullable|string|max:255',
            'firewall.secret_key' => 'sometimes|nullable|string|max:255',
            'firewall.min_score' => 'sometimes|numeric|min:0|max:1',
            'firewall.clearance_minutes' => 'sometimes|integer|min:1|max:1440',
            'firewall.trigger_threat_score' => 'sometimes|boolean',
            'firewall.trigger_bad_bots' => 'sometimes|boolean',
            'firewall.trigger_rate_limit' => 'sometimes|boolean',
            'firewall.trigger_anonymiser' => 'sometimes|boolean',
            'firewall.trigger_country' => 'sometimes|boolean',
        ]);
        $org = Tenant::current();
        $settings = array_replace_recursive(config('platform.default_settings'), $org->settings ?? []);
        // The password is written but never read back; it is stored encrypted.
        if (array_key_exists('smtp', $data)) {
            $smtp = $data['smtp'];
            if (array_key_exists('password', $smtp)) {
                $settings['smtp']['password_encrypted'] = $smtp['password'] === null || $smtp['password'] === ''
                    ? null
                    : \Illuminate\Support\Facades\Crypt::encryptString($smtp['password']);
                unset($smtp['password']);
            }
            foreach ($smtp as $k => $v) {
                if (array_key_exists($k, config('platform.default_settings.smtp'))) {
                    $settings['smtp'][$k] = $v;
                }
            }
        }

        // The challenge secret is written but never read back; stored encrypted.
        if (array_key_exists('firewall', $data)) {
            $fw = $data['firewall'];
            if (array_key_exists('secret_key', $fw)) {
                $settings['firewall']['secret_key_encrypted'] = $fw['secret_key'] === null || $fw['secret_key'] === ''
                    ? null
                    : \Illuminate\Support\Facades\Crypt::encryptString($fw['secret_key']);
                unset($fw['secret_key']);
            }
            foreach ($fw as $k => $v) {
                if (array_key_exists($k, config('platform.default_settings.firewall'))) {
                    $settings['firewall'][$k] = $v;
                }
            }
        }

        foreach (['general', 'security', 'antivirus'] as $section) {
            foreach ($data[$section] ?? [] as $key => $value) {
                if (array_key_exists($key, config("platform.default_settings.{$section}"))) {
                    $settings[$section][$key] = $value;
                }
            }
        }
        $org->update(array_filter(['name' => $data['name'] ?? null, 'settings' => $settings, 'default_region' => $settings['general']['default_region']]));

        return $this->data($this->present());
    }

    /** SET-002: send a test email with the saved SMTP settings and report what the server said. */
    public function testSmtp(Request $request)
    {
        $data = $request->validate(['to' => 'nullable|email|max:190']);
        $settings = array_replace_recursive(config('platform.default_settings'), Tenant::current()->settings ?? []);
        $smtp = $settings['smtp'] ?? [];
        $mailer = \App\Support\Smtp::config($smtp) ?? throw \App\Exceptions\ApiException::conflict('Save an SMTP host first.');
        $to = $data['to'] ?? $request->user()->email;
        $from = $smtp['from_address'] ?: config('mail.from.address');
        config(['mail.mailers.smtp_test' => $mailer]);
        app('mail.manager')->purge('smtp_test');
        try {
            \Illuminate\Support\Facades\Mail::mailer('smtp_test')->raw(
                "This is a test email from WorkSuite CloudPanel.\n\nYour SMTP settings work: {$smtp['host']}:{$mailer['port']} ({$mailer['scheme']}).",
                fn ($m) => $m->to($to)->from($from, $smtp['from_name'] ?: config('mail.from.name'))->subject('WorkSuite CloudPanel SMTP test'),
            );
        } catch (\Throwable $e) {
            app(\App\Services\AuditLogger::class)->log('settings.smtp_test', 'organizations', Tenant::current()->id, ['to' => $to, 'ok' => false]);
            throw \App\Exceptions\ApiException::upstream('The SMTP server refused the test email: '.\Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()), 300));
        }
        app(\App\Services\AuditLogger::class)->log('settings.smtp_test', 'organizations', Tenant::current()->id, ['to' => $to, 'ok' => true]);
        $request->attributes->set('audit_skip', true);

        return $this->data(['sent' => true, 'to' => $to]);
    }

    private function present(): array
    {
        $org = Tenant::current()->fresh();
        $settings = array_replace_recursive(config('platform.default_settings'), $org->settings ?? []);

        $smtp = $settings['smtp'] ?? [];
        $hasPassword = ! empty($smtp['password_encrypted']);
        unset($smtp['password_encrypted']);

        $firewall = $settings['firewall'] ?? [];
        $hasSecret = ! empty($firewall['secret_key_encrypted']);
        unset($firewall['secret_key_encrypted']);

        return [
            'organization' => ['id' => $org->id, 'name' => $org->name, 'slug' => $org->slug],
            'general' => $settings['general'] + ['name' => $org->name],
            'security' => $settings['security'],
            'antivirus' => $settings['antivirus'],
            'smtp' => $smtp + ['password_set' => $hasPassword],
            'firewall' => $firewall + ['secret_key_set' => $hasSecret],
        ];
    }
}
