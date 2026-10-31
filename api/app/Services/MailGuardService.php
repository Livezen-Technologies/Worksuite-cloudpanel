<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Container;
use App\Models\MailDomain;
use App\Models\Mailbox;
use App\Models\MailLogEntry;
use App\Models\MailServer;
use App\Models\NotificationChannel;
use Illuminate\Support\Carbon;

/**
 * Mail cluster guard (MCL):
 *  - parses the mail container's Postfix log into `mail_log_entries`, which each
 *    organisation can search for its own domains;
 *  - holds a mailbox, or a whole domain, that sends more than its hourly limit or
 *    bounces most of what it sends: docker-mailserver's postfix-send-access.cf then
 *    rejects its outgoing mail until an administrator releases it;
 *  - alerts the organisation when it holds something.
 *
 * `mail:watch` runs every 5 minutes: it analyses the log fetched last time and asks
 * the agent for a fresh one.
 */
class MailGuardService
{
    /** A mailbox with at least this many sends in an hour, most of them bounced, is held. */
    public const BOUNCE_MIN = 20;

    public const BOUNCE_RATIO = 0.5;

    public function __construct(private ContainerService $containers, private NotificationService $notifications) {}

    /** @return list<array> entries parsed from Docker log lines ("<RFC3339> <syslog line>") */
    public static function parse(string $log, ?Carbon $after = null): array
    {
        $users = [];  // queue id → SASL user (outgoing)
        $from = [];   // queue id → envelope sender
        $out = [];
        foreach (preg_split('/\r?\n/', $log) as $line) {
            if (! preg_match('/^(\d{4}-\d\d-\d\dT[\d:.]+Z?)\s+(.*)$/', $line, $m)) {
                continue;
            }
            $at = Carbon::parse($m[1]);
            $msg = $m[2];
            if (preg_match('/postfix\/[\w\/-]+\[\d+\]: ([0-9A-F]{6,}): client=.*sasl_username=([^\s,]+)/i', $msg, $x)) {
                $users[$x[1]] = strtolower($x[2]);

                continue;
            }
            if (preg_match('/postfix\/qmgr\[\d+\]: ([0-9A-F]{6,}): from=<([^>]*)>/i', $msg, $x)) {
                $from[$x[1]] = strtolower($x[2]);

                continue;
            }
            if ($after && $at->lte($after)) {
                continue;
            }
            if (preg_match('/postfix\/(smtp|lmtp|local|virtual|error)\[\d+\]: ([0-9A-F]{6,}): to=<([^>]*)>.*?status=(sent|deferred|bounced)\s*\(?(.*?)\)?$/i', $msg, $x)) {
                $qid = $x[2];
                $relay = preg_match('/\brelay=([^,\s]+)/', $msg, $r) ? $r[1] : null;
                $user = $users[$qid] ?? null;
                $out[] = [
                    'at' => $at, 'queue_id' => $qid, 'direction' => $x[1] === 'lmtp' ? 'in' : 'out',
                    'sender' => $user ?? ($from[$qid] ?? null), 'recipient' => strtolower($x[3]), 'status' => strtolower($x[4]),
                    'relay' => $relay, 'detail' => mb_substr($x[5], 0, 500), 'authenticated' => $user !== null,
                ];

                continue;
            }
            if (preg_match('/NOQUEUE: reject: RCPT from [^:]+: (\d{3} [^;]*);.*from=<([^>]*)> to=<([^>]*)>/i', $msg, $x)) {
                $out[] = ['at' => $at, 'queue_id' => null, 'direction' => 'in', 'sender' => strtolower($x[2]), 'recipient' => strtolower($x[3]),
                    'status' => 'rejected', 'relay' => null, 'detail' => mb_substr($x[1], 0, 500), 'authenticated' => false];
            }
        }

        return $out;
    }

    /** Stores new entries from the last fetched log, then holds what is over its limit. */
    public function ingest(MailServer $mail): array
    {
        $container = Container::allTenants()->where('server_id', $mail->server_id)->where('name', MailService::CONTAINER)->first();
        if (! $container) {
            return ['entries' => 0, 'held' => []];
        }
        $domains = MailDomain::allTenants()->where('mail_server_id', $mail->id)->get()->keyBy('name');
        $entries = self::parse((string) $container->last_logs, $mail->log_cursor);
        $last = $mail->log_cursor;
        foreach ($entries as $e) {
            $own = $e['direction'] === 'out' ? $e['sender'] : $e['recipient'];
            $domain = $own && str_contains($own, '@') ? substr($own, strrpos($own, '@') + 1) : null;
            $d = $domain ? $domains->get($domain) : null;
            MailLogEntry::create([
                'organization_id' => $d?->organization_id, 'mail_server_id' => $mail->id, 'at' => $e['at'], 'queue_id' => $e['queue_id'],
                'direction' => $e['direction'], 'sender' => $e['sender'], 'recipient' => $e['recipient'], 'domain' => $d?->name,
                'status' => $e['status'], 'relay' => $e['relay'], 'detail' => $e['detail'],
            ]);
            $last = ! $last || $e['at']->gt($last) ? $e['at'] : $last;
        }
        $mail->forceFill(['log_cursor' => $last])->save();
        $held = $this->enforce($mail, $domains);
        // Ask for a fresh log for the next run.
        $this->containers->logs($container, 5000);

        return ['entries' => count($entries), 'held' => $held];
    }

