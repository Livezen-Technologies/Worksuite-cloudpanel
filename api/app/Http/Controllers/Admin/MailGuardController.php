<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailDomain;
use App\Models\Mailbox;
use App\Models\MailServer;
use App\Services\MailGuardService;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** MCL: sending holds, per-domain limits, per-mailbox spam settings and the searchable delivery log. */
class MailGuardController extends Controller
{
    public function __construct(protected MailGuardService $guard, protected MailService $mail) {}

    public function holds()
    {
        $domains = MailDomain::query()->get()->keyBy('id');

        return $this->data([
            'domains' => $domains->whereNotNull('held_at')->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'held_at' => $d->held_at, 'reason' => $d->held_reason])->values(),
            'mailboxes' => Mailbox::query()->whereNotNull('held_at')->get()->map(fn ($b) => [
                'id' => $b->id, 'domain_id' => $b->mail_domain_id, 'address' => $b->local_part.'@'.($domains[$b->mail_domain_id]->name ?? '?'), 'held_at' => $b->held_at, 'reason' => $b->held_reason,
            ])->values(),
        ]);
    }

    public function releaseMailbox(string $id, string $box)
    {
        $this->guard->release(Mailbox::where('mail_domain_id', MailDomain::findOrFail($id)->id)->whereNotNull('held_at')->findOrFail($box));

        return response()->noContent();
    }

    public function releaseDomain(string $id)
    {
        $this->guard->release(MailDomain::whereNotNull('held_at')->findOrFail($id));

        return response()->noContent();
    }

    public function limits(Request $request, string $id)
    {
        $v = $request->validate([
            'max_mailboxes' => 'nullable|integer|min:1|max:10000', 'max_aliases' => 'nullable|integer|min:0|max:10000',
            'quota_total_mb' => 'nullable|integer|min:100|max:10000000', 'send_per_hour' => 'nullable|integer|min:1|max:100000',
        ]);

        return $this->data(['limits' => $this->mail->setDomainLimits(MailDomain::findOrFail($id), $v)->limits ?? []]);
    }

    public function spam(Request $request, string $id, string $box)
    {
        $v = $request->validate([
            'level' => ['required', Rule::in(array_keys(MailService::SPAM_LEVELS))],
            'allow' => 'sometimes|array|max:200', 'allow.*' => ['string', 'max:190', 'regex:/^([^@\s"]+@)?[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'block' => 'sometimes|array|max:200', 'block.*' => ['string', 'max:190', 'regex:/^([^@\s"]+@)?[a-z0-9.-]+\.[a-z]{2,}$/i'],
        ]);
        $b = $this->mail->setSpam(Mailbox::where('mail_domain_id', MailDomain::findOrFail($id)->id)->findOrFail($box), $v, $request->user()->id);

        return $this->data(['spam' => $b->spam]);
    }

    public function search(Request $request)
    {
        $f = $request->validate([
            'q' => 'nullable|string|max:190', 'status' => 'nullable|in:sent,deferred,bounced,rejected', 'direction' => 'nullable|in:in,out',
            'domain' => 'nullable|string|max:253', 'since' => 'nullable|date', 'limit' => 'nullable|integer|min:1|max:500', 'mail_server_id' => 'nullable|uuid',
        ]);
        $server = ! empty($f['mail_server_id']) ? MailServer::findOrFail($f['mail_server_id'])->id : null;

        return $this->data($this->guard->search($f, $server));
    }
}
