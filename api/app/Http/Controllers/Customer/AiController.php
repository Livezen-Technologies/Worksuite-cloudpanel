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
            'confirm_destructive' => 'sometimes|boolean', 'hide_secrets' => 'sometimes|boolean', 'audit' => 'sometimes|boolean',
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
}
