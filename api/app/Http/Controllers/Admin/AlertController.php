<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Support\Lists;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(Lists::paginate(Alert::query(), $request, ['title'], ['created_at', 'severity'], ['status', 'severity', 'resource_id', 'alert_rule_id']));
    }

    public function ack(Request $request, string $id)
    {
        $alert = Alert::findOrFail($id);
        if ($alert->status === 'open') {
            $alert->update(['status' => 'acknowledged', 'acknowledged_by' => $request->user()->id, 'acknowledged_at' => now()]);
        }

        return $this->data($alert);
    }

    /** Close an alert by hand (e.g. fixed outside the panel). If the condition still holds, the next check opens a new one. */
    public function resolve(Request $request, string $id)
    {
        $alert = Alert::findOrFail($id);
        $note = $request->validate(['note' => 'nullable|string|max:500'])['note'] ?? null;
        if ($alert->status !== 'resolved') {
            $alert->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $request->user()->id, 'snoozed_until' => null]);
            app(\App\Services\AuditLogger::class)->log('alert.resolve', 'alerts', $alert->id, array_filter(['title' => $alert->title, 'note' => $note]));
        }

        return $this->data($alert->fresh());
    }

    /** Keep the alert open but silence reminders and escalation for a while; 0 minutes wakes it now. */
    public function snooze(Request $request, string $id)
    {
        $alert = Alert::findOrFail($id);
        $minutes = (int) $request->validate(['minutes' => 'required|integer|min:0|max:10080'])['minutes'];
        if ($alert->status === 'resolved') {
            throw \App\Exceptions\ApiException::conflict('This alert is already resolved.');
        }
        $alert->update(['snoozed_until' => $minutes ? now()->addMinutes($minutes) : null]);
        app(\App\Services\AuditLogger::class)->log($minutes ? 'alert.snooze' : 'alert.unsnooze', 'alerts', $alert->id, ['title' => $alert->title] + ($minutes ? ['minutes' => $minutes] : []));

        return $this->data($alert->fresh());
    }
}
