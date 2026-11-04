<?php

namespace App\Drivers;

use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use App\Contracts\ChatModel;

/** UAI: the assistant on the Claude API, through the official PHP SDK. */
class AnthropicChatModel implements ChatModel
{
    public function __construct(private string $apiKey, private string $model, private string $effort) {}

    private function args(string $system, array $messages, array $tools): array
    {
        return [
            'model' => $this->model,
            'maxTokens' => 16000,
            'system' => $system,
            'messages' => $messages,
            'tools' => $tools,
            'outputConfig' => ['effort' => $this->effort],
            // Server-side refusal fallbacks ("default" lets the API pick the fallback model).
            'fallbacks' => 'default',
            'betas' => ['server-side-fallback-2026-07-01'],
        ];
    }

    public function reply(string $system, array $messages, array $tools): array
    {
        $client = new Client(apiKey: $this->apiKey);

        return $this->normalise($client->beta->messages->create(...$this->args($system, $messages, $tools)));
    }

    public function streamReply(string $system, array $messages, array $tools, callable $onText): array
    {
        $client = new Client(apiKey: $this->apiKey);
        $acc = MessageAccumulator::forBetaMessages();
        foreach ($client->beta->messages->createStream(...$this->args($system, $messages, $tools)) as $event) {
            $acc->accumulate($event);
            if ($event->type === 'content_block_delta' && ($event->delta->type ?? null) === 'text_delta') {
                $onText((string) $event->delta->text);
            }
        }

        return $this->normalise($acc->message());
    }

    private function normalise($r): array
    {
        $text = '';
        $uses = [];
        $content = [];
        foreach ($r->content as $block) {
            // Rebuilt as plain arrays so the history stores as JSON and an empty tool input
            // goes back as {} rather than []. Thinking blocks keep their signature unchanged.
            $content[] = match ($block->type) {
                'text' => ['type' => 'text', 'text' => (string) $block->text],
                'tool_use' => ['type' => 'tool_use', 'id' => (string) $block->id, 'name' => (string) $block->name, 'input' => ((array) $block->input) ?: new \stdClass],
                'thinking' => ['type' => 'thinking', 'thinking' => (string) $block->thinking, 'signature' => (string) $block->signature],
                'redacted_thinking' => ['type' => 'redacted_thinking', 'data' => (string) $block->data],
                // No server tools are declared, so other block types aren't expected; keep their wire form.
                default => json_decode(json_encode($block->toProperties(), JSON_THROW_ON_ERROR), true),
            };
            if ($block->type === 'text') {
                $text .= $block->text;
            } elseif ($block->type === 'tool_use') {
                $uses[] = ['id' => (string) $block->id, 'name' => (string) $block->name, 'input' => (array) $block->input];
            }
        }

        return ['stop_reason' => (string) $r->stopReason, 'content' => $content, 'text' => $text, 'tool_uses' => $uses];
    }
}
