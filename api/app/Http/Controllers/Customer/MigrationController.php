<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\SiteMigration;
use App\Services\MigrationService;
use Illuminate\Http\Request;

/** UMIG-001/003: a customer asks to move a cPanel account and follows its progress. */
class MigrationController extends Controller
{
    public function __construct(private MigrationService $migrations) {}

    public function index()
    {
        return $this->data(SiteMigration::query()->latest()->get()->map(fn (SiteMigration $m) => $this->present($m)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'archive_url' => 'required|url:https,http|max:2000', 'sha256' => 'nullable|string|regex:/^[a-fA-F0-9]{64}$/',
            'notes' => 'nullable|string|max:2000',
        ]);
        if (SiteMigration::query()->where('status', 'requested')->count() >= 5) {
            throw \App\Exceptions\ApiException::conflict('You already have five requests waiting for review.');
        }

        return $this->data($this->present($this->migrations->request($data, $request->user()->id)), 201);
    }

    private function present(SiteMigration $m): array
    {
        return [
            'id' => $m->id, 'status' => $m->status, 'notes' => $m->notes, 'decision' => $m->decision,
            'sites' => collect($m->sites ?? [])->where('enabled', true)->map(fn ($s) => ['domain' => $s['domain'], 'status' => $s['status'], 'temp_url' => $s['temp_url'] ?? null])->values(),
            'timeline' => $m->timeline ?? [], 'created_at' => $m->created_at?->toIso8601ZuluString(),
        ];
    }
}
