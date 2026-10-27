<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Container;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Resource- and action-scoped checks (SEC-001, SEC-005): role grant, token
 * abilities, 'assigned' constraint (developers) and 'read' constraint.
 */
class Authorizer
{
    public function authorize(User $user, string $permission, ?Model $resource = null, bool $write = false): array
    {
        $grant = $user->grant($permission);
        if (! $grant) {
            throw ApiException::forbidden("Your role does not allow '{$permission}'.");
        }
        $token = $user->currentAccessToken();
        if ($token && method_exists($token, 'can') && ! $token->can('*') && ! $token->can($permission) && ! (! $write && $token->can('read'))) {
            throw ApiException::forbidden("This API token lacks the '{$permission}' ability.");
        }
        if ($grant['constraint'] === 'read' && $write) {
            throw ApiException::forbidden('Your role has read-only access.');
        }
        if ($grant['constraint'] === 'assigned' && $resource && ! $this->isAssigned($user, $resource)) {
            throw ApiException::forbidden('This resource is not assigned to you.');
        }

        return $grant;
    }

    public function isAssigned(User $user, Model $resource): bool
    {
        $applicationId = match (true) {
            $resource instanceof Application => $resource->id,
            $resource instanceof Deployment, $resource instanceof Container => $resource->application_id,
            default => $resource->application_id ?? null,
        };

        return $applicationId && \DB::table('application_members')->where('application_id', $applicationId)->where('user_id', $user->id)->exists();
    }

    /** Applies the 'assigned' constraint to list queries. */
    public function scopeAssigned(User $user, string $permission, $query, string $column = 'application_id')
    {
        if (($user->grant($permission)['constraint'] ?? null) === 'assigned') {
            $query->whereIn($column, \DB::table('application_members')->where('user_id', $user->id)->select('application_id'));
        }

        return $query;
    }
}
