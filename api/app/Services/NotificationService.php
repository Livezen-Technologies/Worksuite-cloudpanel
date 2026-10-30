<?php

namespace App\Services;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notification channels (MON-005). Drivers: log, webhook (also used for
 * Slack/Teams incoming webhooks), email, telegram, whatsapp and sms.
 *
 * whatsapp is the WhatsApp Cloud API: a template message when the channel
 * names one (templates are required to open a conversation), otherwise a plain
 * text message, which only delivers inside an existing 24-hour window.
 * sms is Twilio. Both read their credentials from the channel's encrypted
 * config; nothing here logs a token.
 */
class NotificationService
{
    /**
     * WhatsApp Cloud API. A free-form text message is only delivered if the
     * recipient messaged the business in the last 24 hours, so a channel with
     * a template name uses the template instead and always arrives.
     */
    private function whatsapp(array $config, string $title, string $message): void
    {
        $url = 'https://graph.facebook.com/v21.0/'.$config['phone_number_id'].'/messages';
        $payload = ['messaging_product' => 'whatsapp', 'to' => $config['to']];

        if (! empty($config['template'])) {
            $payload += [
                'type' => 'template',
                'template' => [
                    'name' => $config['template'],
                    'language' => ['code' => $config['language'] ?? 'en'],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => str($title)->limit(60)->toString()],
                            ['type' => 'text', 'text' => str($message)->limit(300)->toString()],
                        ],
                    ]],
                ],
            ];
        } else {
            $payload += ['type' => 'text', 'text' => ['body' => "*{$title}*\n{$message}"]];
        }

        Http::timeout(10)->withToken($config['access_token'])->post($url, $payload)->throw();
    }

    public function send(NotificationChannel $channel, string $title, string $message, array $context = []): bool
    {
        $config = $channel->config ?? [];
        try {
            switch ($channel->type) {
                case 'webhook':
                    Http::timeout(10)->post($config['url'], ['title' => $title, 'message' => $message, 'context' => $context]);
                    break;
                case 'slack':
                    Http::timeout(10)->post($config['url'], ['text' => "*{$title}*\n{$message}"]);
                    break;
                case 'teams':
                    Http::timeout(10)->post($config['url'], ['title' => $title, 'text' => $message]);
                    break;
                case 'telegram':
                    Http::timeout(10)->post("https://api.telegram.org/bot{$config['bot_token']}/sendMessage", ['chat_id' => $config['chat_id'], 'text' => "{$title}\n{$message}"]);
                    break;
                case 'email':
                    // EML: the branded "alert" email (HTML and plain text).
                    app(EmailTemplateService::class)->send('alert', $config['to'], ['title' => $title, 'message' => $message, 'severity' => (string) ($context['severity'] ?? 'alert')]);
                    break;
                case 'whatsapp':
                    $this->whatsapp($config, $title, $message);
                    break;
                case 'sms':
                    // Twilio truncates at 1600; keep well inside one message.
                    Http::timeout(10)
                        ->withBasicAuth($config['account_sid'], $config['auth_token'])
                        ->asForm()
                        ->post("https://api.twilio.com/2010-04-01/Accounts/{$config['account_sid']}/Messages.json", [
                            'From' => $config['from'],
                            'To' => $config['to'],
                            'Body' => str("{$title}: {$message}")->limit(300),
                        ])->throw();
                    break;
                default:
                    Log::info("[notification:{$channel->name}] {$title}: {$message}", $context);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning("Notification channel {$channel->id} failed: {$e->getMessage()}");

            return false;
        }
    }
}
