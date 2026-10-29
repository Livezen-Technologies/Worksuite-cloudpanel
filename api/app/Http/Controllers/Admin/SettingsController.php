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
            'security' => 'sometimes|array',
            'security.*' => 'boolean',
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

        foreach (['general', 'security'] as $section) {
            foreach ($data[$section] ?? [] as $key => $value) {
                if (array_key_exists($key, config("platform.default_settings.{$section}"))) {
                    $settings[$section][$key] = $value;
                }
            }
        }
        $org->update(array_filter(['name' => $data['name'] ?? null, 'settings' => $settings, 'default_region' => $settings['general']['default_region']]));

        return $this->data($this->present());
    }

    private function present(): array
    {
        $org = Tenant::current()->fresh();
        $settings = array_replace_recursive(config('platform.default_settings'), $org->settings ?? []);

        $smtp = $settings['smtp'] ?? [];
        $hasPassword = ! empty($smtp['password_encrypted']);
        unset($smtp['password_encrypted']);

        return [
            'organization' => ['id' => $org->id, 'name' => $org->name, 'slug' => $org->slug],
            'general' => $settings['general'] + ['name' => $org->name],
            'security' => $settings['security'],
            'smtp' => $smtp + ['password_set' => $hasPassword],
        ];
    }
}
