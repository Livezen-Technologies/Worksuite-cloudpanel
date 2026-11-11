<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The predicate behind a dynamic customer group (plan §2.9).
 *
 * Nothing the client sends reaches SQL: a field name is looked up in FIELDS and
 * replaced by this class's own expression, an operator in OPERATORS, and a
 * value is always a bound parameter. A field that is not on the list is a 422,
 * not a silently ignored clause, because a rule that quietly matches everyone
 * is how 250 accounts end up in a discount group.
 *
 * Shape:
 *   {"all": [{"field": "customer_type", "op": "eq", "value": "business"},
 *            {"any": [{"field": "country", "op": "in", "value": ["LK","SG"]}]}]}
 */
class CustomerRules
{
    /**
     * Allowed fields, mapped to a column of the derived table built by base().
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'status' => 'status',
        'customer_type' => 'customer_type',
        'onboarding_status' => 'onboarding_status',
        'country' => 'country',
        'plan_code' => 'plan_code',
        'subscription_status' => 'subscription_status',
        'days_since_registered' => 'days_since_registered',
        'days_since_activity' => 'days_since_activity',
        'active_services' => 'active_services',
        'active_applications' => 'active_applications',
        'active_vps' => 'active_vps',
        'active_domains' => 'active_domains',
        'lifetime_paid_cents' => 'lifetime_paid_cents',
    ];

    /** Fields that hold a number, so a string comparison would read oddly in the UI. */
    public const NUMERIC = [
        'days_since_registered', 'days_since_activity', 'active_services',
        'active_applications', 'active_vps', 'active_domains', 'lifetime_paid_cents',
    ];

    /** @var array<string, string> operator -> SQL */
    public const OPERATORS = [
        'eq' => '=', 'ne' => '!=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=',
        'in' => 'in', 'not_in' => 'not_in', 'contains' => 'contains',
        'is_null' => 'is_null', 'not_null' => 'not_null',
    ];

    /** Operators that take no value at all. */
    private const VALUELESS = ['is_null', 'not_null'];

    /** Operators whose value is a list. */
    private const LISTED = ['in', 'not_in'];

    /**
     * Validate a predicate, returning it normalised.
     *
     * @throws ApiException on anything not on the whitelist
     */
    public function validate(mixed $rules, int $depth = 0): array
    {
        if ($depth > 3) {
            throw ApiException::validation(['rules' => ['Group rules may not nest more than three deep.']]);
        }
        if (! is_array($rules) || $rules === []) {
            throw ApiException::validation(['rules' => ['A dynamic group needs at least one rule.']]);
        }

        $key = array_key_exists('any', $rules) ? 'any' : 'all';
        if (! is_array($rules[$key] ?? null) || $rules[$key] === []) {
            throw ApiException::validation(['rules' => ["Group rules need an \"all\" or \"any\" list with at least one clause."]]);
        }

        $clauses = [];
        foreach ($rules[$key] as $clause) {
            if (! is_array($clause)) {
                throw ApiException::validation(['rules' => ['Each rule must be a clause or a nested group.']]);
            }
            if (array_key_exists('all', $clause) || array_key_exists('any', $clause)) {
                $clauses[] = $this->validate($clause, $depth + 1);

                continue;
            }
            $clauses[] = $this->clause($clause);
        }

        return [$key => $clauses];
    }

    private function clause(array $clause): array
    {
        $field = $clause['field'] ?? null;
        $op = $clause['op'] ?? null;
        if (! is_string($field) || ! isset(self::FIELDS[$field])) {
            throw ApiException::validation(['rules' => ['Unknown rule field: '.(is_string($field) ? $field : gettype($field)).'.']]);
        }
        if (! is_string($op) || ! isset(self::OPERATORS[$op])) {
            throw ApiException::validation(['rules' => ['Unknown rule operator: '.(is_string($op) ? $op : gettype($op)).'.']]);
        }
        if (in_array($op, self::VALUELESS, true)) {
            return ['field' => $field, 'op' => $op];
        }

        $value = $clause['value'] ?? null;
        if (in_array($op, self::LISTED, true)) {
            if (! is_array($value) || $value === []) {
                throw ApiException::validation(['rules' => ["\"{$op}\" needs a non-empty list of values."]]);
            }
            $value = array_values(array_map(fn ($v) => $this->scalar($v, $field), $value));
        } else {
            $value = $this->scalar($value, $field);
        }

        return ['field' => $field, 'op' => $op, 'value' => $value];
    }

    private function scalar(mixed $value, string $field): string|int|float
    {
        if (is_bool($value) || is_null($value) || is_array($value)) {
            throw ApiException::validation(['rules' => ["Rule on \"{$field}\" needs a text or number value."]]);
        }
        if (in_array($field, self::NUMERIC, true)) {
            if (! is_numeric($value)) {
                throw ApiException::validation(['rules' => ["Rule on \"{$field}\" needs a number."]]);
            }

            return $value + 0;
        }

        return (string) $value;
    }

