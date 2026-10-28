<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Services\ApprovalService;
use App\Support\Lists;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function __construct(private ApprovalService $approvals) {}

    public function index(Request $request)
    {
        return response()->json(Lists::paginate(Approval::query(), $request, ['action'], ['created_at'], ['status', 'action', 'requested_by']));
    }

    public function approve(Request $request, string $id)
    {
        return $this->data($this->approvals->approve(Approval::findOrFail($id), $request->user()));
    }

    public function reject(Request $request, string $id)
    {
        return $this->data($this->approvals->reject(Approval::findOrFail($id), $request->user()));
    }
}
