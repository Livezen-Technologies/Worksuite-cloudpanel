<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ProxyAdoptionService;
use Illuminate\Http\Request;

/** Hand-written Traefik routes on a server, and adopting them into the panel. */
class ProxyAdoptionController extends Controller
{
    public function __construct(private ProxyAdoptionService $adoption) {}

    public function show(string $id)
    {
        return $this->data($this->adoption->scanned(Server::findOrFail($id)) ?? ['files' => [], 'adopted' => [], 'scanned_at' => null]);
    }

    public function scan(Request $request, string $id)
    {
        return $this->data(['job_id' => $this->adoption->scan(Server::findOrFail($id), $request->user()->id)->id], 202);
    }

    public function adopt(Request $request, string $id)
    {
        $v = $request->validate(['file' => ['required', 'string', 'max:125', 'regex:/^[a-z0-9][a-z0-9._-]*\.ya?ml$/']]);

        return $this->data(['job_id' => $this->adoption->adopt(Server::findOrFail($id), $v['file'], $request->user()->id)->id], 202);
    }
}
