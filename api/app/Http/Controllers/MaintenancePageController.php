<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Support\MaintenancePage;
use Illuminate\Support\Facades\Cache;

/**
 * The branded page a locked app shows the public. Traefik fetches it (a takeover router with a
 * replacePath, or an errors middleware) from the panel; it answers 503 with Retry-After so
 * crawlers and monitors read it as temporary.
 */
class MaintenancePageController extends Controller
{
    public function show(string $app)
    {
        $html = Cache::remember('maintenance-page:'.$app, 15, function () use ($app) {
            $model = Application::allTenants()->find($app);

            return $model ? [MaintenancePage::render($model), MaintenancePage::retryAfter($model)] : null;
        });
        [$body, $retry] = $html ?? [MaintenancePage::render(null), (int) config('platform.maintenance_page.retry_after', 300)];

        return response($body, 503, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Retry-After' => (string) $retry,
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
