<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiToolService;
use Illuminate\Http\Request;

/**
 * UAI: a Model Context Protocol server (Streamable HTTP, JSON responses) over the
 * customer's own apps. Accepts tokens with the "mcp" ability (or a full-access token).
 */
class McpController extends Controller
{
    public const PROTOCOL = '2025-06-18';

    public function __construct(private AiToolService $tools) {}

    public function handle(Request $request)
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $abilities = $token && method_exists($token, 'can') ? $token : null;
        if ($abilities && ! $abilities->can('*') && ! $abilities->can('mcp')) {
            return $this->error(null, -32001, 'This token cannot use MCP. Create an AI access token in AI assistant → Connect AI.', 403);
        }
        if (! $user->grant('app.manage')) {
            return $this->error(null, -32001, 'Your role does not allow managing apps.', 403);
        }
        $msg = $request->json()->all();
        if (array_is_list($msg) && $msg !== []) {
            return $this->error(null, -32600, 'Batches are not supported.');
        }
        $id = $msg['id'] ?? null;
        $method = (string) ($msg['method'] ?? '');
        if (($msg['jsonrpc'] ?? null) !== '2.0' || $method === '') {
            return $this->error($id, -32600, 'Not a JSON-RPC 2.0 request.');
        }
        if (! array_key_exists('id', $msg)) {
            return response()->noContent(202); // a notification, e.g. notifications/initialized
        }
        $params = (array) ($msg['params'] ?? []);

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => config('app.name').' MCP', 'version' => '1.0'],
                'instructions' => 'Tools act on the apps in this account only. Changes may wait for a person to approve them in the panel.',
            ]),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => collect($this->tools->tools())->map(fn ($t, $name) => [
                'name' => $name, 'description' => $t['description'], 'inputSchema' => $t['schema'],
                'annotations' => ['readOnlyHint' => ! $t['write'], 'destructiveHint' => false],
            ])->values()]),
            'tools/call' => $this->call($request, $id, $params),
            default => $this->error($id, -32601, "Method {$method} is not supported."),
        };
    }

    private function call(Request $request, $id, array $params)
    {
        $name = (string) ($params['name'] ?? '');
        if (! isset($this->tools->tools()[$name])) {
            return $this->error($id, -32602, "Unknown tool {$name}.");
        }
        $token = $request->user()->currentAccessToken();
        $mayWrite = ! $token || ! method_exists($token, 'can') || $token->can('*') || $token->can('mcp.write');
        $client = $token && isset($token->name) && $token->name !== 'session' ? preg_replace('/^mcp:/', '', $token->name) : 'Panel session';
        [$status, $out] = $this->tools->call($request->user(), $client, $token?->id ?? null, $name, (array) ($params['arguments'] ?? []), $mayWrite);
        $text = is_string($out) ? $out : json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return $this->result($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => in_array($status, ['failed', 'refused'], true)]
            + (is_array($out) ? ['structuredContent' => ['result' => $out]] : []));
    }

    private function result($id, $result)
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error($id, int $code, string $message, int $http = 200)
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $http);
    }
}
