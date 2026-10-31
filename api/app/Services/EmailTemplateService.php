<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Transactional emails (SRS EML-1..5). Copy comes from config/email_templates.php with any
 * admin overrides on top; {{variables}} are checked against the template's list when saved and
 * HTML-escaped when rendered. Every email is sent with an HTML and a plain-text part.
 */
class EmailTemplateService
{
    public const FIELDS = ['subject', 'preview', 'eyebrow', 'heading', 'body', 'code', 'button_label', 'button_url', 'secondary_label', 'secondary_url'];

    /** Tag and bar colours per tone (design: Email design). */
    public const TONES = ['blue' => ['#EFF6FF', '#1D4ED8', '#bfdbfe,#93c5fd,#b060ff'], 'slate' => ['#F3F4F6', '#374151', '#e5e7eb,#cbd5e1,#94a3b8'], 'red' => ['#FEF2F2', '#B91C1C', '#fecaca,#ee87cb,#b060ff'], 'green' => ['#F0FDF4', '#15803D', '#bbf7d0,#99f6e4,#b060ff'], 'amber' => ['#FFFBEB', '#B45309', '#fff1be,#fdba74,#ee87cb'], 'violet' => ['#F5F3FF', '#6D28D9', '#ddd6fe,#c4b5fd,#b060ff']];

    public const GLOBALS = ['platform_name', 'user_name', 'panel_url'];

    public const BRAND_DEFAULTS = ['sender_name' => null, 'logo_url' => null, 'color' => '#4F46E5', 'footer' => 'You receive this email because you have an account on {{platform_name}}.'];

    public function __construct(private AuditLogger $audit) {}

    public function definition(string $key): array
    {
        return config("email_templates.templates.{$key}") ?? abort(404, 'No such email template.');
    }

    public function mandatory(string $key): bool
    {
        return (bool) config('email_templates.groups.'.$this->definition($key)['group'].'.mandatory');
    }

    /** The template as it will be sent: defaults, then overrides. */
    public function resolved(string $key): array
    {
        $def = $this->definition($key);
        $row = EmailTemplate::where('key', $key)->first();
        $fields = array_replace(array_fill_keys(self::FIELDS, null), array_intersect_key($def, array_flip(self::FIELDS)), array_filter($row?->overrides ?? [], fn ($v) => $v !== null));

        return $fields + ['key' => $key, 'group' => $def['group'], 'tone' => $def['tone'] ?? 'blue', 'name' => $def['name'], 'details' => $def['details'] ?? [],
            'variables' => array_merge(self::GLOBALS, $def['vars']), 'enabled' => $row?->enabled ?? true,
            'mandatory' => $this->mandatory($key), 'customised' => (bool) array_filter($row?->overrides ?? [])];
    }

