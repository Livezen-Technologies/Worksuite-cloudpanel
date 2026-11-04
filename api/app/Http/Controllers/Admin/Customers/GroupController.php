<?php

namespace App\Http\Controllers\Admin\Customers;

use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use App\Services\AuditLogger;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CUST-5: customer groups. Phase 4 adds dynamic membership rules and the
 * pricing hook; this is the storage, the twelve defaults and manual
 * membership, which the directory needs in order to filter by group.
 */
class GroupController extends Controller
{
    public function __construct(private CustomerService $customers) {}

    public function index()
    {
        $this->seedDefaults();
        $counts = DB::table('customer_group_members')->groupBy('customer_group_id')
            ->selectRaw('customer_group_id, count(*) as total')->pluck('total', 'customer_group_id');

        return $this->data(CustomerGroup::orderByDesc('is_system')->orderBy('name')->get()
            ->map(fn (CustomerGroup $g) => $this->shape($g, (int) ($counts[$g->id] ?? 0))));
    }

    public function store(Request $request)
    {
        $data = $this->rules($request);
        $data['slug'] = Str::slug($data['name']);
        if (CustomerGroup::where('slug', $data['slug'])->exists()) {
            throw \App\Exceptions\ApiException::conflict('A group with that name already exists.');
        }
        $group = CustomerGroup::create($data);
        app(AuditLogger::class)->log('customer.group.created', 'customer_group', $group->id, ['name' => $group->name]);

        return $this->data($this->shape($group, 0), 201);
    }

    public function update(Request $request, string $id)
    {
        $group = CustomerGroup::findOrFail($id);
        $data = $this->rules($request, $group);
        // A system group may be re-priced and re-coloured, but not renamed out
        // from under the rules and reports that name it.
        if ($group->is_system) {
            unset($data['name'], $data['kind']);
        }
        $group->fill($data)->save();
        app(AuditLogger::class)->log('customer.group.updated', 'customer_group', $group->id, ['fields' => array_keys($data)]);

        return $this->data($this->shape($group->fresh(), (int) DB::table('customer_group_members')->where('customer_group_id', $group->id)->count()));
    }

    public function destroy(string $id)
    {
        $group = CustomerGroup::findOrFail($id);
        if ($group->is_system) {
            throw \App\Exceptions\ApiException::conflict('This is a built-in group and cannot be deleted.');
        }
        $group->delete();   // membership rows cascade
        app(AuditLogger::class)->log('customer.group.deleted', 'customer_group', $id, ['name' => $group->name]);

        return response()->noContent();
    }

    private function rules(Request $request, ?CustomerGroup $existing = null): array
    {
        return $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:80'],
            'kind' => ['sometimes', Rule::in(['static', 'dynamic'])],
            'discount_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'colour' => ['sometimes', 'nullable', 'string', 'max:16'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
    }

    private function shape(CustomerGroup $g, int $members): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'slug' => $g->slug,
            'kind' => $g->kind,
            'discount_percent' => $g->discount_percent,
            'colour' => $g->colour,
            'description' => $g->description,
            'is_system' => $g->is_system,
            'members' => $members,
        ];
    }

    /** The twelve groups the spec names, created once and thereafter undeletable. */
    private function seedDefaults(): void
    {
        if (CustomerGroup::where('is_system', true)->exists()) {
            return;
        }
        foreach (CustomerService::DEFAULT_GROUPS as [$slug, $name, $kind]) {
            CustomerGroup::firstOrCreate(['slug' => $slug], [
                'name' => $name, 'kind' => $kind, 'is_system' => true,
            ]);
        }
    }
}
