<?php

namespace App\Support;

use App\Models\Application;

/**
 * The public maintenance page for a locked application: one self-contained HTML document
 * (inline CSS and SVG, no external requests), light and dark, reloading itself every 60 s.
 * Everything taken from the app (reason, times) is HTML-escaped.
 */
class MaintenancePage
{
    public static function retryAfter(?Application $app): int
    {
        $until = $app?->maintenance_until;
        if ($until && $until->isFuture()) {
            return (int) max(60, min(86400, now()->diffInSeconds($until, true)));
        }

        return max(60, (int) config('platform.maintenance_page.retry_after', 300));
    }

    public static function render(?Application $app): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $reason = trim((string) $app?->lock_reason);
        $since = $app?->locked_at;
        $until = $app?->maintenance_until && $app->maintenance_until->isFuture() ? $app->maintenance_until : null;

        // A moment as "11:00 PM" + "Fri 20 Nov"; the script rewrites both into the visitor's own time zone.
        $moment = function (string $label, $at) use ($e) {
            $utc = $at->copy()->utc();

            return '<div class="fact"><dt>'.$label.'</dt><dd><time datetime="'.$e($utc->toIso8601String()).'" data-local="time">'.$e($utc->format('H:i')).'</time>'
                .'<span class="sub"><span data-local-of="'.$e($utc->toIso8601String()).'">'.$e($utc->format('D j M')).'</span> · <span data-tzword>UTC</span></span></dd></div>';
        };
        $facts = ($since ? $moment('Started', $since) : '').($until ? $moment('Expected back', $until) : '');
        if ($facts === '') {
            $facts = '<div class="fact wide"><dt>Expected back</dt><dd><span class="soon">Shortly</span><span class="sub">We have not set an exact time</span></dd></div>';
        }
        $reasonHtml = $reason !== '' ? '<div class="reason"><span class="lbl">Note from the team</span><p>'.$e($reason).'</p></div>' : '';
        $admin = config('platform.maintenance_page.admin_link') && preg_match('#^https://#i', (string) config('app.url'))
            ? '<a class="tl" href="'.$e(rtrim((string) config('app.url'), '/')).'/" rel="nofollow">Admin? Sign in to CloudPanel</a>'
            : '';
        $mail = (string) config('platform.maintenance_page.support_email');
        $support = filter_var($mail, FILTER_VALIDATE_EMAIL) ? '<a class="btn sec" href="mailto:'.$e($mail).'">Contact support</a>' : '';
        $actions = $support !== '' ? '<div class="actions">'.$support.'</div>' : '';
        $statusUrl = (string) config('platform.maintenance_page.status_url');
        $status = preg_match('#^https://#i', $statusUrl) ? '<a class="tl" href="'.$e($statusUrl).'" rel="nofollow">System status</a>' : '';
        $links = $status.$admin;
        $year = now()->year;

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><meta http-equiv="refresh" content="60"><meta name="color-scheme" content="dark light">
<title>We're making improvements · WorkSuite</title>
<style>
:root{color-scheme:dark;--bg:#050914;--glow:rgba(10,108,255,.20);--glow2:rgba(34,211,238,.06);--line:#1d2943;--line2:#26345a;--tx:#e8edf6;--tx2:#c3ccdc;--hd:#fff;--mut:#93a0b8;--soft:#6f7d97;--brand:#0a6cff;--brand2:#3d8bff;--cyan:#22d3ee;--warn:#f5b84a;--warn-tx:#ffd88f;--ok-tx:#bff3df;--ok-bd:rgba(34,195,142,.35);--ok-bg:rgba(34,195,142,.07);--panel:rgba(255,255,255,.025);--hi-bg:linear-gradient(180deg,rgba(10,108,255,.10),rgba(10,108,255,.03));--wm2:#93a0b8}
@media (prefers-color-scheme:light){:root{color-scheme:light;--bg:#f5f7fc;--glow:rgba(10,108,255,.12);--glow2:rgba(34,211,238,.08);--line:#dfe4ef;--line2:#cfd7e6;--tx:#1b2333;--tx2:#36405a;--hd:#0b1222;--mut:#5b6680;--soft:#6b7690;--warn-tx:#8a5a00;--ok-tx:#0d5c41;--ok-bd:rgba(20,160,110,.35);--ok-bg:rgba(34,195,142,.08);--panel:#ffffff;--hi-bg:linear-gradient(180deg,rgba(10,108,255,.07),#fff);--wm2:#5b6680}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:radial-gradient(900px 420px at 50% -180px,var(--glow),transparent 70%),radial-gradient(600px 300px at 100% 100%,var(--glow2),transparent 70%),var(--bg);color:var(--tx);font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased}
a{color:#7cb2ff}
.top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:20px 40px}
.logo{display:flex;align-items:center;gap:12px;color:var(--hd);font-weight:700;font-size:17px;letter-spacing:-.015em}
.logo .wm2{color:var(--wm2);font-weight:600}
.tlinks{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.tl{font-size:13px;color:var(--tx2);text-decoration:none;padding:7px 11px;border-radius:10px}
.tl:hover,.tl:focus-visible{background:rgba(127,140,170,.12);color:var(--hd)}
main{flex:1;width:100%;max-width:1180px;margin:0 auto;padding:36px 40px 56px;display:flex;flex-direction:column;gap:28px}
.hero{display:flex;gap:40px;flex-wrap:wrap;align-items:center}
.copy{flex:1 1 520px;display:flex;flex-direction:column;gap:16px}
.badge{align-self:flex-start;display:inline-flex;align-items:center;gap:8px;height:28px;padding:0 12px;border-radius:999px;font-size:11.5px;font-weight:650;letter-spacing:.02em;color:var(--warn-tx);border:1px solid rgba(245,184,74,.4);background:rgba(245,184,74,.10)}
.dot{width:8px;height:8px;border-radius:999px;background:var(--warn);box-shadow:0 0 0 4px rgba(245,184,74,.2);animation:pulse 2s infinite}
@keyframes pulse{50%{box-shadow:0 0 0 7px rgba(245,184,74,.05)}}
h1{margin:0;font-size:46px;line-height:1.15;letter-spacing:-.035em;font-weight:700;color:var(--hd);text-wrap:balance}
.lead{margin:0;color:var(--mut);font-size:16.5px;max-width:620px}
.reason{max-width:620px;padding:14px 16px;border-radius:14px;border:1px solid var(--line2);background:var(--panel)}
.reason p{margin:0;color:var(--tx);white-space:pre-line;overflow-wrap:anywhere}
.lbl{display:block;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--mut);font-weight:700;margin-bottom:4px}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px}
.btn{display:inline-flex;align-items:center;justify-content:center;height:46px;padding:0 20px;border-radius:13px;font-weight:650;font-size:14.5px;text-decoration:none;color:var(--tx);border:1px solid var(--line2);background:rgba(127,140,170,.08)}
.btn:hover,.btn:focus-visible{background:rgba(127,140,170,.16);color:var(--hd)}
.panel{border:1px solid var(--line);border-radius:18px;background:var(--panel);padding:20px}
.win{flex:1 1 360px;padding:26px;display:flex;flex-direction:column;gap:18px;border-color:rgba(61,139,255,.45);background:var(--hi-bg);box-shadow:0 0 0 4px rgba(10,108,255,.08)}
.facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:0}
.fact.wide{grid-column:1/-1}
.fact dt{font-size:12.5px;color:var(--mut)}
.fact dd{margin:2px 0 0;display:flex;flex-direction:column}
.fact time,.soon{color:var(--hd);font-size:20px;font-weight:700;letter-spacing:-.01em}
.sub{font-size:12.5px;color:var(--mut)}
.bar{height:8px;border-radius:999px;background:var(--line);overflow:hidden;position:relative}
.bar span{position:absolute;inset:0 auto 0 0;width:38%;border-radius:999px;background:linear-gradient(90deg,var(--brand),var(--cyan));animation:slide 2.4s ease-in-out infinite}
@keyframes slide{0%{left:-38%}100%{left:100%}}
.help{font-size:12.5px;color:var(--mut)}
.callout{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border-radius:14px;font-size:13.5px;border:1px solid var(--ok-bd);background:var(--ok-bg);color:var(--ok-tx)}
.callout svg{flex:none;margin-top:1px}
.legal{text-align:center;font-size:12px;color:var(--soft);padding:0 20px 28px;margin:0}
@media (prefers-reduced-motion:reduce){.dot,.bar span{animation:none}.bar span{left:0;width:100%;opacity:.5}}
@media (max-width:640px){.top{padding:18px 20px}.logo{font-size:15px}.logo svg{width:26px;height:26px}main{padding:14px 20px 28px;gap:18px}.hero{gap:18px}.copy{gap:14px;flex-basis:100%}h1{font-size:30px}.lead{font-size:15px}.win{padding:20px;gap:14px;flex-basis:100%}.fact time,.soon{font-size:18px}.actions .btn{width:100%}}
</style></head>
<body>
<header class="top">
<span class="logo"><svg viewBox="0 0 63 63" width="30" height="30" aria-hidden="true"><rect x="0" y="21" width="42" height="42" fill="#00DAF2"/><rect x="21" y="0" width="42" height="42" fill="#0A6CFF"/><rect x="21" y="21" width="21" height="21" fill="#001842"/></svg><span>WorkSuite <span class="wm2">Cloud</span></span></span>
<nav class="tlinks" aria-label="Links">{$links}</nav>
</header>
<main>
<div class="hero">
<section class="copy" role="status" aria-live="polite">
<span class="badge"><span class="dot" aria-hidden="true"></span>Maintenance in progress</span>
<h1>We're making improvements</h1>
<p class="lead">This WorkSuite server is briefly offline while our team carries out maintenance. Your data is safe, and everything will be back shortly.</p>
{$reasonHtml}{$actions}
</section>
<section class="panel win" aria-labelledby="mw">
<span class="lbl" id="mw">Maintenance window</span>
<dl class="facts">{$facts}</dl>
<div class="bar" role="presentation"><span></span></div>
<span class="help">Maintenance in progress · this page refreshes every minute. <span data-tzline>Times in UTC.</span></span>
</section>
</div>
<div class="callout"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/><path d="M9 12l2 2 4-4"/></svg><div><strong>Your work is safe.</strong> Nothing you saved is affected. Reload this page or come back in a few minutes.</div></div>
</main>
<p class="legal">© {$year} Livezen Technologies · WorkSuite · Status 503</p>
<script>(function(){try{var tz=Intl.DateTimeFormat().resolvedOptions().timeZone;document.querySelectorAll("time[data-local]").forEach(function(t){var d=new Date(t.getAttribute("datetime"));if(!isNaN(d))t.textContent=d.toLocaleTimeString([],{hour:"numeric",minute:"2-digit"})});document.querySelectorAll("[data-local-of]").forEach(function(s){var d=new Date(s.getAttribute("data-local-of"));if(!isNaN(d))s.textContent=d.toLocaleDateString([],{weekday:"short",day:"numeric",month:"short"})});document.querySelectorAll("[data-tzword]").forEach(function(s){s.textContent="your time"});var l=document.querySelector("[data-tzline]");if(l&&tz)l.textContent="Times shown in "+tz+"."}catch(e){}})()</script>
</body></html>
HTML;
    }
}
