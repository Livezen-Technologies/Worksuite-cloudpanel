<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\AccessToken;
use App\Models\AiAction;
use App\Models\Organization;
use App\Services\Ai\AiToolService;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** UAI: AI permissions, MCP access tokens, activity and approvals. */
class AiController extends Controller
{
    public function __construct(private AiToolService $ai) {}

    private function org(Request $request): Organization
    {
        return Organization::findOrFail($request->user()->current_organization_id);
    }

    private function tokens(Request $request)
    {
        return AccessToken::where('tokenable_id', $request->user()->id)->where('organization_id', $request->user()->current_organization_id)->where('name', 'like', 'mcp:%');
    }

    private function presentAction(AiAction $a): array
    {
        return ['id' => $a->id, 'client' => $a->client, 'tool' => $a->tool, 'target' => $a->target, 'arguments' => $a->arguments, 'status' => $a->status,
            'error' => $a->error, 'created_at' => $a->created_at?->toIso8601ZuluString(), 'decided_at' => $a->decided_at?->toIso8601ZuluString()];
    }

    public function index(Request $request)
    {
        return $this->data([
            'mcp_url' => url('/api/v1/app/mcp'),
            'assistant' => ['available' => \App\Services\Ai\AssistantService::available(), 'model' => config('platform.ai.model')],
            'settings' => $this->ai->settings($this->org($request)),
            'levels' => AiToolService::LEVELS,
            'tools' => collect($this->ai->tools())->map(fn ($t, $n) => ['name' => $n, 'description' => $t['description'], 'write' => $t['write']])->values(),
            'tokens' => $this->tokens($request)->latest()->get()->map(fn ($t) => [
                'id' => $t->id, 'name' => substr($t->name, 4), 'write' => in_array('mcp.write', (array) $t->abilities, true),
                'last_used_at' => $t->last_used_at?->toIso8601ZuluString(), 'expires_at' => $t->expires_at?->toIso8601ZuluString(),
            ])->values(),
            'pending' => AiAction::where('status', 'pending')->latest()->limit(50)->get()->map(fn ($a) => $this->presentAction($a))->values(),
            'activity' => AiAction::latest()->limit(100)->get()->map(fn ($a) => $this->presentAction($a))->values(),
        ]);
    }

    public function settings(Request $request)
    {
        $v = $request->validate([
            'level' => ['sometimes', Rule::in(AiToolService::LEVELS)],
            'confirm_destructive' => 'sometimes|boolean', 'hide_secrets' => 'sometimes|boolean', 'audit' => 'sometimes|boolean', 'assistant' => 'sometimes|boolean',
        ]);
        $saved = $this->ai->saveSettings($this->org($request), $v);
        app(AuditLogger::class)->log('ai.settings', 'organization', $request->user()->current_organization_id, $v);

        return $this->data($saved);
    }

    public function storeToken(Request $request)
    {
        $v = $request->validate(['name' => 'required|string|min:2|max:60', 'write' => 'sometimes|boolean', 'expires_in_days' => 'sometimes|integer|between:1,365']);
        $user = $request->user();
        $token = $user->createToken('mcp:'.$v['name'], ! empty($v['write']) ? ['mcp', 'mcp.write'] : ['mcp'], now()->addDays($v['expires_in_days'] ?? 90));
        $token->accessToken->forceFill(['organization_id' => $user->current_organization_id])->save();
        app(AuditLogger::class)->log('ai.token.create', 'access_token', $token->accessToken->id, ['name' => $v['name'], 'write' => ! empty($v['write'])]);

        return $this->data(['id' => $token->accessToken->id, 'name' => $v['name'], 'write' => ! empty($v['write']),
            'expires_at' => $token->accessToken->expires_at?->toIso8601ZuluString(), 'token' => $token->plainTextToken], 201);
    }

    public function destroyToken(Request $request, string $id)
    {
        $this->tokens($request)->findOrFail($id)->delete();
        app(AuditLogger::class)->log('ai.token.revoke', 'access_token', $id);

        return response()->noContent();
    }

    public function decide(Request $request, string $id, string $decision)
    {
        $a = AiAction::findOrFail($id);
        $a = $decision === 'approve' ? $this->ai->approve($a, $request->user()) : $this->ai->reject($a, $request->user());

        return $this->data($this->presentAction($a));
    }

    private function conversation(Request $request): ?\App\Models\AiConversation
    {
        return \App\Models\AiConversation::where('user_id', $request->user()->id)->latest('updated_at')->first();
    }

    /** UAI: the person's current conversation with the assistant. */
    public function conversationShow(Request $request)
    {
        $c = $this->conversation($request);

        return $this->data(['id' => $c?->id, 'messages' => $c?->transcript ?? [], 'available' => \App\Services\Ai\AssistantService::available()]);
    }

    private function turnKey(Request $request): string
    {
        $key = 'ai-turns:'.$request->user()->current_organization_id.':'.now()->toDateString();
        $limit = (int) config('platform.ai.max_turns_per_day');
        if ((int) \Illuminate\Support\Facades\Cache::get($key, 0) >= $limit) {
            throw \App\Exceptions\ApiException::conflict("Your account has used today's {$limit} assistant messages. It resets at midnight UTC.", ['reason' => 'ai_daily_limit']);
        }

        return $key;
    }

    /** Counted once a reply came back: a message refused for set-up reasons doesn't use the allowance. */
    private function countTurn(string $key): void
    {
        \Illuminate\Support\Facades\Cache::add($key, 0, now()->endOfDay());
        \Illuminate\Support\Facades\Cache::increment($key);
    }

    public function ask(Request $request, \App\Services\Ai\AssistantService $assistant)
    {
        $v = $request->validate(['message' => 'required|string|min:1|max:4000']);
        $key = $this->turnKey($request);
        $r = $assistant->ask($request->user(), $this->conversation($request), $v['message']);
        $this->countTurn($key);

        return $this->data(['id' => $r['conversation']->id, 'reply' => $r['reply'], 'actions' => $r['actions'], 'messages' => $r['conversation']->transcript]);
    }

    /**
     * UAI: the same as ask(), as server-sent events: `text` pieces while the model writes,
     * `action` for each tool call, then `done` with the transcript (or `error`).
     */
    public function stream(Request $request, \App\Services\Ai\AssistantService $assistant)
    {
        $v = $request->validate(['message' => 'required|string|min:1|max:4000']);
        $key = $this->turnKey($request);
        if (! \App\Services\Ai\AssistantService::available()) {
            throw \App\Exceptions\ApiException::conflict('The assistant is not set up. An administrator needs to add an Anthropic API key to the environment.');
        }
        $user = $request->user();
        $conv = $this->conversation($request);

        return response()->stream(function () use ($assistant, $user, $conv, $v, $key) {
            $send = function (string $event, array $data) {
                echo "event: {$event}\ndata: ".json_encode($data, JSON_UNESCAPED_SLASHES)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            try {
                $r = $assistant->ask($user, $conv, $v['message'], fn (array $e) => $send($e['type'], $e));
                $this->countTurn($key);
                $send('done', ['id' => $r['conversation']->id, 'reply' => $r['reply'], 'actions' => $r['actions'], 'messages' => $r['conversation']->transcript]);
            } catch (\App\Exceptions\ApiException $e) {
                $send('error', ['message' => $e->getMessage()]);
            } catch (\Throwable $e) {
                report($e);
                $send('error', ['message' => 'The assistant could not answer. Try again in a moment.']);
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }

    /** Start over: the next message opens a new conversation. */
    public function conversationReset(Request $request)
    {
        \App\Models\AiConversation::where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }
}
