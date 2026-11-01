<?php

namespace App\Drivers;

use App\Contracts\ChatModel;

/** Tests: replies are queued in advance; every request is recorded. */
class FakeChatModel implements ChatModel
{
    /** @var list<array> */
    public array $queue = [];

    /** @var list<array{system:string, messages:array, tools:array}> */
    public array $requests = [];

    public function say(string $text): static
    {
        $this->queue[] = ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]], 'text' => $text, 'tool_uses' => []];

        return $this;
    }

    public function use(string $tool, array $input, string $id = 'tu_1'): static
    {
        $this->queue[] = ['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $tool, 'input' => $input]], 'text' => '', 'tool_uses' => [['id' => $id, 'name' => $tool, 'input' => $input]]];

        return $this;
    }

    public function refuse(): static
    {
        $this->queue[] = ['stop_reason' => 'refusal', 'content' => [], 'text' => '', 'tool_uses' => []];

        return $this;
    }

    public function reply(string $system, array $messages, array $tools): array
    {
        $this->requests[] = compact('system', 'messages', 'tools');

        return array_shift($this->queue) ?? ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'OK.']], 'text' => 'OK.', 'tool_uses' => []];
    }
}
