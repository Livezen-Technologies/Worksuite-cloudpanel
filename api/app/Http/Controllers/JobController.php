<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Support\Lists;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(Lists::paginate(Job::query(), $request, ['type'], ['created_at', 'type', 'status'], ['status', 'type', 'resource_id'], fn (Job $j) => $j->toApi()));
    }

    public function show(string $id)
    {
        return $this->data(Job::findOrFail($id)->toApi());
    }
}
