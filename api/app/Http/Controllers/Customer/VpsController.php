<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Vps;
use App\Services\VpsService;
use Illuminate\Http\Request;

/** UVPS: the customer's own virtual servers. */
class VpsController extends Controller
{
    public function __construct(private VpsService $vps) {}

    public function index()
    {
        return $this->data([
            'servers' => Vps::with('container')->orderBy('name')->get()->map(fn ($v) => $this->vps->present($v))->values(),
            'limit' => (int) config('platform.vps.limit'),
            'plans' => $this->vps->plans(),
            'images' => $this->vps->images(),
            'regions' => $this->vps->regions(),
        ]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
            'plan' => 'required|string|max:20',
            'os' => 'required|string|max:30',
            'region' => 'nullable|string|max:60',
            'ssh_keys' => 'required|array|min:1|max:10',
            'ssh_keys.*' => ['required', 'string', 'max:2000', 'regex:/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com) [A-Za-z0-9+\/=]{40,}( [^\n\r]{0,200})?$/'],
        ], ['name.regex' => 'Use lowercase letters, numbers and hyphens.', 'ssh_keys.*.regex' => 'Paste a public key, e.g. the contents of ~/.ssh/id_ed25519.pub.']);
        $v['ssh_keys'] = array_map('trim', $v['ssh_keys']);
        [$vps, $job] = $this->vps->create($request->user(), $v);

        return response()->json(['data' => $this->vps->present($vps->fresh(['container'])) + ['job_id' => $job->id]], 202);
    }

    public function show(string $id)
    {
        return $this->data($this->vps->present(Vps::with('container')->findOrFail($id)));
    }

    public function action(string $id, string $action)
    {
        return $this->accepted($this->vps->action(Vps::findOrFail($id), $action));
    }

    public function destroy(Request $request, string $id)
    {
        $vps = Vps::findOrFail($id);
        if (trim((string) $request->input('confirm')) !== $vps->name) {
            throw ApiException::validation(['confirm' => ['Type the server name to delete it.']]);
        }
        $job = $this->vps->destroy($vps);

        return $job ? $this->accepted($job) : response()->noContent();
    }
}
