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
            'general.default_region' => 'sometimes|string|max:60',
            'general.audit_retention_days' => 'sometimes|integer|min:30|max:3650',
            'general.heartbeat_interval' => 'sometimes|integer|min:15|max:30',
            'general.refresh_interval' => 'sometimes|integer|min:5|max:15',
            'security' => 'sometimes|array',
            'security.*' => 'boolean',
        ]);
        $org = Tenant::current();
        $settings = array_replace_recursive(config('platform.default_settings'), $org->settings ?? []);
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

        return ['organization' => ['id' => $org->id, 'name' => $org->name, 'slug' => $org->slug], 'general' => $settings['general'] + ['name' => $org->name], 'security' => $settings['security']];
    }
}