    /**
     * Every customer account with the rule fields resolved, as one derived
     * table. Computing them here rather than per row keeps a preview over
     * thousands of accounts a single query.
     */
    public function base(): Builder
    {
        $now = now();

        return DB::table('organizations as o')
            ->leftJoin('billing_profiles as bp', 'bp.organization_id', '=', 'o.id')
            ->leftJoinSub(
                DB::table('subscriptions as s')->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
                    ->select('s.organization_id', 's.status as subscription_status', 'p.code as plan_code')
                    // One account can hold several subscription rows; the newest is the live one.
                    ->whereRaw('s.created_at = (select max(s2.created_at) from subscriptions s2 where s2.organization_id = s.organization_id)'),
                'sub', 'sub.organization_id', '=', 'o.id'
            )
            ->leftJoinSub($this->counts('applications', 'deleted_at'), 'ca', 'ca.organization_id', '=', 'o.id')
            ->leftJoinSub($this->counts('vps'), 'cv', 'cv.organization_id', '=', 'o.id')
            ->leftJoinSub($this->counts('domains'), 'cd', 'cd.organization_id', '=', 'o.id')
            ->leftJoinSub(
                DB::table('invoices')->where('status', 'paid')
                    ->select('organization_id', DB::raw('coalesce(sum(amount_cents), 0) as paid_cents'))
                    ->groupBy('organization_id'),
                'ci', 'ci.organization_id', '=', 'o.id'
            )
            ->whereNull('o.archived_at')
            ->select([
                'o.id', 'o.name', 'o.status', 'o.customer_type', 'o.onboarding_status',
                DB::raw('bp.country as country'),
                DB::raw("coalesce(sub.plan_code, '') as plan_code"),
                DB::raw("coalesce(sub.subscription_status, '') as subscription_status"),
                DB::raw($this->daysSince('o.registered_at', $now).' as days_since_registered'),
                DB::raw($this->daysSince('o.last_activity_at', $now).' as days_since_activity'),
                DB::raw('coalesce(ca.total, 0) as active_applications'),
                DB::raw('coalesce(cv.total, 0) as active_vps'),
                DB::raw('coalesce(cd.total, 0) as active_domains'),
                DB::raw('coalesce(ca.total, 0) + coalesce(cv.total, 0) + coalesce(cd.total, 0) as active_services'),
                DB::raw('coalesce(ci.paid_cents, 0) as lifetime_paid_cents'),
            ]);
    }

    private function counts(string $table, ?string $softDelete = null): Builder
    {
        $q = DB::table($table)->select('organization_id', DB::raw('count(*) as total'))->groupBy('organization_id');

        return $softDelete ? $q->whereNull($softDelete) : $q;
    }

    /**
     * Whole days between a timestamp and now, as an integer, in both engines we
     * run on. A never-set stamp reads as a very large number so that "inactive
     * for more than 90 days" catches an account that has never been active.
     */
    private function daysSince(string $column, \DateTimeInterface $now): string
    {
        $at = "'".$now->format('Y-m-d H:i:s')."'";
        $expr = DB::connection()->getDriverName() === 'pgsql'
            ? "floor(extract(epoch from ({$at}::timestamp - {$column})) / 86400)"
            : "cast((julianday({$at}) - julianday({$column})) as integer)";

        return "cast(coalesce({$expr}, 99999) as integer)";
    }

    /**
     * Apply a validated predicate to a query over base().
     *
     * The whole predicate goes inside one nested group. Without that, an "any"
     * at the top level would read as `archived is null AND a OR b` -- which SQL
     * groups as `(archived is null AND a) OR b`, quietly matching rows the
     * caller had already excluded.
     */
    public function apply(Builder $query, array $rules): Builder
    {
        return $query->where(fn (Builder $q) => $this->compose($q, $rules));
    }

    private function compose(Builder $query, array $rules): void
    {
        $key = array_key_exists('any', $rules) ? 'any' : 'all';
        $boolean = $key === 'any' ? 'or' : 'and';

        foreach ($rules[$key] as $clause) {
            $nested = array_key_exists('all', $clause) || array_key_exists('any', $clause);
            $query->where(function (Builder $q) use ($clause, $nested) {
                $nested ? $this->compose($q, $clause) : $this->where($q, $clause);
            }, null, null, $boolean);
        }
    }

    private function where(Builder $q, array $clause): void
    {
        $column = self::FIELDS[$clause['field']];
        $op = $clause['op'];
        $value = $clause['value'] ?? null;

        // A numeric column is never compared against '': Postgres refuses the
        // cast outright, where SQLite would quietly accept it.
        $text = ! in_array($clause['field'], self::NUMERIC, true);

        match ($op) {
            'in' => $q->whereIn($column, $value),
            'not_in' => $q->whereNotIn($column, $value),
            'is_null' => $text
                ? $q->where(fn (Builder $w) => $w->whereNull($column)->orWhere($column, '=', ''))
                : $q->whereNull($column),
            'not_null' => $text
                ? $q->whereNotNull($column)->where($column, '!=', '')
                : $q->whereNotNull($column),
            'contains' => $q->where($column, 'like', '%'.self::escapeLike((string) $value).'%'),
            default => $q->where($column, self::OPERATORS[$op], $value),
        };
    }

    /**
     * A customer who typed a literal % into a rule value means that character,
     * not "anything", so the wildcards are escaped before the LIKE is built.
     */
    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /** The field list the UI builds its selects from. */
    public static function describe(): array
    {
        return [
            'fields' => array_map(fn (string $f) => [
                'key' => $f,
                'numeric' => in_array($f, self::NUMERIC, true),
            ], array_keys(self::FIELDS)),
            'operators' => array_keys(self::OPERATORS),
        ];
    }
}
