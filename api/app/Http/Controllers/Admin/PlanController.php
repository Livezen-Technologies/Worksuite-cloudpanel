<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hosting packages. A package is the resource envelope a hosting customer is sold:
 * vCPU, RAM, storage, price, plus soft limits (apps, bandwidth, backups).
 */
class PlanController extends Controller
{
    public function index()
    {
        return $this->data(Plan::orderBy('sort')->orderBy('name')->get()->map(fn ($p) => $this->shape($p)));
    }

    public function show(string $id)
    {
        return $this->data($this->shape(Plan::findOrFail($id)));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $plan = Plan::create($data);

        return $this->data($this->shape($plan), 201);
    }

    public function update(Request $request, string $id)
    {
        $plan = Plan::findOrFail($id);
        $plan->update($this->validated($request, $plan->id));

        return $this->data($this->shape($plan->fresh()));
    }

    /** Refused while any subscription still points at the package. */
    public function destroy(string $id)
    {
        $plan = Plan::findOrFail($id);
        $inUse = Subscription::withoutGlobalScopes()->where('plan_id', $plan->id)->count();
        if ($inUse > 0) {
            return response()->json(['error' => [
                'code' => 'plan_in_use',
                'message' => "This package is assigned to {$inUse} customer(s). Move them to another package first.",
            ]], 409);
        }
        $plan->delete();

        return $this->data(['deleted' => true]);
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        $req = $request->isMethod('POST') ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$req, 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('plans', 'code')->ignore($ignoreId)],
            'name' => [$req, 'string', 'max:120'],
            'vcpu' => [$req, 'numeric', 'min:0.1', 'max:256'],
            'ram_mb' => [$req, 'integer', 'min:128', 'max:1048576'],
            'storage_gb' => [$req, 'integer', 'min:1', 'max:1048576'],
            'price_cents' => [$req, 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'limits' => ['sometimes', 'array'],
            'limits.apps' => ['sometimes', 'integer', 'min:0'],
            'limits.ram_mb' => ['sometimes', 'integer', 'min:0'],
            'limits.storage_gb' => ['sometimes', 'integer', 'min:0'],
            'limits.bandwidth_gb' => ['sometimes', 'integer', 'min:0'],
            'limits.backups_per_day' => ['sometimes', 'integer', 'min:0'],
            'limits.backup_retention_days' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    private function shape(Plan $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'vcpu' => $p->vcpu,
            'ram_mb' => $p->ram_mb,
            'storage_gb' => $p->storage_gb,
            'price_cents' => $p->price_cents,
            'currency' => $p->currency,
            'limits' => $p->limits ?? [],
            'sort' => $p->sort,
            'subscribers' => Subscription::withoutGlobalScopes()->where('plan_id', $p->id)->count(),
        ];
    }
}
