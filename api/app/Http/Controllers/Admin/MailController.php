<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailAlias;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailServer;
use App\Models\Server;
use App\Services\MailService;
use Illuminate\Http\Request;

/** Mail hosting (SRS MAIL-001..005). */
class MailController extends Controller
{
    public function __construct(private MailService $mail) {}

    public function index()
    {
        $servers = Server::allTenants()->pluck('name', 'id');

        return $this->data([
            'servers' => MailServer::query()->get()->map(fn (MailServer $m) => [
                'id' => $m->id, 'server_id' => $m->server_id, 'server' => $servers[$m->server_id] ?? null, 'hostname' => $m->hostname,
                'status' => $m->status, 'error' => $m->error, 'synced_at' => $m->synced_at?->toIso8601ZuluString(),
            ]),
            'domains' => MailDomain::query()->orderBy('name')->get()->map(fn (MailDomain $d) => $this->presentDomain($d)),
        ]);
    }

    public function install(Request $request)
    {
        $data = $request->validate(['server_id' => 'required|string', 'hostname' => ['required', 'string', 'max:190', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i']]);
        $request->attributes->set('audit_skip', true);
        $m = $this->mail->install(Server::findOrFail($data['server_id']), $data['hostname'], $request->user()->id);

        return $this->data(['id' => $m->id, 'status' => $m->status], 202);
    }

    public function storeDomain(Request $request)
    {
        $data = $request->validate(['mail_server_id' => 'required|string', 'name' => ['required', 'string', 'max:190', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i']]);
        $request->attributes->set('audit_skip', true);
        $d = $this->mail->addDomain(MailServer::findOrFail($data['mail_server_id']), $data['name'], $request->user()->id);

        return $this->data($this->presentDomain($d, true), 201);
    }

    public function showDomain(string $id)
    {
        return $this->data($this->presentDomain(MailDomain::findOrFail($id), true));
    }

    public function updateDomain(Request $request, string $id)
    {
        $d = MailDomain::findOrFail($id);
        $data = $request->validate(['catch_all' => 'nullable|email|max:190']);
        $d->update(['catch_all' => $data['catch_all'] ?? null]);
        $this->mail->sync(MailServer::findOrFail($d->mail_server_id), $request->user()->id);

        return $this->data($this->presentDomain($d, true));
    }

    public function destroyDomain(Request $request, string $id)
    {
        $d = MailDomain::findOrFail($id);
        $server = MailServer::findOrFail($d->mail_server_id);
        $d->delete();
        $this->mail->sync($server, $request->user()->id);

        return response()->noContent();
    }

    /** MAIL-005 */
    public function checkDns(string $id)
    {
        return $this->data($this->mail->checkDns(MailDomain::findOrFail($id)));
    }

    public function publishDns(string $id)
    {
        return $this->data($this->mail->publish(MailDomain::findOrFail($id)));
    }

    /** MAIL-003 */
    public function storeMailbox(Request $request, string $id)
    {
        $data = $request->validate(['local_part' => 'required|string|max:64', 'password' => 'required|string|min:10|max:200', 'quota_mb' => 'sometimes|integer|min:50|max:102400']);
        $request->attributes->set('audit_skip', true);
        $box = $this->mail->saveMailbox(MailDomain::findOrFail($id), $data, null, $request->user()->id);

        return $this->data($this->presentBox($box), 201);
    }

    public function updateMailbox(Request $request, string $id, string $box)
    {
        $d = MailDomain::findOrFail($id);
        $data = $request->validate(['password' => 'sometimes|string|min:10|max:200', 'quota_mb' => 'sometimes|integer|min:50|max:102400', 'enabled' => 'sometimes|boolean']);
        $request->attributes->set('audit_skip', true);

        return $this->data($this->presentBox($this->mail->saveMailbox($d, $data, Mailbox::where('mail_domain_id', $d->id)->findOrFail($box), $request->user()->id)));
    }

    public function destroyMailbox(Request $request, string $id, string $box)
    {
        $d = MailDomain::findOrFail($id);
        Mailbox::where('mail_domain_id', $d->id)->findOrFail($box)->delete();
        $this->mail->sync(MailServer::findOrFail($d->mail_server_id), $request->user()->id);

        return response()->noContent();
    }

    /** MAIL-004 */
    public function storeAlias(Request $request, string $id)
    {
        $data = $request->validate(['local_part' => 'required|string|max:64', 'destinations' => 'required|array|min:1|max:50', 'destinations.*' => 'string|max:190']);
        $request->attributes->set('audit_skip', true);
        $a = $this->mail->saveAlias(MailDomain::findOrFail($id), $data['local_part'], $data['destinations'], $request->user()->id);

        return $this->data(['id' => $a->id, 'local_part' => $a->local_part, 'destinations' => $a->destinations], 201);
    }

    public function destroyAlias(Request $request, string $id, string $alias)
    {
        $d = MailDomain::findOrFail($id);
        MailAlias::where('mail_domain_id', $d->id)->findOrFail($alias)->delete();
        $this->mail->sync(MailServer::findOrFail($d->mail_server_id), $request->user()->id);

        return response()->noContent();
    }

    private function presentDomain(MailDomain $d, bool $detail = false): array
    {
        $out = [
            'id' => $d->id, 'name' => $d->name, 'mail_server_id' => $d->mail_server_id, 'catch_all' => $d->catch_all,
            'mailboxes' => Mailbox::where('mail_domain_id', $d->id)->count(), 'aliases' => MailAlias::where('mail_domain_id', $d->id)->count(),
            'dns_ok' => $d->dns ? collect($d->dns)->every(fn ($r) => $r['status'] === 'ok') : null, 'dns_checked_at' => $d->dns_checked_at?->toIso8601ZuluString(),
        ];
        if ($detail) {
            $out += [
                'records' => $this->mail->records($d), 'dns' => $d->dns ?? [],
                'mailbox_list' => Mailbox::where('mail_domain_id', $d->id)->orderBy('local_part')->get()->map(fn ($b) => $this->presentBox($b)),
                'alias_list' => MailAlias::where('mail_domain_id', $d->id)->orderBy('local_part')->get()->map(fn ($a) => ['id' => $a->id, 'local_part' => $a->local_part, 'destinations' => $a->destinations]),
            ];
        }

        return $out;
    }

    private function presentBox(Mailbox $b): array
    {
        return ['id' => $b->id, 'local_part' => $b->local_part, 'quota_mb' => $b->quota_mb, 'enabled' => $b->enabled, 'created_at' => $b->created_at?->toIso8601ZuluString()];
    }
}
