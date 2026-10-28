<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TerminalSession;
use App\Models\User;
use App\Services\TerminalService;
use Illuminate\Http\Request;

/** Logs & Terminal: terminal session history and recordings (LOG-002). */
class TerminalSessionController extends Controller
{
    public function index(Request $request)
    {
        $q = TerminalSession::query()->with('container')->latest();
        if ($cid = $request->query('container_id')) {
            $q->where('container_id', $cid);
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = $q->paginate($perPage);
        $emails = User::whereIn('id', collect($page->items())->pluck('user_id')->unique())->pluck('email', 'id');

        return response()->json([
            'data' => collect($page->items())->map(function (TerminalSession $s) use ($emails) {
                $s->user_email = $emails[$s->user_id] ?? null;

                return TerminalService::present($s);
            })->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** GET /terminal-sessions/{id}/recording → {data:{session_id, format, cast}} (asciicast v2 JSON lines). */
    public function recording(string $id, TerminalService $terminals)
    {
        $session = TerminalSession::findOrFail($id);
        $cast = $terminals->recording($session);
        abort_if($cast === null, 404);

        return $this->data(['session_id' => $session->id, 'format' => 'asciicast-v2', 'cast' => $cast]);
    }
}
