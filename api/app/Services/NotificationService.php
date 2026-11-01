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

    /** Per-channel webhook signing secret: derived, so nothing extra is stored; shown to admins. */
    public static function signingSecret(NotificationChannel $channel): string
    {
        // A secret typed into the channel form wins (it was accepted before but never used to sign anything).
        return (string) (($channel->config['secret'] ?? null) ?: 'whsec_'.hash_hmac('sha256', 'notification-channel:'.$channel->id, (string) config('app.key')));
    }

    /** The error of the last failed send(), for the channel test. */
    public ?string $lastError = null;

    public function send(NotificationChannel $channel, string $title, string $message, array $context = []): bool
    {
        $config = $channel->config ?? [];
        $this->lastError = null;
        try {
            switch ($channel->type) {
                case 'webhook':
                    // Signed: receivers recompute HMAC-SHA256(secret, "<timestamp>.<body>") and compare.
                    $body = json_encode(['title' => $title, 'message' => $message, 'context' => $context, 'channel_id' => $channel->id], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $ts = (string) time();
                    Http::timeout(10)->withHeaders([
                        'X-DMP-Timestamp' => $ts,
                        'X-DMP-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, self::signingSecret($channel)),
                    ])->withBody($body, 'application/json')->post($config['url'])->throw();
                    break;
                case 'slack':
                    Http::timeout(10)->post($config['url'], ['text' => "*{$title}*\n{$message}"])->throw();
                    break;
                case 'teams':
                    Http::timeout(10)->post($config['url'], ['title' => $title, 'text' => $message])->throw();
                    break;
                case 'telegram':
                    Http::timeout(10)->post("https://api.telegram.org/bot{$config['bot_token']}/sendMessage", ['chat_id' => $config['chat_id'], 'text' => "{$title}\n{$message}"])->throw();
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
            $channel->forceFill(['last_delivered_at' => now(), 'consecutive_failures' => 0, 'last_error' => null])->saveQuietly();

            return true;
        } catch (\Throwable $e) {
            // Never the URL or token: an HTTP error says only its status; others their message.
            $this->lastError = $e instanceof \Illuminate\Http\Client\RequestException ? 'HTTP '.$e->response->status().' from the receiver' : mb_substr($e->getMessage(), 0, 300);
            $this->lastError = str_replace(array_filter([$config['bot_token'] ?? null, $config['url'] ?? null, $config['auth_token'] ?? null, $config['access_token'] ?? null]), '•••', $this->lastError);
            $channel->forceFill(['last_failed_at' => now(), 'last_error' => $this->lastError, 'consecutive_failures' => $channel->consecutive_failures + 1])->saveQuietly();
            Log::warning("Notification channel {$channel->id} failed: {$this->lastError}");

            return false;
        }
    }
}
