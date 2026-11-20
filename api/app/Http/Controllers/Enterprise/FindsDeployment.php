<?php

namespace App\Http\Controllers\Enterprise;

use App\Exceptions\ApiException;
use App\Models\EnterpriseDeployment;
use App\Support\Tenant;
use Illuminate\Support\Str;

/** Deployments are addressed by the panel id or by Cloud Admin's own external_id, inside the tenant. */
trait FindsDeployment
{
    private function find(string $id): EnterpriseDeployment
    {
        $q = EnterpriseDeployment::where('organization_id', app(Tenant::class)->id());

        return (clone $q)->whereKey(Str::isUuid($id) ? $id : null)->first()
            ?? $q->where('external_id', $id)->first()
            ?? throw new ApiException('deployment_not_found', 'No such enterprise deployment.', 404);
    }
}