    /** EML-2/3: save overrides; unknown {{variables}} are refused. */
    public function save(string $key, array $input, ?string $userId): array
    {
        $def = $this->definition($key);
        $allowed = array_merge(self::GLOBALS, $def['vars']);
        $errors = [];
        foreach (array_intersect_key($input, array_flip(self::FIELDS)) as $field => $value) {
            preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', (string) $value, $m);
            if ($bad = array_diff($m[1], $allowed)) {
                $errors[$field] = ['Unknown variable: {{'.implode('}}, {{', $bad).'}}. Available: {{'.implode('}}, {{', $allowed).'}}.'];
            }
        }
        if (array_key_exists('enabled', $input) && ! $input['enabled'] && $this->mandatory($key)) {
            $errors['enabled'] = ['Security and billing emails cannot be turned off.'];
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        $row = EmailTemplate::firstOrNew(['key' => $key]);
        $row->overrides = array_replace($row->overrides ?? [], array_intersect_key($input, array_flip(self::FIELDS)));
        if (array_key_exists('enabled', $input)) {
            $row->enabled = (bool) $input['enabled'];
        }
        $row->updated_by = $userId;
        $row->save();
        $this->audit->log('email_template.update', 'email_templates', (string) $row->id, ['key' => $key, 'fields' => array_keys(array_intersect_key($input, array_flip(self::FIELDS))), 'enabled' => $row->enabled]);

        return $this->resolved($key);
    }

    public function reset(string $key): array
    {
        $this->definition($key);
        EmailTemplate::where('key', $key)->update(['overrides' => null]);
        $this->audit->log('email_template.reset', 'email_templates', null, ['key' => $key]);

        return $this->resolved($key);
    }

    /** The platform's brand lives on the first organisation, like its SMTP settings. */
    public function brand(): array
    {
        return array_replace(self::BRAND_DEFAULTS, data_get($this->platformOrg()?->settings, 'email_brand', []));
    }

    public function saveBrand(array $input): array
    {
        $org = $this->platformOrg() ?? throw ApiException::conflict('No organisation exists yet.');
        $settings = $org->settings ?? [];
        $settings['email_brand'] = array_replace($this->brand(), array_intersect_key($input, self::BRAND_DEFAULTS));
        $org->update(['settings' => $settings]);
        $this->audit->log('email_template.brand', 'organizations', $org->id, array_intersect_key($input, self::BRAND_DEFAULTS));

        return $this->brand();
    }

    /** Example values for previews and test sends. */
    public function sample(string $key): array
    {
        $samples = [
            'inviter' => 'Alex Perera', 'organization' => 'Acme Ltd', 'role' => 'Developer', 'accept_url' => '{{panel_url}}/invite/abc123',
            'verify_url' => '{{panel_url}}/verify/abc123', 'reset_url' => '{{panel_url}}/reset/abc123', 'expires_minutes' => '60',
            'ip' => '203.0.113.7', 'location' => 'Colombo, Sri Lanka', 'device' => 'Chrome on macOS', 'time' => now()->format('j M Y H:i').' UTC',
            'app' => 'shop', 'version' => '42', 'url' => 'https://shop.example.com', 'duration' => '1m 12s', 'stage' => 'build',
            'error' => 'npm ERR! missing script: build', 'deployment_url' => '{{panel_url}}/deployments', 'branch' => 'feature/checkout',
            'domain' => 'shop.example.com', 'days' => '7', 'reason' => 'The DNS-01 challenge timed out.', 'target' => 'shop database',
            'snapshot' => 'yesterday at 02:00', 'server' => 'web-1', 'since' => '10:42 UTC', 'title' => 'CPU above 90% on web-1',
            'message' => 'CPU has been above 90% for 10 minutes.', 'severity' => 'critical', 'site' => 'shop.example.com', 'count' => '5',
            'security' => '2', 'path' => 'wp-content/uploads/x.php', 'signature' => 'YARA.PHP.WebShell', 'action' => 'quarantined',
            'number' => 'INV-2026-0042', 'amount' => 'LKR 12,500.00', 'period' => 'October 2026', 'invoice_url' => '{{panel_url}}/billing',
            'retry_date' => now()->addDays(3)->format('j M'), 'resource' => 'apps', 'used' => '5', 'limit' => '5', 'plan' => 'Standard',
            'started' => now()->format('j M Y, H:i').' UTC', 'mode' => 'Browser check for every visitor (CrowdSec)', 'customer' => 'Acme Ltd', 'by' => 'ops@example.com',
            'scenario' => 'crowdsecurity/http-bf-wordpress_bf', 'origins' => 'VN 41% · BR 18% · RU 12%', 'targets' => '38 sites · /wp-login.php', 'window' => '5 minutes',
            'log' => "1284   crowdsecurity/http-bf-wordpress_bf\n212    crowdsecurity/http-probing",
        ];

        return array_intersect_key($samples, array_flip($this->definition($key)['vars']));
    }

    /** @return array{subject: string, preview: string, html: string, text: string} */
    public function render(string $key, array $vars = [], ?User $user = null): array
    {
        $t = $this->resolved($key);
        $brand = $this->brand();
        $platform = $brand['sender_name'] ?: config('app.name', 'WorkSuite CloudPanel');
        $panel = rtrim((string) (config('platform.panel_url') ?: config('app.url')), '/');
        $vars = array_map('strval', array_replace(['platform_name' => $platform, 'user_name' => $user?->name ?? 'there', 'panel_url' => $panel], $vars));
        // A variable may hold {{panel_url}} (sample links); resolve globals inside values once.
        $vars = array_map(fn ($v) => str_replace('{{panel_url}}', $panel, $v), $vars);
        $fill = fn (?string $s) => $s === null ? null : preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => $vars[$m[1]] ?? '', $s);

        $f = array_map($fill, array_intersect_key($t, array_flip(self::FIELDS)));
        $details = array_map($fill, $t['details']);
        $footer = $fill($brand['footer']);

        return [
            'subject' => $f['subject'], 'preview' => (string) $f['preview'],
            'html' => $this->html($f, $details, $brand, $platform, $footer, $t['tone'], (string) config('email_templates.groups.'.$t['group'].'.name')),
            'text' => $this->text($f, $details, $footer),
        ];
    }

