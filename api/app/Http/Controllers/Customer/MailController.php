<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\MailAlias;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailServer;
use App\Services\BillingService;
use App\Services\MailService;
use Illuminate\Http\Request;

/**
 * UMAIL-001, 002, 004..006: email on the customer's own domains, hosted on the platform's mail
 * server. Mailboxes count against the package (limits.mailboxes, default 5).
 */
class MailController extends Controller
{
    public function __construct(private MailService $mail, private BillingService $billing) {}

    public function index()
    {
        $domains = MailDomain::query()->orderBy('name')->get();

        return $this->data([
            'available' => (bool) $this->server(), 'limit' => $this->billing->limit('mailboxes', 5), 'max_quota_mb' => $this->billing->limit('mailbox_quota_mb', 5120),
            'used' => Mailbox::query()->count(),
            'webmail_url' => ($s = $this->server()) && $s->webmail_hostname && $s->webmail_status === 'running' ? 'https://'.$s->webmail_hostname : null,
            'settings' => ($s = $this->server()) ? ['imap' => ['host' => $s->hostname, 'port' => 993, 'security' => 'SSL/TLS'], 'smtp' => ['host' => $s->hostname, 'port' => 587, 'security' => 'STARTTLS'], 'username' => 'Your full email address'] : null,
            'domains' => $domains->map(fn (MailDomain $d) => [
                'id' => $d->id, 'name' => $d->name,
                'records' => $this->plainRecords($d),
                'mailboxes' => Mailbox::where('mail_domain_id', $d->id)->orderBy('local_part')->get()->map(fn (Mailbox $b) => [
                    'id' => $b->id, 'address' => $b->local_part.'@'.$d->name, 'quota_mb' => $b->quota_mb, 'enabled' => $b->enabled,
                    'autoresponder' => $b->autoresponder,
                    // UMAIL: one-click webmail and the mail-app settings for this address.
                    'client' => ($s = $this->server()) ? MailService::clientSettings($s, $b->local_part.'@'.$d->name) : null,
                ]),
                'aliases' => MailAlias::where('mail_domain_id', $d->id)->orderBy('local_part')->get()->map(fn ($a) => ['id' => $a->id, 'address' => $a->local_part.'@'.$d->name, 'destinations' => $a->destinations]),
            ]),
        ]);
    }

