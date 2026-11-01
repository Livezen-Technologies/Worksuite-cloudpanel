<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Support\Lists;
use App\Support\Tenant;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    private function filtered(Request $request)
    {
        $query = AuditLog::where('organization_id', Tenant::current()->id);
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->filtered($request);

        return response()->json(Lists::paginate($query, $request, ['action', 'actor', 'resource_id'], ['created_at', 'id'], ['user_id', 'action', 'resource_type', 'resource_id']));
    }

    /** Max rows in one export; narrow the dates for more. */
    public const EXPORT_LIMIT = 100000;

    /**
     * CSV of the organisation's audit trail (date range, action and resource filters), streamed so a
     * large export never sits in memory. Includes each row's hash so the chain can be checked offline.
     * The export is itself audited.
     */
    public function export(Request $request, AuditLogger $audit)
    {
        $f = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'action' => 'nullable|string|max:120', 'resource_type' => 'nullable|string|max:60', 'user_id' => 'nullable|uuid']);
        $query = $this->filtered($request)
            ->when($f['action'] ?? null, fn ($q, $v) => str_ends_with($v, '*') ? $q->where('action', 'like', rtrim($v, '*').'%') : $q->where('action', $v))
            ->when($f['resource_type'] ?? null, fn ($q, $v) => $q->where('resource_type', $v))
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->orderBy('id');
        $total = (clone $query)->count();
        if ($total > self::EXPORT_LIMIT) {
            throw \App\Exceptions\ApiException::conflict("{$total} entries match; exports are limited to ".number_format(self::EXPORT_LIMIT).'. Narrow the date range.');
        }
        $audit->log('audit.export', 'audit_logs', null, array_filter($f) + ['rows' => $total]);
        $emails = \App\Models\User::whereIn('id', (clone $query)->reorder()->whereNotNull('user_id')->distinct()->pluck('user_id'))->pluck('email', 'id');
        $name = 'audit-log-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $emails) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'time_utc', 'actor', 'user_email', 'action', 'resource_type', 'resource_id', 'ip', 'request_id', 'details', 'prev_hash', 'hash']);
            $query->chunkById(1000, function ($rows) use ($out, $emails) {
                foreach ($rows as $r) {
                    $cells = [$r->id, $r->created_at?->toIso8601ZuluString(), $r->actor, $emails[$r->user_id] ?? '', $r->action, $r->resource_type, $r->resource_id, $r->ip, $r->request_id,
                        $r->meta ? json_encode($r->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '', $r->prev_hash, $r->hash];
                    // Spreadsheet formula injection: cells starting with = + - @ are prefixed with '.
                    fputcsv($out, array_map(fn ($c) => is_string($c) && $c !== '' && in_array($c[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$c : $c, $cells));
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function verify(AuditLogger $audit)
    {
        return $this->data($audit->verify(Tenant::current()->id));
    }
}
