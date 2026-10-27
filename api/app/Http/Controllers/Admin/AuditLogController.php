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
    public function index(Request $request)
    {
        $query = AuditLog::where('organization_id', Tenant::current()->id);
        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        return response()->json(Lists::paginate($query, $request, ['action', 'actor', 'resource_id'], ['created_at', 'id'], ['user_id', 'action', 'resource_type', 'resource_id']));
    }

    public function verify(AuditLogger $audit)
    {
        return $this->data($audit->verify(Tenant::current()->id));
    }
}
