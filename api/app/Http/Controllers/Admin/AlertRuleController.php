<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Models\AlertRule;
use Illuminate\Database\Eloquent\Model;

class AlertRuleController extends CrudController
{
    protected string $model = AlertRule::class;

    protected array $filters = ['metric', 'severity', 'enabled', 'scope_type'];

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:120",
            'metric' => "{$req}|in:cpu,ram,disk,heartbeat,container_unhealthy,backup_failed,ssl_expiry",
            'operator' => 'sometimes|in:>,<,>=,<=',
            'threshold' => 'nullable|numeric',
            'duration_minutes' => 'sometimes|integer|min:0|max:1440',
            'severity' => 'sometimes|in:info,warning,critical,emergency',
            'scope_type' => 'sometimes|in:organization,server_group,server,application',
            'scope_id' => 'nullable|uuid',
            'channel_ids' => 'nullable|array', 'channel_ids.*' => 'uuid',
            'enabled' => 'sometimes|boolean',
        ];
    }
}
