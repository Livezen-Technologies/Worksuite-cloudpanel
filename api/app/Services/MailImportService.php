<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Job;
use App\Models\MailDomain;
use App\Models\MailImport;
use App\Models\MailServer;
use App\Models\Mailbox;
use App\Models\Server;

/**
 * MCL-10 / UMAIL: copy a mailbox from another provider (Google Workspace, Microsoft 365,
 * cPanel or any IMAP server) with imapsync. The agent runs imapsync in a one-off container
 * sharing the mail container's network, and signs in to Dovecot as the master user, so the
 * destination mailbox's own password is never needed. The source password is kept encrypted
 * only until the import finishes.
 */
class MailImportService implements HandlesCommandResults
{
    public const HOST = '/^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/';

    /** Common providers, so people can pick one instead of typing a host. */
    public const PRESETS = [
        'gmail' => ['label' => 'Google Workspace / Gmail', 'host' => 'imap.gmail.com', 'port' => 993, 'security' => 'ssl', 'hint' => 'Use an app password; IMAP must be on in Gmail settings.'],
        'outlook' => ['label' => 'Microsoft 365 / Outlook', 'host' => 'outlook.office365.com', 'port' => 993, 'security' => 'ssl', 'hint' => 'Use an app password, or ask your admin to allow IMAP with basic sign-in.'],
        'cpanel' => ['label' => 'cPanel', 'host' => '', 'port' => 993, 'security' => 'ssl', 'hint' => 'The host is usually mail.<your domain>.'],
        'other' => ['label' => 'Other IMAP server', 'host' => '', 'port' => 993, 'security' => 'ssl', 'hint' => ''],
    ];

    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit, private MailService $mail) {}

    public function start(Mailbox $box, array $data, ?string $userId): MailImport
    {
        $domain = MailDomain::allTenants()->findOrFail($box->mail_domain_id);
        $mail = MailServer::allTenants()->findOrFail($domain->mail_server_id);
        if ($mail->status !== 'running') {
            throw ApiException::conflict('The mail server is not running yet.');
        }
        $server = Server::allTenants()->find($mail->server_id);
        if (! $server?->isOnline()) {
            throw ApiException::conflict('The mail server is offline.');
        }
        if (! preg_match(self::HOST, $data['host'])) {
            throw ApiException::validation(['host' => ['Enter the IMAP server name, e.g. imap.gmail.com.']]);
        }
        if (MailImport::allTenants()->where('mailbox_id', $box->id)->whereIn('status', ['queued', 'running'])->exists()) {
            throw ApiException::conflict('An import into this mailbox is already running.');
        }
        $import = MailImport::create([
            'mailbox_id' => $box->id, 'source_host' => strtolower($data['host']), 'source_port' => (int) ($data['port'] ?? 993),
            'source_security' => $data['security'] ?? 'ssl', 'source_user' => $data['username'], 'source_password' => $data['password'],
            'status' => 'running', 'user_id' => $userId, 'started_at' => now(),
        ]);
        $address = $box->local_part.'@'.$domain->name;
        $job = $this->jobs->create('mail.import', ['import'], $import, ['mail_import_id' => $import->id], $userId, $import->organization_id);
        $import->update(['job_id' => $job->id]);
        // The master user is already in Dovecot: every sync writes dovecot-masters.cf, and creating the mailbox synced.
        $this->bus->dispatch($server, 'mail.import', [
            'run_id' => $import->id,
            'container' => MailService::CONTAINER,
            'source' => ['host' => $import->source_host, 'port' => $import->source_port, 'security' => $import->source_security, 'user' => $import->source_user, 'password' => $data['password']],
            'dest' => ['user' => $address.'*'.MailService::MASTER_USER, 'password' => $this->mail->masterPassword($mail)],
        ], $job);
        $this->audit->log('mail.import_start', 'mailboxes', $box->id, ['address' => $address, 'source' => $import->source_user.' @ '.$import->source_host]);

        return $import;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $import = MailImport::allTenants()->find($job->payload['mail_import_id'] ?? null);
        if (! $import) {
            return;
        }
        $out = (array) $command->output;
        $ok = $command->status === 'succeeded' && (int) ($out['exit_code'] ?? 1) === 0;
        $import->update([
            'status' => $ok ? 'succeeded' : 'failed',
            'messages' => $out['messages'] ?? null, 'folders' => $out['folders'] ?? null, 'bytes' => $out['bytes'] ?? null, 'errors' => $out['errors'] ?? null,
            'log' => mb_strcut((string) ($out['output'] ?? $command->message ?? ''), -16384),
            // The source password is needed only while the import runs.
            'source_password' => '',
            'finished_at' => now(),
        ]);
        $ok ? $this->jobs->succeed($job, ['messages' => $import->messages])
            : $this->jobs->fail($job, $command->status === 'succeeded' ? self::reason($out) : ($command->message ?: 'The import failed.'), 'import');
    }

    /** The first line of imapsync's output that says what went wrong. */
    private static function reason(array $out): string
    {
        foreach (preg_split('/\R/', (string) ($out['output'] ?? '')) as $line) {
            if (preg_match('/(Error login|failed login|LOGIN failed|AUTHENTICATIONFAILED|Can not connect|Connection refused|timed out)/i', $line)) {
                return trim($line);
            }
        }

        return 'imapsync exited with code '.($out['exit_code'] ?? '?').'.';
    }

    public static function present(MailImport $i): array
    {
        return [
            'id' => $i->id, 'mailbox_id' => $i->mailbox_id, 'source' => $i->source_user.' @ '.$i->source_host.':'.$i->source_port,
            'status' => $i->status, 'messages' => $i->messages, 'folders' => $i->folders, 'bytes' => $i->bytes, 'errors' => $i->errors,
            'log' => $i->log, 'job_id' => $i->job_id,
            'started_at' => $i->started_at?->toIso8601ZuluString(), 'finished_at' => $i->finished_at?->toIso8601ZuluString(),
        ];
    }
}