    /**
     * Sends a template to a user or an address. Returns false when it is not sent: the template
     * is off, or the user opted out of it (never for security and billing emails).
     */
    public function send(string $key, User|string $to, array $vars = []): bool
    {
        $t = $this->resolved($key);
        $user = $to instanceof User ? $to : null;
        if (! $t['enabled'] && ! $t['mandatory']) {
            return false;
        }
        if ($user && ! $t['mandatory'] && in_array($key, $user->email_preferences['opt_out'] ?? [], true)) {
            return false;
        }
        $mail = $this->render($key, $vars, $user);
        $brand = $this->brand();
        Mail::send([], [], function ($m) use ($mail, $to, $brand) {
            $m->to($to instanceof User ? $to->email : $to)->subject($mail['subject']);
            if ($brand['sender_name']) {
                $m->from(config('mail.from.address'), $brand['sender_name']);
            }
            $m->html($mail['html']);
            $m->getSymfonyMessage()->text($mail['text']);
        });

        return true;
    }

    /** Who in an organisation receives each group's emails, by role. */
    public const RECIPIENTS = [
        'deployments' => ['owner', 'super_admin', 'devops', 'developer'],
        'billing' => ['owner', 'billing'],
        'default' => ['owner', 'super_admin', 'infra_admin', 'devops'],
    ];

