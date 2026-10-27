<?php

namespace App\Services;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notification channels (MON-005). Drivers: log, webhook (also used for
 * Slack/Teams incoming webhooks and the Telegram bot API), email.
 */
class NotificationService
{
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
                    Mail::raw($message, fn ($m) => $m->to($config['to'])->subject($title));
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