    /** Email for a domain the customer already has on the platform. */
    public function storeDomain(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:190', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i']]);
        $name = strtolower($data['name']);
        if (! Domain::query()->where(fn ($q) => $q->where('name', $name)->orWhere('name', 'www.'.$name))->where('platform_subdomain', false)->exists()) {
            throw ApiException::validation(['name' => ['Add the domain to one of your apps first, under Domains.']]);
        }
        $server = $this->server() ?? throw ApiException::conflict('Email hosting is not available yet. Contact support.');
        $request->attributes->set('audit_skip', true);

        return $this->data(['id' => $this->mail->addDomain($server, $name, $request->user()->id)->id], 201);
    }

    /** UMAIL-002: refused at the package limit. */
    public function storeMailbox(Request $request, string $id)
    {
        $d = MailDomain::findOrFail($id);
        $data = $request->validate(['local_part' => 'required|string|max:64', 'password' => 'required|string|min:10|max:200', 'quota_mb' => 'sometimes|integer|in:512,1024,2048,5120']);
        if (($data['quota_mb'] ?? 1024) > ($max = $this->billing->limit('mailbox_quota_mb', 5120))) {
            throw ApiException::quota("Your plan allows mailboxes of up to {$max} MB.");
        }
        if (Mailbox::query()->count() >= ($limit = $this->billing->limit('mailboxes', 5))) {
            throw ApiException::quota("Your plan includes {$limit} mailboxes. Upgrade to add more.");
        }
        $request->attributes->set('audit_skip', true);
        $box = $this->mail->saveMailbox($d, $data, null, $request->user()->id);

        return $this->data(['id' => $box->id, 'address' => $box->local_part.'@'.$d->name], 201);
    }

    public function updateMailbox(Request $request, string $id, string $box)
    {
        $d = MailDomain::findOrFail($id);
        $data = $request->validate(['password' => 'sometimes|string|min:10|max:200']);
        $request->attributes->set('audit_skip', true);
        $this->mail->saveMailbox($d, $data, Mailbox::where('mail_domain_id', $d->id)->findOrFail($box), $request->user()->id);

        return $this->data(['saved' => true]);
    }

    public function destroyMailbox(Request $request, string $id, string $box)
    {
        $d = MailDomain::findOrFail($id);
        Mailbox::where('mail_domain_id', $d->id)->findOrFail($box)->delete();
        $this->mail->sync(MailServer::allTenants()->findOrFail($d->mail_server_id), $request->user()->id);

        return response()->noContent();
    }

    /** UMAIL-006 */
    public function autoresponder(Request $request, string $id, string $box)
    {
        $d = MailDomain::findOrFail($id);
        $data = $request->validate(['enabled' => 'required|boolean', 'subject' => 'required_if:enabled,true|nullable|string|max:200', 'body' => 'required_if:enabled,true|nullable|string|max:5000', 'until' => 'nullable|date|after_or_equal:today']);
        $this->mail->setAutoresponder(Mailbox::where('mail_domain_id', $d->id)->findOrFail($box), $data['enabled'] ? $data : null, $request->user()->id);

        return $this->data(['saved' => true]);
    }

    public function storeAlias(Request $request, string $id)
    {
        $d = MailDomain::findOrFail($id);
        $data = $request->validate(['local_part' => 'required|string|max:64', 'destinations' => 'required|array|min:1|max:20', 'destinations.*' => 'string|max:190']);
        $request->attributes->set('audit_skip', true);
        $a = $this->mail->saveAlias($d, $data['local_part'], $data['destinations'], $request->user()->id);

        return $this->data(['id' => $a->id], 201);
    }

    public function destroyAlias(Request $request, string $id, string $alias)
    {
        $d = MailDomain::findOrFail($id);
        MailAlias::where('mail_domain_id', $d->id)->findOrFail($alias)->delete();
        $this->mail->sync(MailServer::allTenants()->findOrFail($d->mail_server_id), $request->user()->id);

        return response()->noContent();
    }

    /** UMAIL-005: each record in plain words, marked set, missing or wrong. */
    public function checkDns(string $id)
    {
        $this->mail->checkDns(MailDomain::findOrFail($id));

        return $this->data($this->plainRecords(MailDomain::findOrFail($id)));
    }

    private function plainRecords(MailDomain $d): array
    {
        $what = ['autoconfig' => 'Lets email apps find the settings by themselves (optional)', 'MX' => 'Sends your email to this platform', 'spf' => 'Says which servers may send as you', 'dkim' => 'Signs your email so it is trusted', 'dmarc' => 'Tells others what to do with forged email'];
        $status = collect($d->dns ?? [])->keyBy(fn ($r) => $r['type'].' '.$r['name']);

        return array_map(function ($r) use ($what, $status) {
            $kind = in_array($r['type'], ['SRV', 'CNAME'], true) ? 'autoconfig' : ($r['type'] === 'MX' ? 'MX' : (str_starts_with($r['value'], 'v=spf1') ? 'spf' : (str_contains($r['name'], '_domainkey') ? 'dkim' : 'dmarc')));
            $s = $status[$r['type'].' '.$r['name']]['status'] ?? null;

            return ['type' => $r['type'], 'name' => $r['name'], 'value' => $r['value'], 'priority' => $r['priority'] ?? null, 'purpose' => $what[$kind],
                'status' => match ($s) { 'ok' => 'set', 'wrong' => 'wrong', 'missing' => ($r['optional'] ?? false) ? 'recommended' : 'missing', default => 'not checked' }];
        }, array_values(array_filter($this->mail->records($d), fn ($r) => empty($r['next']))));
    }

    /** The platform's first running mail server. */
    private function server(): ?MailServer
    {
        return MailServer::allTenants()->where('status', 'running')->orderBy('created_at')->first();
    }

    /** MCL-10: start an IMAP import into a mailbox. */
    public function startImport(Request $request, string $id, string $box)
    {
        $d = MailDomain::findOrFail($id);
        $data = $request->validate([
            'host' => 'required|string|max:253', 'port' => 'sometimes|integer|min:1|max:65535', 'security' => 'sometimes|in:ssl,starttls,none',
            'username' => 'required|string|max:190', 'password' => 'required|string|max:500',
        ]);
        $request->attributes->set('audit_skip', true);
        $import = app(\App\Services\MailImportService::class)->start(Mailbox::where('mail_domain_id', $d->id)->findOrFail($box), $data, $request->user()->id);

        return $this->accepted(\App\Models\Job::find($import->job_id), ['import' => \App\Services\MailImportService::present($import)]);
    }

    /** Imports into a domain's mailboxes, newest first, with the provider presets. */
    public function imports(string $id)
    {
        $d = MailDomain::findOrFail($id);
        $boxes = Mailbox::where('mail_domain_id', $d->id)->pluck('id');

        return $this->data([
            'presets' => \App\Services\MailImportService::PRESETS,
            'imports' => \App\Models\MailImport::whereIn('mailbox_id', $boxes)->latest()->limit(50)->get()->map(fn ($i) => \App\Services\MailImportService::present($i)),
        ]);
    }
}
