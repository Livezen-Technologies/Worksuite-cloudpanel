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

/** Mail hosting (SRS MAIL-001..010, 012, 014). */
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
                'settings' => $this->presentSettings($m), 'health' => $m->health, 'health_checked_at' => $m->health_checked_at?->toIso8601ZuluString(),
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

    /** MAIL-008 / MAIL-009 */
    public function updateServer(Request $request, string $id)
    {
        $data = $request->validate([
            'send_per_hour' => 'sometimes|integer|min:1|max:100000', 'spam_reject' => 'sometimes|numeric|min:5|max:100', 'spam_header' => 'sometimes|numeric|min:1|max:99',
            'relay' => 'sometimes|nullable|array', 'relay.host' => ['required_with:relay', 'string', 'max:190', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'relay.port' => 'sometimes|integer|in:25,465,587,2525', 'relay.username' => 'required_with:relay|string|max:190', 'relay.password' => 'sometimes|string|max:255',
        ]);
        $request->attributes->set('audit_skip', true);
        $m = $this->mail->updateSettings(MailServer::findOrFail($id), $data, $request->user()->id);

        return $this->data($this->presentSettings($m));
    }

    /** MAIL-010 */
    public function health(string $id)
    {
        return $this->data($this->mail->checkHealth(MailServer::findOrFail($id)));
    }

    /** MAIL-014 */
    public function logs(Request $request, string $id)
    {
        return $this->data($this->mail->logs(MailServer::findOrFail($id), min(1000, max(10, (int) $request->query('tail', 200)))));
    }

    /** MAIL-006 */
    public function dkim(Request $request, string $id, string $step)
    {
        $d = MailDomain::findOrFail($id);
        $request->attributes->set('audit_skip', true);
        $d = $step === 'rotate' ? $this->mail->rotateDkim($d, $request->user()->id) : $this->mail->completeDkimRotation($d, $request->user()->id);

        return $this->data($this->presentDomain($d, true));
    }

    /** MAIL-012 */
    public function autoresponder(Request $request, string $id, string $box)
    {
        $d = MailDomain::findOrFail($id);
        $data = $request->validate(['enabled' => 'required|boolean', 'subject' => 'required_if:enabled,true|nullable|string|max:200', 'body' => 'required_if:enabled,true|nullable|string|max:5000', 'until' => 'nullable|date|after_or_equal:today']);
        $request->attributes->set('audit_skip', true);
        $b = $this->mail->setAutoresponder(Mailbox::where('mail_domain_id', $d->id)->findOrFail($box), $data['enabled'] ? $data : null, $request->user()->id);

        return $this->data($this->presentBox($b));
    }

    private function presentSettings(MailServer $m): array
    {
        $set = $this->mail->settings($m);
        if ($set['relay']) {
            $set['relay']['password_set'] = (bool) $m->relay_password;
        }

        return $set;
    }

    private function presentDomain(MailDomain $d, bool $detail = false): array
    {
        $out = [
            'id' => $d->id, 'name' => $d->name, 'mail_server_id' => $d->mail_server_id, 'catch_all' => $d->catch_all,
            'mailboxes' => Mailbox::where('mail_domain_id', $d->id)->count(), 'aliases' => MailAlias::where('mail_domain_id', $d->id)->count(),
            'dkim_selector' => $d->dkim_selector, 'dkim_next_selector' => $d->dkim_next_selector, 'dkim_previous_selector' => $d->dkim_previous_selector,
            'dkim_rotated_at' => $d->dkim_rotated_at?->toIso8601ZuluString(),
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
        return ['id' => $b->id, 'local_part' => $b->local_part, 'quota_mb' => $b->quota_mb, 'enabled' => $b->enabled, 'autoresponder' => $b->autoresponder, 'created_at' => $b->created_at?->toIso8601ZuluString()];
    }
}
