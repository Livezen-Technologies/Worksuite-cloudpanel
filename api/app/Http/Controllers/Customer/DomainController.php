<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Domain;
use App\Services\DomainService;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function __construct(private DomainService $domains) {}

    public function index(Request $request)
    {
        $query = Domain::with('certificate')->whereIn('application_id', Application::where('kind', 'customer')->select('id'))->latest();
        if ($app = $request->query('app_id')) {
            $query->where('application_id', $app);
        }

        return response()->json(['data' => $query->get()->map(fn ($d) => $this->domains->toApi($d))->values(), 'meta' => ['page' => 1, 'per_page' => $query->count(), 'total' => $query->count()]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i'],
            'app_id' => 'required|uuid',
            'www_redirect' => 'sometimes|boolean',
            'redirect_www' => 'sometimes|boolean',
        ]);
        $app = Application::where('kind', 'customer')->findOrFail($data['app_id']);
        $domain = $this->domains->create([
            'name' => $data['domain'], 'application_id' => $app->id, 'target_port' => $app->port,
            'www_redirect' => (bool) ($data['www_redirect'] ?? $data['redirect_www'] ?? false),
        ]);

        return $this->data($this->domains->toApi($domain), 201);
    }

    public function show(string $id)
    {
        return $this->data($this->domains->toApi($this->find($id)));
    }

    public function destroy(string $id)
    {
        $domain = $this->find($id);
        abort_if($domain->platform_subdomain, 409, 'The free address cannot be removed.');
        $domain->delete();

        return response()->noContent();
    }

    public function verify(string $id)
    {
        return $this->data($this->domains->verify($this->find($id)));
    }

    private function find(string $id): Domain
    {
        return Domain::whereIn('application_id', Application::where('kind', 'customer')->select('id'))->findOrFail($id);
    }
}
