<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Models\NotificationChannel;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;

class NotificationChannelController extends CrudController
{
    protected string $model = NotificationChannel::class;

    protected array $filters = ['type', 'enabled'];

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:120",
            'type' => "{$req}|in:log,email,slack,teams,telegram,webhook",
            'config' => 'nullable|array',
            'config.url' => 'nullable|url:https,http',
            'config.to' => 'nullable|email',
            'enabled' => 'sometimes|boolean',
        ];
    }

    protected function present(Model $c): array
    {
        $config = $c->config ?? [];

        return $c->toArray() + ['target' => isset($config['url']) ? parse_url($config['url'], PHP_URL_HOST) : ($config['to'] ?? $config['chat_id'] ?? null)];
    }

    public function test(string $id, NotificationService $notifications)
    {
        $ok = $notifications->send(NotificationChannel::findOrFail($id), 'Test notification', 'This is a test from Docker Manager Portal.');

        return $this->data(['delivered' => $ok]);
    }
}
