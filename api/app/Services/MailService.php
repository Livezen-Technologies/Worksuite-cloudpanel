<?php

namespace App\Services;

use App\Contracts\DnsResolver;
use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Container;
use App\Models\DnsZone;
use App\Models\Job;
use App\Models\MailAlias;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailServer;
use App\Models\Server;
use App\Support\Tenant;

/**
 * Mail hosting (SRS MAIL-001..005). One docker-mailserver container per server runs Postfix,
 * Dovecot, OpenDKIM and Rspamd. The panel owns its account, alias, quota and DKIM files and
 * rewrites them on every change; the container picks the changes up by itself.
 */
class MailService implements HandlesCommandResults
{
    public const IMAGE = 'ghcr.io/docker-mailserver/docker-mailserver:15';

    public const CONTAINER = 'mailserver';

    /** The volume mounted at /tmp/docker-mailserver, where the config files live. */
    public const CONFIG_VOLUME = 'mailserver-config';

    public const LOCAL_PART = '/^[a-z0-9]([a-z0-9._+-]{0,62}[a-z0-9])?$/';

    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit, private DnsResolver $dns) {}

    /** MAIL-001: create the mail stack on a server. */
    public function install(Server $server, string $hostname, ?string $userId): MailServer
    {
        if (MailServer::allTenants()->where('server_id', $server->id)->exists()) {
            throw ApiException::conflict('This server already runs mail.');
        }
        if (! $server->isOnline()) {
            throw ApiException::conflict('The server is offline.');
        }
        $mail = MailServer::create(['server_id' => $server->id, 'hostname' => strtolower($hostname), 'status' => 'installing']);
        $spec = ContainerSpec::build([
            'name' => self::CONTAINER, 'image' => self::IMAGE, 'read_only_rootfs' => false,
            'env' => [
                'OVERRIDE_HOSTNAME' => $mail->hostname, 'ENABLE_RSPAMD' => '1', 'ENABLE_OPENDKIM' => '1', 'ENABLE_OPENDMARC' => '0',
                'ENABLE_QUOTAS' => '1', 'ENABLE_CLAMAV' => '0', 'ENABLE_FAIL2BAN' => '0', 'SPOOF_PROTECTION' => '1',
                'PERMIT_DOCKER' => 'none', 'SSL_TYPE' => 'self-signed', 'POSTMASTER_ADDRESS' => 'postmaster@'.$mail->hostname,
            ],
            'ports' => array_map(fn ($p) => ['container_port' => $p, 'host_port' => $p], [25, 465, 587, 993]),
            'mounts' => [
                ['source' => 'mailserver-data', 'target' => '/var/mail'],
                ['source' => 'mailserver-state', 'target' => '/var/mail-state'],
                ['source' => self::CONFIG_VOLUME, 'target' => '/tmp/docker-mailserver'],
            ],
            'memory_mb' => 1024, 'labels' => ['io.dockermgr.mail' => $mail->id],
        ], Tenant::current());
        $job = $this->jobs->create('mail', ['execute'], $mail, ['mail_server_id' => $mail->id, 'action' => 'install', 'steps' => [], 'step' => 0], $userId, $mail->organization_id);
        $this->bus->dispatch($server, 'container.create', $spec, $job);
        Container::create(['server_id' => $server->id, 'name' => self::CONTAINER, 'image' => self::IMAGE, 'state' => 'creating', 'spec' => ContainerSpec::redact($spec)]);
        $this->audit->log('mail.install', 'mail_servers', $mail->id, ['server' => $server->name, 'hostname' => $mail->hostname]);

        return $mail;
    }

    /** MAIL-002: a mail domain with a fresh 2048-bit DKIM key; its DNS records are published when a managed zone holds it. */
    public function addDomain(MailServer $mail, string $name, ?string $userId): MailDomain
    {
        $name = strtolower(trim($name, '. '));
        if (MailDomain::allTenants()->where('name', $name)->exists()) {
            throw ApiException::conflict("{$name} already receives mail on this platform.");
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        $domain = MailDomain::create([
            'mail_server_id' => $mail->id, 'name' => $name, 'dkim_selector' => 'mail', 'dkim_private' => $private,
            'dkim_public' => preg_replace('/-----[^-]+-----|\s+/', '', $public),
        ]);
        $this->audit->log('mail.domain_create', 'mail_domains', $domain->id, ['domain' => $name]);
        $this->publish($domain);
        $this->sync($mail, $userId);

        return $domain;
    }

    /** MAIL-005: what the domain needs in DNS. */
    public function records(MailDomain $domain): array
    {
        $host = MailServer::allTenants()->find($domain->mail_server_id)->hostname;

        return [
            ['type' => 'MX', 'name' => $domain->name, 'value' => $host, 'priority' => 10],
            ['type' => 'TXT', 'name' => $domain->name, 'value' => 'v=spf1 mx a:'.$host.' ~all'],
            ['type' => 'TXT', 'name' => $domain->dkim_selector.'._domainkey.'.$domain->name, 'value' => 'v=DKIM1; k=rsa; p='.$domain->dkim_public],
            ['type' => 'TXT', 'name' => '_dmarc.'.$domain->name, 'value' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@'.$domain->name.'; adkim=s; aspf=s'],
        ];
    }

    /** Writes the records into a managed zone that holds the domain; existing values are left alone. */
    public function publish(MailDomain $domain): array
    {
        $zone = DnsZone::forDomain($domain->name, $domain->organization_id);
        if (! $zone) {
            return [];
        }
        $existing = $zone->records()->withoutGlobalScope('organization')->get();
        $out = [];
        foreach ($this->records($domain) as $r) {
            $same = $existing->filter(fn ($e) => $e->type === $r['type'] && $e->name === $r['name']);
            $isSpf = str_starts_with($r['value'], 'v=spf1');
            if ($same->contains(fn ($e) => trim($e->content, '".') === rtrim($r['value'], '.'))) {
                $out[] = $r + ['status' => 'present'];
            } elseif ($r['type'] === 'MX' && $same->isNotEmpty() || $isSpf && $same->contains(fn ($e) => str_contains($e->content, 'v=spf1'))) {
                $out[] = $r + ['status' => 'conflict'];  // never replace someone's MX or SPF silently
            } else {
                try {
                    app(DnsZoneService::class)->createRecord($zone, ['type' => $r['type'], 'name' => $r['name'], 'content' => $r['value'], 'ttl' => 3600, 'priority' => $r['priority'] ?? null]);
                    $out[] = $r + ['status' => 'created'];
                } catch (\Throwable $e) {
                    $out[] = $r + ['status' => 'failed', 'message' => $e->getMessage()];
                }
            }
        }
        $this->audit->log('mail.dns_publish', 'mail_domains', $domain->id, ['zone' => $zone->name, 'records' => array_column($out, 'status', 'name')]);

        return $out;
    }

    /** MAIL-005: looks each record up and stores which are in place. */
    public function checkDns(MailDomain $domain): array
    {
        $out = [];
        foreach ($this->records($domain) as $r) {
            $found = array_map(fn ($v) => rtrim(trim((string) $v, '"'), '.'), $this->dns->lookup($r['name'], $r['type']));
            $ok = $r['type'] === 'MX'
                ? collect($found)->contains(fn ($v) => str_ends_with($v, $r['value']))
                : in_array($r['value'], $found, true);
            $out[] = $r + ['status' => $ok ? 'ok' : ($found ? 'wrong' : 'missing'), 'found' => array_values($found)];
        }
        $domain->update(['dns' => $out, 'dns_checked_at' => now()]);

        return $out;
    }

    /** MAIL-003 */
    public function saveMailbox(MailDomain $domain, array $data, ?Mailbox $box, ?string $userId): Mailbox
    {
        $attrs = [];
        if (! $box) {
            $local = strtolower($data['local_part']);
            if (! preg_match(self::LOCAL_PART, $local)) {
                throw ApiException::validation(['local_part' => ['Use letters, digits, dots, dashes, plus or underscores.']]);
            }
            if (Mailbox::allTenants()->where('mail_domain_id', $domain->id)->where('local_part', $local)->exists()
                || MailAlias::allTenants()->where('mail_domain_id', $domain->id)->where('local_part', $local)->exists()) {
                throw ApiException::conflict("{$local}@{$domain->name} already exists.");
            }
            $attrs = ['mail_domain_id' => $domain->id, 'local_part' => $local];
        }
        if (isset($data['password'])) {
            $attrs['password_hash'] = self::hash($data['password']);
        }
        foreach (['quota_mb', 'enabled'] as $k) {
            if (array_key_exists($k, $data)) {
                $attrs[$k] = $data[$k];
            }
        }
        $box = $box ? tap($box)->update($attrs) : Mailbox::create($attrs);
        $this->audit->log($box->wasRecentlyCreated ? 'mail.mailbox_create' : 'mail.mailbox_update', 'mailboxes', $box->id,
            ['address' => $box->local_part.'@'.$domain->name, 'password_changed' => isset($data['password'])] + array_intersect_key($data, array_flip(['quota_mb', 'enabled'])));
        $this->sync(MailServer::allTenants()->find($domain->mail_server_id), $userId);

        return $box;
    }

    /** MAIL-004 */
    public function saveAlias(MailDomain $domain, string $local, array $destinations, ?string $userId): MailAlias
    {
        $local = strtolower($local);
        if (! preg_match(self::LOCAL_PART, $local)) {
            throw ApiException::validation(['local_part' => ['Use letters, digits, dots, dashes, plus or underscores.']]);
        }
        $destinations = array_values(array_unique(array_map('strtolower', $destinations)));
        foreach ($destinations as $d) {
            if (! filter_var($d, FILTER_VALIDATE_EMAIL)) {
                throw ApiException::validation(['destinations' => ["{$d} is not an email address."]]);
            }
        }
        if (Mailbox::allTenants()->where('mail_domain_id', $domain->id)->where('local_part', $local)->exists()) {
            throw ApiException::conflict("{$local}@{$domain->name} is a mailbox.");
        }
        $alias = MailAlias::allTenants()->updateOrCreate(['mail_domain_id' => $domain->id, 'local_part' => $local], ['organization_id' => $domain->organization_id, 'destinations' => $destinations]);
        $this->audit->log('mail.alias_save', 'mail_aliases', $alias->id, ['address' => $local.'@'.$domain->name, 'destinations' => $destinations]);
        $this->sync(MailServer::allTenants()->find($domain->mail_server_id), $userId);

        return $alias;
    }

    /** {SHA512-CRYPT}, the scheme docker-mailserver's Dovecot reads from postfix-accounts.cf. */
    public static function hash(string $password): string
    {
        return '{SHA512-CRYPT}'.crypt($password, '$6$rounds=5000$'.substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16).'$');
    }

    /** The files the panel owns in the mail container's config volume. */
    public function files(MailServer $mail): array
    {
        $domains = MailDomain::allTenants()->where('mail_server_id', $mail->id)->orderBy('name')->get();
        $accounts = $virtual = $quotas = $keyTable = $signing = [];
        $keys = [];
        foreach ($domains as $d) {
            foreach (Mailbox::allTenants()->where('mail_domain_id', $d->id)->where('enabled', true)->orderBy('local_part')->get() as $b) {
                $address = $b->local_part.'@'.$d->name;
                $accounts[] = $address.'|'.$b->password_hash;
                $quotas[] = $address.':'.$b->quota_mb.'M';
            }
            foreach (MailAlias::allTenants()->where('mail_domain_id', $d->id)->orderBy('local_part')->get() as $a) {
                $virtual[] = $a->local_part.'@'.$d->name.' '.implode(',', $a->destinations);
            }
            if ($d->catch_all) {
                $virtual[] = '@'.$d->name.' '.$d->catch_all;
            }
            $keyTable[] = "{$d->dkim_selector}._domainkey.{$d->name} {$d->name}:{$d->dkim_selector}:/etc/opendkim/keys/{$d->name}/{$d->dkim_selector}.private";
            $signing[] = "*@{$d->name} {$d->dkim_selector}._domainkey.{$d->name}";
            $keys["opendkim/keys/{$d->name}/{$d->dkim_selector}.private"] = $d->dkim_private;
        }
        $lines = fn (array $l) => $l ? implode("\n", $l)."\n" : '';

        return [
            'postfix-accounts.cf' => $lines($accounts), 'postfix-virtual.cf' => $lines($virtual), 'dovecot-quotas.cf' => $lines($quotas),
            'opendkim/KeyTable' => $lines($keyTable), 'opendkim/SigningTable' => $lines($signing), 'opendkim/TrustedHosts' => "127.0.0.1\nlocalhost\n",
        ] + $keys;
    }

    /** Rewrites every file on the mail server; folders first. */
    public function sync(MailServer $mail, ?string $userId): ?Job
    {
        if ($mail->status !== 'running') {
            return null; // the install job syncs once the container runs
        }
        $files = $this->files($mail);
        $dirs = collect(array_keys($files))->map(fn ($p) => dirname($p))->filter(fn ($d) => $d !== '.')
            ->flatMap(function ($d) {
                $parts = explode('/', $d);

                return array_map(fn ($i) => implode('/', array_slice($parts, 0, $i)), range(1, count($parts)));
            })->unique()->values();
        $steps = $dirs->map(fn ($d) => ['fs.mkdir', ['volume' => self::CONFIG_VOLUME, 'path' => $d, 'exist_ok' => true]])->all();
        foreach (array_keys($files) as $path) {
            // Content is read at dispatch, so the job never stores password hashes or DKIM keys.
            $steps[] = ['fs.write', ['volume' => self::CONFIG_VOLUME, 'path' => $path, 'file' => $path]];
        }
        $job = $this->jobs->create('mail', ['execute'], $mail, ['mail_server_id' => $mail->id, 'action' => 'sync', 'steps' => $steps, 'step' => 0], $userId, $mail->organization_id);
        $this->dispatch($mail, $job, $steps[0]);

        return $job;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $mail = MailServer::allTenants()->find($job->payload['mail_server_id'] ?? null);
        if (! $mail) {
            $this->jobs->fail($job, 'The mail server no longer exists.', 'execute');

            return;
        }
        if ($command->status !== 'succeeded') {
            $message = (string) ($command->message ?: 'The mail command failed.');
            if ($job->payload['action'] === 'install') {
                $mail->update(['status' => 'failed', 'error' => $message]);
            }
            $this->jobs->fail($job, $message, 'execute');

            return;
        }
        if ($job->payload['action'] === 'install') {
            Container::allTenants()->where('server_id', $mail->server_id)->where('name', self::CONTAINER)->update(['state' => 'running']);
            $mail->update(['status' => 'running', 'error' => null]);
            $this->jobs->succeed($job, ['mail_server_id' => $mail->id]);
            $this->sync($mail, $job->user_id);

            return;
        }
        $steps = $job->payload['steps'];
        $next = (int) $job->payload['step'] + 1;
        if ($next < count($steps)) {
            $job->update(['payload' => ['step' => $next] + $job->payload]);
            $this->dispatch($mail, $job, $steps[$next]);

            return;
        }
        $mail->update(['synced_at' => now()]);
        $this->jobs->succeed($job, ['mail_server_id' => $mail->id]);
    }

    private function dispatch(MailServer $mail, Job $job, array $step): void
    {
        [$type, $payload] = $step;
        if (isset($payload['file'])) {
            $payload['content'] = $this->files($mail)[$payload['file']] ?? '';
            unset($payload['file']);
        }
        $this->bus->dispatch(Server::allTenants()->find($mail->server_id), $type, $payload, $job);
    }
}
