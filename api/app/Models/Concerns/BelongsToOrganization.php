<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Organisation scoping (SEC-001): every query is limited to the current
 * tenant and new rows are stamped with it. Rows of another organisation
 * are invisible, so route-model binding returns 404 for them.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            $orgId = app(Tenant::class)->id();
            if ($orgId !== null) {
                $builder->where($builder->getModel()->getTable().'.organization_id', $orgId);
            }
        });

        static::creating(function ($model) {
            if (empty($model->organization_id)) {
                $model->organization_id = app(Tenant::class)->id();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function allTenants(): Builder
    {
        return static::withoutGlobalScope('organization');
    }
}
