<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupMember;
use App\Support\CustomerRules;
use Illuminate\Support\Facades\DB;

/**
 * CUST-5 phase 4: dynamic group membership and the pricing a group carries.
 *
 * Membership is *materialised* into customer_group_members with source='rule'
 * rather than evaluated on read, so every other read path -- the directory
 * filter, the profile, Finance -- stays a plain join and cannot be slowed down
 * by a complicated rule.
 */
class CustomerGroupService
{
    /** A preview shows this many matches; the count is of all of them. */
    public const PREVIEW = 25;

    public function __construct(private CustomerRules $rules) {}

    /**
     * Count and sample the accounts a predicate matches. Writes nothing: a
     * mis-typed rule must not be able to sweep accounts into a discount group
     * before somebody has looked at it.
     */
    public function preview(array $rules): array
    {
        $validated = $this->rules->validate($rules);
        $matched = $this->rules->apply($this->rules->base(), $validated);

        $total = (clone $matched)->count();
        $sample = $matched->orderBy('name')->limit(self::PREVIEW)->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'status' => $r->status,
                'customer_type' => $r->customer_type, 'country' => $r->country,
                'active_services' => (int) $r->active_services,
            ])->all();

        return ['rules' => $validated, 'total' => $total, 'sample' => $sample];
    }

    /**
     * Bring one dynamic group's membership in line with its rules.
     *
     * Only `source='rule'` rows are touched: an account somebody added by hand
     * stays, because a rule tightening is not a reason to undo a deliberate
     * decision. Returns what changed.
     */
    public function recompute(CustomerGroup $group, ?string $actorId = null): array
    {
        if ($group->kind !== 'dynamic') {
            throw ApiException::conflict('This group is filled by hand, so there is nothing to recompute.');
        }
        if (! $group->rules) {
            throw ApiException::validation(['rules' => ['This group has no rules yet.']]);
        }

        $should = $this->rules->apply($this->rules->base(), $this->rules->validate($group->rules))
            ->pluck('id')->all();

        $existing = CustomerGroupMember::allTenants()->where('customer_group_id', $group->id)->get();
        $byRule = $existing->where('source', 'rule')->pluck('organization_id')->all();
        $manual = $existing->where('source', '!=', 'rule')->pluck('organization_id')->all();

        // An account already in by hand needs no second row, and the unique
        // key on (group, organisation) would refuse one anyway.
        $added = array_values(array_diff($should, $byRule, $manual));
        $removed = array_values(array_diff($byRule, $should));

        DB::transaction(function () use ($group, $added, $removed, $should, $actorId) {
            foreach (array_chunk($added, 500) as $chunk) {
                foreach ($chunk as $orgId) {
                    CustomerGroupMember::create([
                        'organization_id' => $orgId, 'customer_group_id' => $group->id,
                        'source' => 'rule', 'added_by' => $actorId,
                    ]);
                }
            }
            if ($removed !== []) {
                CustomerGroupMember::allTenants()->where('customer_group_id', $group->id)
                    ->where('source', 'rule')->whereIn('organization_id', $removed)->delete();
            }
            $group->forceFill([
                'rules_run_at' => now(),
                'rules_matched' => count($should),
            ])->save();
        });

        return [
            'matched' => count($should),
            'added' => count($added),
            'removed' => count($removed),
            'kept_manual' => count($manual),
        ];
    }

    /** Every dynamic group with rules, recomputed. Returns the per-group result. */
    public function recomputeAll(): array
    {
        $out = [];
        foreach (CustomerGroup::where('kind', 'dynamic')->whereNotNull('rules')->get() as $group) {
            $out[$group->slug] = $this->recompute($group);
        }

        return $out;
    }
}
