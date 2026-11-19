<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectDashboardService;
use Illuminate\Http\Request;

/** Project → Overview (admin and customer). */
class ProjectDashboardController extends Controller
{
    public function __invoke(Request $request, ProjectDashboardService $dashboard, string $id)
    {
        return $this->data($dashboard->build(Project::findOrFail($id), $request->is('api/v1/app/*')));
    }
}
