<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Services\Authorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function data(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    /** 202 for long-running mutations: { data: { job_id, ... } }. */
    protected function accepted(Job $job, array $extra = []): JsonResponse
    {
        return response()->json(['data' => array_merge(['job_id' => $job->id, 'status' => $job->status], $extra)], 202);
    }

    /**
     * Staff hold a platform-scope role and administer every tenant, so their list
     * views must not be limited to their own organisation.
     */
    protected function seesAllTenants(Request $request): bool
    {
        $userId = $request->user()?->id;
        if (! $userId) {
            return false;
        }
        $role = \Illuminate\Support\Facades\DB::table('memberships')->where('user_id', $userId)->value('role');

        return $role !== null
            && \Illuminate\Support\Facades\DB::table('roles')->where('name', $role)->value('scope') === 'platform';
    }

    protected function authorizeFor(Request $request, string $permission, $resource = null): void
    {
        app(Authorizer::class)->authorize($request->user(), $permission, $resource, ! $request->isMethodSafe());
    }
}