    /**
     * Sends from an event: never throws, so a mail outage cannot fail the work that
     * triggered it. Returns whether the email went out.
     */
    public function notify(string $key, User|string $to, array $vars = []): bool
    {
        try {
            return $this->send($key, $to, $vars);
        } catch (\Throwable $e) {
            Log::warning("Email '{$key}' was not sent: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Sends to the active members of an organisation whose role receives the template's
     * group. Returns how many were sent; repeated events inside $dedupeMinutes for the
     * same $dedupeKey send nothing.
     */
    public function notifyOrganization(?string $organizationId, string $key, array $vars = [], ?string $dedupeKey = null, int $dedupeMinutes = 0): int
    {
        if (! $organizationId) {
            return 0;
        }
        if ($dedupeKey && $dedupeMinutes > 0 && ! Cache::add("email:{$key}:{$organizationId}:{$dedupeKey}", 1, now()->addMinutes($dedupeMinutes))) {
            return 0;
        }
        $roles = self::RECIPIENTS[$this->definition($key)['group']] ?? self::RECIPIENTS['default'];
        $users = User::whereIn('id', Membership::allTenants()->where('organization_id', $organizationId)
            ->where('status', 'active')->whereIn('role', $roles)->select('user_id'))->get();

        return $users->filter(fn (User $u) => $this->notify($key, $u, $vars))->count();
    }

    /**
     * Sends to platform staff (people holding a platform-scope role in any organisation),
     * once per $dedupeKey within $dedupeMinutes. Returns how many were sent.
     */
    public function notifyStaff(string $key, array $vars = [], ?string $dedupeKey = null, int $dedupeMinutes = 0): int
    {
        if ($dedupeKey && $dedupeMinutes > 0 && ! Cache::add("email:{$key}:staff:{$dedupeKey}", 1, now()->addMinutes($dedupeMinutes))) {
            return 0;
        }
        $roles = array_keys(array_filter(config('rbac.roles'), fn ($r) => ($r['scope'] ?? '') === 'platform'));
        $roles = array_merge($roles, \App\Models\Role::where('scope', 'platform')->pluck('name')->all());
        $roles = array_values(array_diff(array_unique($roles), ['viewer', 'billing']));
        $users = User::whereIn('id', Membership::allTenants()->where('status', 'active')->whereIn('role', $roles)->select('user_id'))->get();

        return $users->filter(fn (User $u) => $this->notify($key, $u, $vars))->count();
    }

    /** EML-4: a 600 px table layout with inline styles, fluid down to 360 px, with a dark-mode stylesheet. */
    private function html(array $f, array $details, array $brand, string $platform, string $footer, string $tone = 'blue', string $tag = ''): string
    {
        [$tagBg, $tagFg, $bar] = self::TONES[$tone] ?? self::TONES['blue'];
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $brand['color']) ? $brand['color'] : self::BRAND_DEFAULTS['color'];
        $safeUrl = fn (?string $u) => $u && preg_match('#^https?://#i', $u) ? $u : null;
        $logo = $safeUrl($brand['logo_url'])
            ? '<img src="'.$e($brand['logo_url']).'" alt="'.$e($platform).'" height="32" style="height:32px;border:0;display:block">'
            : '<span style="font-size:18px;font-weight:700;color:'.$color.'">'.$e($platform).'</span>';
        $body = implode('', array_map(fn ($p) => '<p style="margin:0 0 16px">'.nl2br($e(trim($p)), false).'</p>', preg_split('/\n\s*\n/', trim((string) $f['body'])) ?: []));
        $rows = implode('', array_map(fn ($k, $v) => '<tr><td class="muted" style="padding:6px 0;color:#6b7280;width:40%">'.$e($k).'</td><td style="padding:6px 0;font-weight:600">'.$e($v).'</td></tr>', array_keys($details), $details));
        $button = ($url = $safeUrl($f['button_url'])) && $f['button_label']
            ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 20px"><tr><td style="border-radius:999px;background:'.$color.'"><a href="'.$e($url).'" style="display:inline-block;padding:13px 24px;color:#ffffff;text-decoration:none;font-weight:600;border-radius:999px">'.$e($f['button_label']).'</a></td></tr></table>'
            : '';
        $secondary = ($url2 = $safeUrl($f['secondary_url'])) && $f['secondary_label']
            ? '<p style="margin:0 0 16px"><a href="'.$e($url2).'" style="color:'.$color.'">'.$e($f['secondary_label']).'</a></p>' : '';
        $code = trim((string) ($f['code'] ?? '')) !== ''
            ? '<div style="margin:0 0 20px;padding:14px;border-radius:12px;background:#030712;color:#e5e7eb;font:500 12.5px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-word">'.$e(mb_substr(trim((string) $f['code']), 0, 2000)).'</div>' : '';
        $eyebrow = trim((string) ($f['eyebrow'] ?? '')) !== '' ? '<div style="margin:0 0 10px;font:600 11.5px/18px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.14em;text-transform:uppercase;color:#6b7280">'.$e($f['eyebrow']).'</div>' : '';
        $tagHtml = $tag !== '' ? '<span style="display:inline-block;padding:3px 9px;border-radius:999px;font:600 11px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.06em;background:'.$tagBg.';color:'.$tagFg.'">'.$e(strtoupper($tag)).'</span>' : '';
        $fallback = $url ? '<p class="muted" style="margin:16px 0 0;font-size:12px;color:#6b7280">If the button does not work, copy this link into your browser:<br><a href="'.$e($url).'" style="color:'.$color.';word-break:break-all">'.$e($url).'</a></p>' : '';

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">
<title>{$e($f['subject'])}</title>
<style>
  @media (max-width:620px){ .container{width:100% !important} .pad{padding:24px 20px !important} }
  @media (prefers-color-scheme:dark){ body,.bg{background:#0b0d12 !important} .card{background:#151922 !important;color:#e5e7eb !important} .muted{color:#9ca3af !important} h1{color:#f9fafb !important} }
</style></head>
<body style="margin:0;padding:0;background:#f4f5f7">
<div style="display:none;max-height:0;overflow:hidden;opacity:0">{$e($f['preview'])}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="bg" style="background:#f4f5f7"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" class="container" style="width:600px;max-width:600px">
<tr><td class="card" style="background:#ffffff;border-radius:20px 20px 0 0;height:8px;line-height:8px;font-size:0;background-image:linear-gradient(90deg,{$bar})">&nbsp;</td></tr>
<tr><td class="card" style="background:#ffffff;padding:24px 32px 0;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td>{$logo}</td><td align="right">{$tagHtml}</td></tr></table></td></tr>
<tr><td class="card pad" style="background:#ffffff;border-radius:0 0 20px 20px;padding:24px 32px 32px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#111827">
{$eyebrow}<h1 style="margin:0 0 16px;font-size:26px;line-height:1.2;letter-spacing:-0.02em;color:#111827">{$e($f['heading'])}</h1>
{$body}{$code}
{$this->detailsTable($rows)}
<div style="height:20px;line-height:20px;font-size:0">&nbsp;</div>{$button}{$secondary}
{$fallback}
</td></tr>
<tr><td class="muted" style="padding:16px 4px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:12px;color:#6b7280">{$e($footer)}</td></tr>
</table></td></tr></table>
</body></html>
HTML;
    }

    private function detailsTable(string $rows): string
    {
        return $rows ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 0;padding:4px 16px;border-radius:14px;background:#f9fafb;font-size:14px">'.$rows.'</table>' : '';
    }

    private function text(array $f, array $details, string $footer): string
    {
        $lines = [$f['heading'], '', trim((string) $f['body'])];
        if (trim((string) ($f['code'] ?? '')) !== '') {
            $lines[] = '';
            $lines[] = trim((string) $f['code']);
        }
        if ($details) {
            $lines[] = '';
            foreach ($details as $k => $v) {
                $lines[] = "{$k}: {$v}";
            }
        }
        if ($f['button_label'] && $f['button_url']) {
            $lines[] = '';
            $lines[] = "{$f['button_label']}: {$f['button_url']}";
        }
        if ($f['secondary_label'] && $f['secondary_url']) {
            $lines[] = "{$f['secondary_label']}: {$f['secondary_url']}";
        }
        $lines[] = '';
        $lines[] = '--';
        $lines[] = $footer;

        return implode("\n", $lines)."\n";
    }

    private function platformOrg(): ?Organization
    {
        return Organization::query()->orderBy('created_at')->first();
    }
}
