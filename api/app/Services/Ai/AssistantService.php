<?php

namespace App\Services\Ai;

use App\Contracts\ChatModel;
use App\Exceptions\ApiException;
use App\Models\AiConversation;
use App\Models\Organization;
use App\Models\User;

/**
 * UAI: the built-in assistant. It uses exactly the MCP tools, through AiToolService, so the
 * account's AI level and approvals apply the same way; changes in "ask" mode wait in the panel.
 */
class AssistantService
{
    public const MAX_STEPS = 6;

    public function __construct(private ChatModel $model, private AiToolService $tools) {}

    public static function available(): bool
    {
        return config('platform.ai.driver') === 'fake' || filled(config('platform.ai.api_key'));
    }

    private function system(Organization $org): string
    {
        $level = $this->tools->settings($org)['level'];

        return "You are the hosting assistant for {$org->name} in ".config('app.name').'. You help with the apps in this account only, using the tools provided. '
            ."The account's AI level is {$level}: changes may be refused (read_only) or wait for a person's approval in the panel (ask). "
            .'When a change is waiting for approval, say so plainly and do not claim it happened. '
            .'Be brief and concrete. If no tool can do what was asked, say so instead of guessing.';
    }

    private function toolDefs(): array
    {
        return collect($this->tools->tools())->map(fn ($t, $name) => ['name' => $name, 'description' => $t['description'], 'inputSchema' => $t['schema']])->values()->all();
    }

    /** @return array{conversation: AiConversation, reply: string, actions: list<array>} */
    public function ask(User $user, ?AiConversation $conv, string $text, ?callable $onEvent = null): array
    {
        if (! self::available()) {
            throw ApiException::conflict('The assistant is not set up. An administrator needs to add an Anthropic API key to the environment.');
        }
        $org = Organization::findOrFail($user->current_organization_id);
        if (! ($this->tools->settings($org)['assistant'] ?? true)) {
            throw ApiException::conflict('The assistant is turned off for this account.');
        }
        $conv ??= new AiConversation(['user_id' => $user->id, 'turns' => 0]);
        $messages = $conv->messages();
        $messages[] = ['role' => 'user', 'content' => $text];
        $actions = [];
        $reply = '';
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            try {
                $r = $onEvent
                    ? $this->model->streamReply($this->system($org), $messages, $this->toolDefs(), fn (string $t) => $onEvent(['type' => 'text', 'text' => $t]))
                    : $this->model->reply($this->system($org), $messages, $this->toolDefs());
            } catch (\Throwable $e) {
                report($e);
                throw ApiException::conflict('The assistant could not be reached. Try again in a moment.');
            }
            if ($r['stop_reason'] === 'refusal') {
                $reply = "I can't help with that request.";
                $onEvent && $onEvent(['type' => 'text', 'text' => $reply]);
                // A refused turn has no usable content; the user message stays so the history stays valid.
                $messages[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $reply]]];
                break;
            }
            $messages[] = ['role' => 'assistant', 'content' => $r['content']];
            $reply = $r['text'];
            if ($r['stop_reason'] !== 'tool_use' || ! $r['tool_uses']) {
                break;
            }
            $results = [];
            foreach ($r['tool_uses'] as $use) {
                [$status, $out, $action] = $this->tools->call($user, 'Assistant', null, $use['name'], (array) $use['input'], true);
                $actions[] = ['id' => $action->id, 'tool' => $use['name'], 'target' => $action->target, 'status' => $status];
                $onEvent && $onEvent(['type' => 'action'] + end($actions));
                $results[] = ['type' => 'tool_result', 'toolUseID' => $use['id'],
                    'content' => is_string($out) ? $out : json_encode($out, JSON_UNESCAPED_SLASHES), 'isError' => in_array($status, ['failed', 'refused'], true)];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
            if ($step === self::MAX_STEPS - 1) {
                $reply = 'I stopped after several steps. Ask me to continue if you need more.';
            }
        }
        $transcript = (array) ($conv->transcript ?? []);
        $transcript[] = ['role' => 'user', 'text' => $text, 'at' => now()->toIso8601ZuluString()];
        $transcript[] = ['role' => 'assistant', 'text' => $reply, 'actions' => $actions, 'at' => now()->toIso8601ZuluString()];
        $conv->setMessages($messages);
        $conv->transcript = $transcript;
        $conv->turns = (int) $conv->turns + 1;
        $conv->save();

        return ['conversation' => $conv, 'reply' => $reply, 'actions' => $actions];
    }
}
