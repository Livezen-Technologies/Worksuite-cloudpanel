<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\StatusPageService;
use Illuminate\Http\Request;

/** Monitoring: the public status page (settings for admins, the page itself for everyone). */
class StatusPageController extends Controller
{
    public function __construct(private StatusPageService $status) {}

    public function show()
    {
        $s = $this->status->settings();

        return $this->data($s + [
            'domains' => Domain::query()->where('dns_status', 'verified')->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'url' => rtrim((string) config('platform.panel_url'), '/').'/status',
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean', 'title' => 'sometimes|string|max:80', 'notice' => 'sometimes|nullable|string|max:500',
            'components' => 'sometimes|array|max:20', 'components.*.name' => 'required|string|max:60', 'components.*.domain_id' => 'required|uuid',
        ]);

        return $this->data($this->status->save($data, $request->user()->id));
    }

    public function public()
    {
        $page = $this->status->public();

        return $page ? response()->json(['data' => $page])->header('Cache-Control', 'public, max-age=60')
            : response()->json(['error' => ['code' => 'not_found', 'message' => 'There is no status page.']], 404);
    }
}
