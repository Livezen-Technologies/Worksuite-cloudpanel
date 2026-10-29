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
            'type' => "{$req}|in:log,email,slack,teams,telegram,webhook,whatsapp,sms",
            'config' => 'nullable|array',
            'config.url' => 'nullable|url:https,http',
            // email wants an address; whatsapp and sms want a phone number.
            'config.to' => ['nullable', 'string', 'max:190', function ($attr, $value, $fail) {
                $type = request('type');
                if (in_array($type, ['whatsapp', 'sms'], true)) {
                    if (! preg_match('/^\+?[0-9]{6,20}$/', (string) $value)) {
                        $fail('The recipient must be a phone number in international format, for example +94771234567.');
                    }
                } elseif ($type === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $fail('The recipient must be a valid email address.');
                }
            }],
            // Credentials are stored in the channel's encrypted config.
            'config.bot_token' => 'nullable|string|max:255',
            'config.chat_id' => 'nullable|string|max:120',
            'config.phone_number_id' => 'nullable|string|max:60',
            'config.access_token' => 'nullable|string|max:500',
            'config.template' => 'nullable|string|max:120',
            'config.language' => 'nullable|string|max:10',
            'config.account_sid' => 'nullable|string|max:120',
            'config.auth_token' => 'nullable|string|max:255',
            'config.from' => 'nullable|string|max:40',
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
