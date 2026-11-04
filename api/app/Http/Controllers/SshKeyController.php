<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\UserSshKey;
use App\Services\AuditLogger;
use App\Services\SshGatewayService;
use App\Support\SshKeys;
use Illuminate\Http\Request;

/** SSH-GW: the signed-in person's own SSH keys for the SSH gateway (PuTTY, ssh, any SSH client). */
class SshKeyController extends Controller
{
    public const MAX = 20;

    public function __construct(private AuditLogger $audit) {}

    private static function present(UserSshKey $k): array
    {
        return ['id' => $k->id, 'name' => $k->name, 'public_key' => $k->public_key, 'fingerprint' => $k->fingerprint,
            'last_used_at' => $k->last_used_at?->toIso8601ZuluString(), 'created_at' => $k->created_at?->toIso8601ZuluString()];
    }

    public function index(Request $request)
    {
        return $this->data([
            'gateway' => SshGatewayService::info(),
            'keys' => UserSshKey::where('user_id', $request->user()->id)->orderBy('name')->get()->map(fn ($k) => self::present($k))->values(),
        ]);
    }

    private function room(Request $request): void
    {
        if (UserSshKey::where('user_id', $request->user()->id)->count() >= self::MAX) {
            throw ApiException::conflict('You can have up to '.self::MAX.' SSH keys. Delete one first.');
        }
    }

    /** Paste an existing public key (ssh-ed25519, ecdsa or RSA 2048+). */
    public function store(Request $request)
    {
        $v = $request->validate(['name' => 'required|string|min:2|max:80', 'public_key' => 'required|string|max:16000']);
        $this->room($request);
        $k = SshKeys::parsePublic($v['public_key']);
        if (! $k) {
            throw ApiException::validation(['public_key' => ['Paste one OpenSSH public key line, e.g. "ssh-ed25519 AAAA… you@laptop" (Ed25519, ECDSA or RSA 2048+). In PuTTYgen, copy the box "Public key for pasting into OpenSSH authorized_keys file".']]);
        }
        if (UserSshKey::where('fingerprint', $k['fingerprint'])->exists()) {
            throw ApiException::conflict('This key is already registered.');
        }
        $key = UserSshKey::create(['user_id' => $request->user()->id, 'name' => $v['name'], 'public_key' => $k['key'], 'fingerprint' => $k['fingerprint']]);
        $this->audit->log('ssh_key.add', 'user_ssh_key', $key->id, ['fingerprint' => $key->fingerprint]);

        return $this->data(self::present($key), 201);
    }

    /** A new Ed25519 key; the private half is returned once and never stored. */
    public function generate(Request $request)
    {
        $v = $request->validate(['name' => 'required|string|min:2|max:80']);
        $this->room($request);
        $k = SshKeys::generate(preg_replace('/[^A-Za-z0-9._-]/', '-', $v['name']));
        $key = UserSshKey::create(['user_id' => $request->user()->id, 'name' => $v['name'], 'public_key' => $k['public'], 'fingerprint' => $k['fingerprint']]);
        $this->audit->log('ssh_key.generate', 'user_ssh_key', $key->id, ['fingerprint' => $key->fingerprint]);

        return $this->data(self::present($key) + ['private_key' => $k['private']], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $key = UserSshKey::where('user_id', $request->user()->id)->findOrFail($id);
        $key->delete();
        $this->audit->log('ssh_key.delete', 'user_ssh_key', $id, ['fingerprint' => $key->fingerprint]);

        return response()->noContent();
    }
}
