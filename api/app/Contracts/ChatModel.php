<?php

namespace App\Contracts;

/** UAI: one model turn. The assistant service owns the tool loop. */
interface ChatModel
{
    /**
     * @param  list<array{role:string, content:mixed}>  $messages  the conversation so far, assistant content passed back unchanged
     * @param  list<array{name:string, description:string, inputSchema:array}>  $tools
     * @return array{stop_reason:string, content:mixed, text:string, tool_uses:list<array{id:string, name:string, input:array}>}
     */
    public function reply(string $system, array $messages, array $tools): array;

    /** As reply(), streaming: $onText receives each piece of text as the model writes it. */
    public function streamReply(string $system, array $messages, array $tools, callable $onText): array;
}
