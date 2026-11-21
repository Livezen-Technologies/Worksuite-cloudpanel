<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Services\MaintenanceScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public, read-only: whether an app has a scheduled maintenance window or is in maintenance now, so
 * the app can show its own in-app notice. Only the window and the admin's notice text are exposed.
 * Browsers may read it from the app's own verified domains (CORS); servers can always fetch it.
 */
class MaintenanceNoticeController extends Controller
{
    public function show(Request $request, string $app, MaintenanceScheduleService $schedule)
    {
        [$body, $origins] = Cache::remember('maintenance-notice:'.$app, 60, function () use ($app, $schedule) {
            $model = Application::allTenants()->find($app);

            return [$schedule->notice($model), $model ? MaintenanceScheduleService::origins($model) : []];
        });

        $headers = ['Cache-Control' => 'public, max-age=60', 'Vary' => 'Origin', 'X-Robots-Tag' => 'noindex'];
        $origin = strtolower((string) $request->headers->get('Origin'));
        if ($origin !== '' && in_array($origin, $origins, true)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Methods'] = 'GET, OPTIONS';
            $headers['Access-Control-Max-Age'] = '600';
        }

        return response()->json(['data' => $body], 200, $headers);
    }
}
