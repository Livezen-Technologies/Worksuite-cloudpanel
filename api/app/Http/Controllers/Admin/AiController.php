<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAction;
use App\Models\AiConversation;
use App\Services\Ai\AssistantService;
use Illuminate\Http\Request;

/** UAI admin: how AI is set up on the platform and what it has done. */
class AiController extends Controller
{
    public function index(Request $request)
    {
        $all = $this->seesAllTenants($request);
        $actions = $all ? AiAction::allTenants() : AiAction::query();
        $conversations = $all ? AiConversation::allTenants() : AiConversation::query();
        $since = now()->subDay();

        return $this->data([
            'assistant' => [
                'available' => AssistantService::available(),
                'driver' => config('platform.ai.driver'),
                'model' => config('platform.ai.model'),
                'effort' => config('platform.ai.effort'),
                'max_turns_per_day' => (int) config('platform.ai.max_turns_per_day'),
                'key_set' => filled(config('platform.ai.api_key')),
            ],
            'mcp_url' => url('/api/v1/app/mcp'),
            'last_24h' => [
                'tool_calls' => (clone $actions)->where('created_at', '>=', $since)->count(),
                'changes' => (clone $actions)->where('created_at', '>=', $since)->whereIn('tool', ['restart_app', 'redeploy_app'])->count(),
                'pending' => (clone $actions)->where('status', 'pending')->count(),
                'refused' => (clone $actions)->where('created_at', '>=', $since)->where('status', 'refused')->count(),
                'conversations' => (clone $conversations)->where('updated_at', '>=', $since)->count(),
            ],
            'recent' => (clone $actions)->latest()->limit(50)->get()->map(fn ($a) => [
                'id' => $a->id, 'organization_id' => $a->organization_id, 'client' => $a->client, 'tool' => $a->tool, 'target' => $a->target,
                'status' => $a->status, 'error' => $a->error, 'created_at' => $a->created_at?->toIso8601ZuluString(),
            ])->values(),
        ]);
    }
}
