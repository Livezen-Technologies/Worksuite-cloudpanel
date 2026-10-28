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
}
