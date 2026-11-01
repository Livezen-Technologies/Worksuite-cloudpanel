<?php

namespace App\Drivers;

use Anthropic\Client;
use App\Contracts\ChatModel;

/** UAI: the assistant on the Claude API, through the official PHP SDK. */
class AnthropicChatModel implements ChatModel
{
    public function __construct(private string $apiKey, private string $model, private string $effort) {}

    public function reply(string $system, array $messages, array $tools): array
    {
        $client = new Client(apiKey: $this->apiKey);
        // Beta call for server-side refusal fallbacks ("default" lets the API pick the fallback model).
        $r = $client->beta->messages->create(
            model: $this->model,
            maxTokens: 16000,
            system: $system,
            messages: $messages,
            tools: $tools,
            outputConfig: ['effort' => $this->effort],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );
        $text = '';
        $uses = [];
        $content = [];
        foreach ($r->content as $block) {
            // Rebuilt as plain arrays so the history serialises cleanly and an empty tool input
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
