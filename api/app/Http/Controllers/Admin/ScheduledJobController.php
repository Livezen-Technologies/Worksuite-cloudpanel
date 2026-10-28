<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Models\ScheduledJob;
use App\Services\ScheduledJobService;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ScheduledJobController extends CrudController
{
    protected string $model = ScheduledJob::class;

    protected array $filters = ['trigger', 'action', 'enabled'];

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:120",
            'trigger' => 'sometimes|in:cron,webhook,manual',
            'cron' => ['nullable', 'required_if:trigger,cron', 'string', function ($attr, $value, $fail) {
                $value === null || CronExpression::isValidExpression($value) || $fail('Use a valid cron expression.');
            }],
            'action' => ["{$req}", 'in:'.implode(',', ScheduledJobService::ACTIONS)],
            'params' => 'nullable|array',
            'params.url' => 'required_if:action,webhook|nullable|url:https',
            'enabled' => 'sometimes|boolean',
        ];
    }

    private ?string $webhookToken = null;

    protected function prepare(array $data, ?Model $model = null): array
    {
        if (($data['trigger'] ?? $model?->trigger) === 'webhook' && ! $model?->webhook_token_hash) {
            $this->webhookToken = Str::random(40);
            $data['webhook_token_hash'] = hash('sha256', $this->webhookToken);
        }

        return $data;
    }

    protected function saved(Model $model, bool $created): void
    {
        app(ScheduledJobService::class)->refreshNextRun($model);
    }

    protected function present(Model $model): array
    {
        return $model->toArray() + ($this->webhookToken ? ['webhook_url' => url('/api/v1/hooks/scheduled-jobs/'.$model->id.'/'.$this->webhookToken)] : []);
    }

    public function run(string $id, ScheduledJobService $service)
    {
        $job = $service->run(ScheduledJob::findOrFail($id));

        return $this->accepted($job);
    }
}
