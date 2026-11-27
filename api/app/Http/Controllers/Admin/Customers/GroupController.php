<?php

namespace App\Http\Controllers\Admin\Customers;

use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use App\Services\AuditLogger;
use App\Services\CustomerGroupService;
use App\Services\CustomerService;
use App\Support\CustomerRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CUST-5: customer groups -- storage, the twelve defaults, manual membership,
 * and (phase 4) the rules that fill a dynamic group plus the discount and plan
 * overrides Finance reads when it bills.
 */
class GroupController extends Controller
{
    public function __construct(
        private CustomerService $customers,
        private CustomerGroupService $groups,
        private CustomerRules $predicates,
    ) {}

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

    /**
     * What a predicate would match, without writing anything. The rules may
     * come from the request (while the operator is still typing) or from the
     * saved group.
     */
    public function preview(Request $request, string $id)
    {
        $group = CustomerGroup::findOrFail($id);
        $rules = $request->input('rules') ?: $group->rules;
        if (! $rules) {
            throw \App\Exceptions\ApiException::validation(['rules' => ['There are no rules to preview.']]);
        }

        return $this->data($this->groups->preview($rules));
    }

    /** Materialise a dynamic group's membership now rather than at the next nightly run. */
    public function recompute(Request $request, string $id)
    {
        $group = CustomerGroup::findOrFail($id);
        $result = $this->groups->recompute($group, $request->user()?->id);
        app(AuditLogger::class)->log('customer.group.recomputed', 'customer_group', $group->id, $result);

        return $this->data($result);
    }

    /** The fields and operators a rule may use, for the builder's selects. */
    public function fields()
    {
        return $this->data(CustomerRules::describe());
    }

    private function rules(Request $request, ?CustomerGroup $existing = null): array
    {
        $data = $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:80'],
            'kind' => ['sometimes', Rule::in(['static', 'dynamic'])],
            'discount_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'colour' => ['sometimes', 'nullable', 'string', 'max:16'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'rules' => ['sometimes', 'nullable', 'array'],
            'plan_overrides' => ['sometimes', 'nullable', 'array'],
            'plan_overrides.*' => ['integer', 'min:0'],
        ]);

        // A rule set is checked against the whitelist here, not when the
        // scheduler next runs it: a group saved with a bad field would
        // otherwise fail silently every night.
        if (($data['rules'] ?? null)) {
            $data['rules'] = $this->predicates->validate($data['rules']);
        }
        if (array_key_exists('plan_overrides', $data) && $data['plan_overrides']) {
            $known = \App\Models\Plan::whereIn('id', array_keys($data['plan_overrides']))->pluck('id')->all();
            if ($unknown = array_diff(array_keys($data['plan_overrides']), $known)) {
                throw \App\Exceptions\ApiException::validation(['plan_overrides' => ['Unknown plan: '.implode(', ', $unknown).'.']]);
            }
        }

        return $data;
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
            'rules' => $g->rules,
            'plan_overrides' => $g->plan_overrides,
            'rules_run_at' => $g->rules_run_at?->toIso8601String(),
            'rules_matched' => $g->rules_matched,
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
