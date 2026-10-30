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
 * Mail hosting (SRS MAIL-001..010, 012, 014). One docker-mailserver container per server runs Postfix,
 * Dovecot, OpenDKIM and Rspamd. The panel owns its account, alias, quota and DKIM files and
 * rewrites them on every change; the container picks the changes up by itself.
 */
class MailService implements HandlesCommandResults
{
    public const IMAGE = 'ghcr.io/docker-mailserver/docker-mailserver:15';

    public const CONTAINER = 'mailserver';

    /** Roundcube, beside the mail container (UMAIL / MAIL webmail). */
    public const WEBMAIL_IMAGE = 'roundcube/roundcubemail:1.6.9-apache';

    public const WEBMAIL_CONTAINER = 'webmail';

    /** The Dovecot master user imports sign in as ("box@domain*dmpimport"). */
    public const MASTER_USER = 'dmpimport';

    /** The volume mounted at /tmp/docker-mailserver, where the config files live. */
    public const CONFIG_VOLUME = 'mailserver-config';

    /** MAIL-008 defaults: sending limit per mailbox and Rspamd action scores. */
    public const DEFAULTS = ['send_per_hour' => 200, 'spam_reject' => 15, 'spam_header' => 6, 'relay' => null];

    /** MAIL-010: DNS blocklists checked for the server's address. */
    public const BLOCKLISTS = ['zen.spamhaus.org', 'bl.spamcop.net', 'b.barracudacentral.org', 'dnsbl.sorbs.net'];

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
                'PERMIT_DOCKER' => 'none', 'SSL_TYPE' => 'manual',
                'SSL_CERT_PATH' => '/tmp/docker-mailserver/ssl/cert.pem', 'SSL_KEY_PATH' => '/tmp/docker-mailserver/ssl/key.pem', 'POSTMASTER_ADDRESS' => 'postmaster@'.$mail->hostname,
            ],
            'ports' => array_map(fn ($p) => ['container_port' => $p, 'host_port' => $p], [25, 465, 587, 993]),
            'mounts' => [
                ['source' => 'mailserver-data', 'target' => '/var/mail'],
                ['source' => 'mailserver-state', 'target' => '/var/mail-state'],
                ['source' => self::CONFIG_VOLUME, 'target' => '/tmp/docker-mailserver'],
            ],
            'memory_mb' => 1024, 'labels' => ['io.dockermgr.mail' => $mail->id],
        ], Tenant::current());
        // MAIL-005: the certificate first (Traefik's, or self-signed until it has one), then the container.
        $job = $this->jobs->create('mail', ['execute'], $mail, ['mail_server_id' => $mail->id, 'action' => 'install', 'steps' => [
            ['mail.certs', ['hostname' => $mail->hostname, 'volume' => self::CONFIG_VOLUME, 'fallback' => true]],
            ['container.create', $spec],
        ], 'step' => 0], $userId, $mail->organization_id);
        $this->bus->dispatch($server, 'mail.certs', ['hostname' => $mail->hostname, 'volume' => self::CONFIG_VOLUME, 'fallback' => true], $job);
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
            ...($domain->dkim_next_selector ? [['type' => 'TXT', 'name' => $domain->dkim_next_selector.'._domainkey.'.$domain->name, 'value' => 'v=DKIM1; k=rsa; p='.$domain->dkim_next_public, 'next' => true]] : []),
            // MAIL-003: autoconfiguration for email apps (RFC 6186) and Thunderbird/Outlook lookups.
            ['type' => 'SRV', 'name' => '_submission._tcp.'.$domain->name, 'value' => '1 587 '.$host, 'priority' => 0, 'optional' => true],
            ['type' => 'SRV', 'name' => '_imaps._tcp.'.$domain->name, 'value' => '1 993 '.$host, 'priority' => 0, 'optional' => true],
            ['type' => 'CNAME', 'name' => 'autoconfig.'.$domain->name, 'value' => $host, 'optional' => true],
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
            $ok = in_array($r['type'], ['MX', 'SRV'], true)
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
            if ($d->dkim_next_selector) {
                $keys["opendkim/keys/{$d->name}/{$d->dkim_next_selector}.private"] = $d->dkim_next_private;
            }
        }
        $lines = fn (array $l) => $l ? implode("\n", $l)."\n" : '';
        $set = $this->settings($mail);
        // MAIL-008: per-user sending limit and spam actions in Rspamd.
        $extra = [
            'rspamd/override.d/ratelimit.conf' => "rates {\n  user = \"{$set['send_per_hour']} / 1h\";\n}\n",
            'rspamd/override.d/actions.conf' => "reject = {$set['spam_reject']};\nadd_header = {$set['spam_header']};\ngreylist = ".max(1, $set['spam_header'] - 2).";\n",
        ];
        // MAIL-009: send everything through a smarthost.
        $relay = $set['relay'];
        $extra['postfix-main.cf'] = $relay ? implode("\n", [
            "relayhost = [{$relay['host']}]:{$relay['port']}", 'smtp_sasl_auth_enable = yes', 'smtp_sasl_security_options = noanonymous',
            'smtp_sasl_password_maps = texthash:/tmp/docker-mailserver/postfix-sasl-password.cf', 'smtp_tls_security_level = encrypt',
        ])."\n" : '';
        $extra['postfix-sasl-password.cf'] = $relay ? "[{$relay['host']}]:{$relay['port']} {$relay['username']}:{$mail->relay_password}\n" : '';
        // MAIL-012: auto-replies as per-user Sieve scripts, which docker-mailserver installs.
        foreach ($domains as $d) {
            foreach (Mailbox::allTenants()->where('mail_domain_id', $d->id)->whereNotNull('autoresponder')->get() as $b) {
                $extra[$b->local_part.'@'.$d->name.'.dovecot.sieve'] = self::sieve($b->autoresponder);
            }
        }

        // MCL-10: imports sign in as a master user, so mailbox passwords are never needed.
        $extra['dovecot-masters.cf'] = self::MASTER_USER.'|'.self::hash($this->masterPassword($mail))."\n";

        return $extra + [
            'postfix-accounts.cf' => $lines($accounts), 'postfix-virtual.cf' => $lines($virtual), 'dovecot-quotas.cf' => $lines($quotas),
            'opendkim/KeyTable' => $lines($keyTable), 'opendkim/SigningTable' => $lines($signing), 'opendkim/TrustedHosts' => "127.0.0.1\nlocalhost\n",
        ] + $keys;
    }

    /** The master user's password, created on first use and kept encrypted. */
    public function masterPassword(MailServer $mail): string
    {
        if (! $mail->master_password) {
            $mail->forceFill(['master_password' => \Illuminate\Support\Str::password(40, symbols: false)])->save();
        }

        return $mail->master_password;
    }

    /**
     * Roundcube on its own hostname, routed by Traefik and signing in to this mail
     * server over IMAPS / submission. Its settings live in a SQLite volume.
     */
    public function installWebmail(MailServer $mail, string $hostname, ?string $userId): MailServer
    {
        if ($mail->status !== 'running') {
            throw ApiException::conflict('Install the mail server first.');
        }
        $server = Server::allTenants()->find($mail->server_id);
        if (! $server?->isOnline()) {
            throw ApiException::conflict('The server is offline.');
        }
        $hostname = strtolower($hostname);
        $router = 'webmail-'.substr(str_replace('-', '', $mail->id), 0, 8);
        $spec = ContainerSpec::build([
            'name' => self::WEBMAIL_CONTAINER, 'image' => self::WEBMAIL_IMAGE, 'read_only_rootfs' => false,
            'env' => [
                'ROUNDCUBEMAIL_DEFAULT_HOST' => 'ssl://'.$mail->hostname, 'ROUNDCUBEMAIL_DEFAULT_PORT' => '993',
                'ROUNDCUBEMAIL_SMTP_SERVER' => 'tls://'.$mail->hostname, 'ROUNDCUBEMAIL_SMTP_PORT' => '587',
                'ROUNDCUBEMAIL_DB_TYPE' => 'sqlite', 'ROUNDCUBEMAIL_SKIN' => 'elastic', 'ROUNDCUBEMAIL_UPLOAD_MAX_FILESIZE' => '25M',
            ],
            'networks' => [['name' => 'proxy']],
            'mounts' => [['source' => 'webmail-db', 'target' => '/var/roundcube/db']],
            'memory_mb' => 256,
            'labels' => [
                'io.dockermgr.mail' => $mail->id,
                'traefik.enable' => 'true',
                "traefik.http.routers.{$router}.rule" => "Host(`{$hostname}`)",
                "traefik.http.routers.{$router}.entrypoints" => 'websecure',
                "traefik.http.routers.{$router}.tls" => 'true',
                "traefik.http.routers.{$router}.tls.certresolver" => 'letsencrypt',
                "traefik.http.services.{$router}.loadbalancer.server.port" => '80',
            ],
        ], Tenant::current());
        $mail->update(['webmail_hostname' => $hostname, 'webmail_status' => 'installing']);
        $steps = [['container.remove', ['container_id' => self::WEBMAIL_CONTAINER, 'keep_volumes' => true, 'force' => true]], ['container.create', $spec]];
        $job = $this->jobs->create('mail', ['execute'], $mail, ['mail_server_id' => $mail->id, 'action' => 'webmail', 'steps' => $steps, 'step' => 0], $userId, $mail->organization_id);
        $this->dispatch($mail, $job, $steps[0]);
        $this->audit->log('mail.webmail_install', 'mail_servers', $mail->id, ['hostname' => $hostname]);

        return $mail->fresh();
    }

    /** UMAIL: where to point a mail app, and the webmail address. */
    public static function clientSettings(MailServer $mail, string $address): array
    {
        return [
            'username' => $address,
            'imap' => ['host' => $mail->hostname, 'port' => 993, 'security' => 'SSL/TLS'],
            'pop3' => ['host' => $mail->hostname, 'port' => 995, 'security' => 'SSL/TLS'],
            'smtp' => ['host' => $mail->hostname, 'port' => 587, 'security' => 'STARTTLS', 'auth' => 'normal password'],
            'webmail_url' => $mail->webmail_hostname && $mail->webmail_status === 'running' ? 'https://'.$mail->webmail_hostname.'/?_user='.rawurlencode($address) : null,
        ];
    }

    public function settings(MailServer $mail): array
    {
        return array_replace(self::DEFAULTS, $mail->settings ?? []);
    }

    /** MAIL-008 / MAIL-009: limits, spam scores and the relay; the container restarts to load them. */
    public function updateSettings(MailServer $mail, array $data, ?string $userId): MailServer
    {
        $set = $this->settings($mail);
        foreach (['send_per_hour', 'spam_reject', 'spam_header'] as $k) {
            if (isset($data[$k])) {
                $set[$k] = $data[$k];
            }
        }
        if ($set['spam_header'] >= $set['spam_reject']) {
            throw ApiException::validation(['spam_header' => ['The header score must be below the reject score.']]);
        }
        if (array_key_exists('relay', $data)) {
            $set['relay'] = $data['relay'] ? ['host' => strtolower($data['relay']['host']), 'port' => (int) ($data['relay']['port'] ?? 587), 'username' => (string) $data['relay']['username']] : null;
            if (! $data['relay']) {
                $mail->relay_password = null;
            } elseif (isset($data['relay']['password'])) {
                $mail->relay_password = $data['relay']['password'];
            } elseif (! $mail->relay_password) {
                throw ApiException::validation(['relay.password' => ['Enter the relay password.']]);
            }
        }
        $mail->settings = $set;
        $mail->save();
        $this->audit->log('mail.settings', 'mail_servers', $mail->id, ['send_per_hour' => $set['send_per_hour'], 'spam_reject' => $set['spam_reject'], 'spam_header' => $set['spam_header'], 'relay' => $set['relay']['host'] ?? null]);
        $this->sync($mail, $userId, restart: true);

        return $mail;
    }

    /** MAIL-012 */
    public function setAutoresponder(Mailbox $box, ?array $reply, ?string $userId): Mailbox
    {
        $box->update(['autoresponder' => $reply ? ['enabled' => true, 'subject' => $reply['subject'], 'body' => $reply['body'], 'until' => $reply['until'] ?? null] : ['enabled' => false]]);
        $this->audit->log('mail.autoresponder', 'mailboxes', $box->id, ['enabled' => (bool) $reply, 'until' => $reply['until'] ?? null]);
        $this->sync(MailServer::allTenants()->find(MailDomain::allTenants()->find($box->mail_domain_id)->mail_server_id), $userId);

        return $box;
    }

    /** A vacation script; quotes and backslashes are escaped for Sieve strings. */
    public static function sieve(?array $reply): string
    {
        $active = ($reply['enabled'] ?? false) && (empty($reply['until']) || now()->lt(\Carbon\Carbon::parse($reply['until'])->endOfDay()));
        if (! $active) {
            return "# Auto-reply is off.\n";
        }
        $q = fn (string $v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"';
        $script = "require [\"vacation\"";
        $cond = '';
        if (! empty($reply['until'])) {
            $script .= ', "date", "relational"';
            $cond = 'if currentdate :value "le" "date" '.$q(\Carbon\Carbon::parse($reply['until'])->toDateString()).' { ';
        }

        return $script."];\n".$cond.'vacation :days 1 :subject '.$q((string) $reply['subject']).' '.$q((string) $reply['body']).';'.($cond ? ' }' : '')."\n";
    }

    /**
     * MAIL-006: a new DKIM key under a new selector. It signs nothing until its record is in DNS
     * and the rotation is completed; the old record can be removed a few days later.
     */
    public function rotateDkim(MailDomain $domain, ?string $userId): MailDomain
    {
        $selector = 'mail'.now()->format('Ym');
        if ($selector === $domain->dkim_selector) {
            $selector .= 'b';
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $domain->update(['dkim_next_selector' => $selector, 'dkim_next_private' => $private,
            'dkim_next_public' => preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($key)['key'])]);
        $this->audit->log('mail.dkim_rotate', 'mail_domains', $domain->id, ['selector' => $selector]);
        $this->publish($domain);
        $this->sync(MailServer::allTenants()->find($domain->mail_server_id), $userId);

        return $domain;
    }

    public function completeDkimRotation(MailDomain $domain, ?string $userId): MailDomain
    {
        if (! $domain->dkim_next_selector) {
            throw ApiException::conflict('No DKIM rotation is in progress.');
        }
        $name = $domain->dkim_next_selector.'._domainkey.'.$domain->name;
        $found = array_map(fn ($v) => str_replace(['"', ' '], '', (string) $v), $this->dns->lookup($name, 'TXT'));
        if (! in_array(str_replace(' ', '', 'v=DKIM1;k=rsa;p='.$domain->dkim_next_public), $found, true)) {
            throw ApiException::conflict("The new key is not in DNS yet ({$name}); signing with it now would fail verification.");
        }
        $domain->update([
            'dkim_previous_selector' => $domain->dkim_selector, 'dkim_selector' => $domain->dkim_next_selector,
            'dkim_private' => $domain->dkim_next_private, 'dkim_public' => $domain->dkim_next_public,
            'dkim_next_selector' => null, 'dkim_next_private' => null, 'dkim_next_public' => null, 'dkim_rotated_at' => now(),
        ]);
        $this->audit->log('mail.dkim_activate', 'mail_domains', $domain->id, ['selector' => $domain->dkim_selector, 'previous' => $domain->dkim_previous_selector]);
        $this->sync(MailServer::allTenants()->find($domain->mail_server_id), $userId);

        return $domain;
    }

    /** MAIL-010: reverse DNS, the hostname's A record, and DNS blocklists for the server's address. */
    public function checkHealth(MailServer $mail): array
    {
        $ip = (string) Server::allTenants()->find($mail->server_id)?->ip;
        $out = ['ip' => $ip, 'ptr' => [], 'ptr_ok' => false, 'a_ok' => false, 'listed' => []];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $rev = implode('.', array_reverse(explode('.', $ip)));
            $out['ptr'] = array_map(fn ($v) => rtrim((string) $v, '.'), $this->dns->lookup($rev.'.in-addr.arpa', 'PTR'));
            $out['ptr_ok'] = in_array($mail->hostname, $out['ptr'], true);
            $out['a_ok'] = in_array($ip, $this->dns->lookup($mail->hostname, 'A'), true);
            foreach (self::BLOCKLISTS as $bl) {
                // 127.255.255.x answers mean the query was refused (e.g. a public resolver), not a listing.
                $hits = array_filter($this->dns->lookup($rev.'.'.$bl, 'A'), fn ($a) => str_starts_with((string) $a, '127.') && ! str_starts_with((string) $a, '127.255.255.'));
                if ($hits) {
                    $out['listed'][] = $bl;
                }
            }
        }
        $was = $mail->health['listed'] ?? [];
        $mail->update(['health' => $out, 'health_checked_at' => now()]);
        if ($new = array_diff($out['listed'], $was)) {
            $this->audit->log('mail.blocklisted', 'mail_servers', $mail->id, ['ip' => $ip, 'lists' => array_values($new)], $mail->organization_id);
            // MAIL-008: through the organisation's alert channels, like every other alert.
            foreach (\App\Models\NotificationChannel::allTenants()->where('organization_id', $mail->organization_id)->where('enabled', true)->get() as $channel) {
                app(NotificationService::class)->send($channel, "Mail server {$mail->hostname} is blocklisted",
                    "{$ip} is listed on ".implode(', ', $new).'. Outgoing mail may be rejected; use a relay or ask the list to delist the address.', ['severity' => 'critical']);
            }
        }

        return $out;
    }

    /** MAIL-005: copy a renewed certificate from Traefik; the container restarts only when it changed. */
    public function refreshCerts(MailServer $mail): ?Job
    {
        $server = Server::allTenants()->find($mail->server_id);
        if ($mail->status !== 'running' || ! $server?->isOnline()) {
            return null;
        }
        $job = $this->jobs->create('mail', ['execute'], $mail, ['mail_server_id' => $mail->id, 'action' => 'certs', 'steps' => [], 'step' => 0], null, $mail->organization_id);
        $this->bus->dispatch($server, 'mail.certs', ['hostname' => $mail->hostname, 'volume' => self::CONFIG_VOLUME, 'fallback' => false], $job);

        return $job;
    }

    /**
     * MAIL-010: a nightly restic backup of the mailboxes (the mailserver-data volume) through the
     * Backup manager, to local disk or to the destination of an existing policy.
     */
    public function backupPolicy(MailServer $mail, array $data): \App\Models\BackupPolicy
    {
        $volume = \App\Models\Volume::allTenants()->firstOrCreate(
            ['server_id' => $mail->server_id, 'name' => 'mailserver-data'],
            ['organization_id' => $mail->organization_id, 'type' => 'local'],
        );
        $from = isset($data['copy_destination_from']) ? \App\Models\BackupPolicy::findOrFail($data['copy_destination_from']) : null;
        $policy = \App\Models\BackupPolicy::allTenants()->updateOrCreate(
            ['organization_id' => $mail->organization_id, 'target_type' => 'volume', 'target_id' => $volume->id],
            array_filter([
                'name' => 'Mail: '.$mail->hostname, 'method' => 'restic', 'schedule' => $data['schedule'] ?? '30 2 * * *',
                'retention_days' => $data['retention_days'] ?? 14, 'enabled' => true,
                'destination' => $from?->destination ?? 'local', 'destination_config' => $from?->destination_config,
            ], fn ($v) => $v !== null),
        );
        $this->audit->log('mail.backup_policy', 'mail_servers', $mail->id, ['policy' => $policy->id, 'destination' => $policy->destination, 'schedule' => $policy->schedule]);

        return $policy;
    }

    /** MAIL-014 */
    public function logs(MailServer $mail, int $tail): array
    {
        $container = Container::allTenants()->where('server_id', $mail->server_id)->where('name', self::CONTAINER)->firstOrFail();

        return app(ContainerService::class)->logs($container, $tail);
    }

    /** Rewrites every file on the mail server; folders first. */
    public function sync(MailServer $mail, ?string $userId, bool $restart = false): ?Job
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
        if ($restart) {
            $steps[] = ['container.restart', ['container_id' => self::CONTAINER]];
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
            if ($job->payload['action'] === 'webmail' && $command->type !== 'container.remove') {
                $mail->update(['webmail_status' => 'failed']);
            }
            if ($job->payload['action'] === 'webmail' && $command->type === 'container.remove') {
                // Nothing to remove on a first install: carry on.
                $next = $job->payload['steps'][1];
                $job->update(['payload' => ['step' => 1] + $job->payload]);
                $this->dispatch($mail, $job, $next);

                return;
            }
            $this->jobs->fail($job, $message, 'execute');

            return;
        }
        if ($command->type === 'mail.certs') {
            $mail->update(['tls' => array_intersect_key((array) $command->output, array_flip(['source', 'not_after', 'found']))]);
            if ($job->payload['action'] === 'certs') {
                $changed = (bool) ($command->output['changed'] ?? false);
                $changed && $mail->status === 'running'
                    ? $this->bus->dispatch(Server::allTenants()->find($mail->server_id), 'container.restart', ['container_id' => self::CONTAINER])
                    : null;
                $this->jobs->succeed($job, ['changed' => $changed]);

                return;
            }
        }
        if ($job->payload['action'] === 'install' && $command->type === 'container.create') {
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
        if ($job->payload['action'] === 'webmail') {
            $mail->update(['webmail_status' => 'running']);
            $this->jobs->succeed($job, ['webmail' => 'https://'.$mail->webmail_hostname]);

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