    /** @return list<string> what was held this time */
    public function enforce(MailServer $mail, $domains): array
    {
        $since = now()->subHour();
        $base = (int) app(MailService::class)->settings($mail)['send_per_hour'];
        $held = [];
        foreach ($domains as $domain) {
            $sent = MailLogEntry::where('mail_server_id', $mail->id)->where('domain', $domain->name)->where('direction', 'out')->where('at', '>=', $since)
                ->get(['sender', 'status'])->groupBy('sender');
            $domainLimit = (int) ($domain->limits['send_per_hour'] ?? 0);
            if (! $domain->held_at && $domainLimit > 0 && $sent->flatten()->count() > $domainLimit) {
                $this->hold($domain, "sent {$sent->flatten()->count()} messages in an hour (limit {$domainLimit})");
                $held[] = $domain->name;
            }
            foreach ($sent as $address => $rows) {
                [$local] = explode('@', (string) $address);
                $box = Mailbox::allTenants()->where('mail_domain_id', $domain->id)->where('local_part', $local)->first();
                if (! $box || $box->held_at) {
                    continue;
                }
                $bounced = $rows->where('status', 'bounced')->count();
                $reason = match (true) {
                    $rows->count() > $base => "sent {$rows->count()} messages in an hour (limit {$base})",
                    $rows->count() >= self::BOUNCE_MIN && $bounced / $rows->count() >= self::BOUNCE_RATIO => "{$bounced} of {$rows->count()} messages bounced in an hour",
                    default => null,
                };
                if ($reason) {
                    $this->hold($box, $reason, $domain);
                    $held[] = $address;
                }
            }
        }
        if ($held) {
            app(MailService::class)->sync($mail, null);
        }

        return $held;
    }

    private function hold(Mailbox|MailDomain $target, string $reason, ?MailDomain $domain = null): void
    {
        $target->forceFill(['held_at' => now(), 'held_reason' => mb_substr($reason, 0, 255)])->save();
        $name = $target instanceof Mailbox ? $target->local_part.'@'.$domain->name : $target->name;
        $title = "Outgoing mail held for {$name}";
        $message = ucfirst($reason).'. Sending is blocked until an administrator releases it (Mail → Holds); incoming mail still arrives.';
        $alert = Alert::create(['organization_id' => $target->organization_id, 'resource_type' => $target instanceof Mailbox ? 'mailbox' : 'mail_domain',
            'resource_id' => $target->id, 'severity' => 'warning', 'title' => $title, 'message' => $message]);
        foreach (NotificationChannel::allTenants()->where('organization_id', $target->organization_id)->where('enabled', true)->get() as $channel) {
            $this->notifications->send($channel, "[WARNING] {$title}", $message, ['alert_id' => $alert->id]);
        }
    }

    public function release(Mailbox|MailDomain $target): void
    {
        $target->forceFill(['held_at' => null, 'held_reason' => null])->save();
        $domain = $target instanceof Mailbox ? MailDomain::allTenants()->find($target->mail_domain_id) : $target;
        Alert::allTenants()->where('resource_id', $target->id)->where('status', 'open')->where('title', 'like', 'Outgoing mail held%')->update(['status' => 'resolved', 'resolved_at' => now()]);
        app(MailService::class)->sync(MailServer::allTenants()->find($domain->mail_server_id), null);
    }

    public function watchAll(): int
    {
        $n = 0;
        foreach (MailServer::allTenants()->where('status', 'running')->get() as $mail) {
            app(\App\Support\Tenant::class)->as($mail->organization_id, fn () => $this->ingest($mail));
            $n++;
        }

        return $n;
    }

    /** Searchable log for the current organisation's domains (or every domain on a server, for admins). */
    public function search(array $f, ?string $mailServerId = null)
    {
        $q = MailLogEntry::query()->orderByDesc('at')->orderByDesc('id');
        $mailServerId ? $q->where('mail_server_id', $mailServerId) : $q->where('organization_id', \App\Support\Tenant::current()?->id);
        if (! empty($f['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], strtolower($f['q'])).'%';
            $q->where(fn ($w) => $w->where('sender', 'like', $term)->orWhere('recipient', 'like', $term)->orWhere('queue_id', 'like', $term)->orWhere('detail', 'like', $term));
        }
        foreach (['status', 'direction', 'domain'] as $k) {
            ! empty($f[$k]) && $q->where($k, $f[$k]);
        }
        ! empty($f['since']) && $q->where('at', '>=', Carbon::parse($f['since']));

        return $q->limit(min(500, (int) ($f['limit'] ?? 200)))->get();
    }
}
