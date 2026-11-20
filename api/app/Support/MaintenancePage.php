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

        $reasonHtml = $reason !== '' ? '<p class="reason"><span class="rl">Note from the team</span>'.$e($reason).'</p>' : '';
        $facts = '';
        if ($since) {
            $facts .= '<div class="fact"><dt>Started</dt><dd><time datetime="'.$e($since->toIso8601String()).'" title="'.$e($since->utc()->format('j M Y, H:i').' UTC').'">'.$e($since->diffForHumans()).'</time></dd></div>';
        }
        if ($until) {
            $facts .= '<div class="fact"><dt>Expected back</dt><dd><time datetime="'.$e($until->toIso8601String()).'" data-local>'.$e($until->utc()->format('j M, H:i').' UTC').'</time></dd></div>';
        }
        $facts = $facts !== '' ? '<dl class="facts">'.$facts.'</dl>' : '';
        $admin = config('platform.maintenance_page.admin_link') && preg_match('#^https://#i', (string) config('app.url'))
            ? '<a class="admin" href="'.$e(rtrim((string) config('app.url'), '/')).'/" rel="nofollow">Admin? Sign in to CloudPanel</a>'
            : '';

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><meta http-equiv="refresh" content="60">
<title>We're making improvements · WorkSuite</title>
<style>
:root{color-scheme:light dark;--bg:#f7f7fb;--bg2:#eef0ff;--card:#ffffffcc;--line:rgba(17,24,39,.08);--ink:#0b1020;--muted:#5b6475;--soft:#8a93a6;--accent:#4f46e5;--accent2:#06b6d4;--ok:#f59e0b;--sky1:#e0e7ff;--sky2:#cffafe;--hill1:#c7d2fe;--hill2:#a5b4fc}
@media (prefers-color-scheme:dark){:root{--bg:#070a14;--bg2:#0e1330;--card:#0f1426cc;--line:rgba(255,255,255,.08);--ink:#eef1f8;--muted:#a3abbd;--soft:#7b8399;--accent:#818cf8;--accent2:#22d3ee;--ok:#fbbf24;--sky1:#1e1b4b;--sky2:#083344;--hill1:#312e81;--hill2:#3730a3}}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:radial-gradient(1200px 600px at 10% -10%,var(--bg2),transparent 60%),radial-gradient(900px 500px at 110% 110%,var(--bg2),transparent 60%),var(--bg);color:var(--ink);font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased}
header{display:flex;align-items:center;justify-content:space-between;padding:22px 28px}
.brand{display:inline-flex;align-items:center;gap:10px;font-weight:650;font-size:18px;letter-spacing:-.015em;color:var(--ink);text-decoration:none}
.logo{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:grid;place-items:center;box-shadow:0 6px 18px -6px var(--accent)}
main{flex:1;display:flex;align-items:center;justify-content:center;padding:8px 16px 40px}
.card{width:100%;max-width:620px;background:var(--card);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border:1px solid var(--line);border-radius:28px;padding:36px 36px 30px;box-shadow:0 30px 80px -40px rgba(15,23,42,.45);text-align:center}
.art{display:block;width:100%;max-width:340px;height:auto;margin:0 auto 18px}
.eyebrow{display:inline-flex;align-items:center;gap:8px;font:600 12px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
h1{margin:14px 0 10px;font-size:clamp(30px,5.5vw,44px);line-height:1.08;font-weight:650;letter-spacing:-.035em;text-wrap:balance}
.lead{margin:0 auto;max-width:470px;color:var(--muted);font-size:clamp(16px,2.2vw,18px)}
.reason{margin:22px auto 0;max-width:480px;padding:14px 18px;border-radius:16px;border:1px solid var(--line);background:color-mix(in srgb,var(--accent) 7%,transparent);text-align:left;white-space:pre-line;overflow-wrap:anywhere}
.rl{display:block;font:600 11px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.12em;text-transform:uppercase;color:var(--soft);margin-bottom:2px}
.facts{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin:20px 0 0;padding:0}
.fact{min-width:150px;padding:10px 16px;border-radius:14px;border:1px solid var(--line)}
.fact dt{font-size:12px;color:var(--soft);text-transform:uppercase;letter-spacing:.08em}
.fact dd{margin:2px 0 0;font-weight:600}
.status{display:inline-flex;align-items:center;gap:10px;margin-top:24px;padding:8px 14px;border-radius:999px;border:1px solid var(--line);font-size:14px;color:var(--muted)}
.dot{width:9px;height:9px;border-radius:50%;background:var(--ok);box-shadow:0 0 0 0 var(--ok);animation:pulse 2s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--ok) 60%,transparent)}70%{box-shadow:0 0 0 10px transparent}100%{box-shadow:0 0 0 0 transparent}}
.gear{transform-origin:center;transform-box:fill-box;animation:spin 14s linear infinite}.gear.r{animation-direction:reverse;animation-duration:10s}
@keyframes spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.dot,.gear{animation:none}}
footer{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px 16px;padding:16px 28px 22px;font-size:13px;color:var(--soft)}
.admin{color:var(--soft);text-decoration:none;border-bottom:1px dotted currentColor}.admin:hover{color:var(--ink)}
@media (max-width:560px){header{padding:16px}.card{padding:26px 18px 22px;border-radius:22px}footer{padding:14px 16px 18px}}
</style></head>
<body>
<header><span class="brand"><span class="logo" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6l4 12 5-9 5 9 4-12"/></svg></span>WorkSuite</span></header>
<main><section class="card" role="status" aria-live="polite">
<svg class="art" viewBox="0 0 340 170" role="img" aria-label="Gears turning over quiet hills">
<defs><linearGradient id="sky" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="var(--sky1)"/><stop offset="1" stop-color="var(--sky2)"/></linearGradient>
<linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="var(--accent)"/><stop offset="1" stop-color="var(--accent2)"/></linearGradient></defs>
<rect x="0" y="0" width="340" height="170" rx="22" fill="url(#sky)"/>
<circle cx="276" cy="40" r="14" fill="var(--ok)" opacity=".55"/>
<path d="M0 132c50-26 92-30 140-14s96 16 140-6 60-8 60-8v66H0z" fill="var(--hill1)"/>
<path d="M0 150c60-18 120-18 180-4s110 10 160-6v30H0z" fill="var(--hill2)" opacity=".8"/>
<g class="gear" fill="url(#g)"><path d="M150 52l6-1 2-8h10l2 8 6 1 6-5 7 7-5 6 1 6 8 2v10l-8 2-1 6 5 6-7 7-6-5-6 1-2 8h-10l-2-8-6-1-6 5-7-7 5-6-1-6-8-2V68l8-2 1-6-5-6 7-7z"/><circle cx="163" cy="73" r="10" fill="var(--sky1)"/></g>
<g class="gear r" fill="url(#g)" opacity=".85"><path d="M206 92l4-1 1-5h7l1 5 4 1 4-3 5 5-3 4 1 4 5 1v7l-5 1-1 4 3 4-5 5-4-3-4 1-1 5h-7l-1-5-4-1-4 3-5-5 3-4-1-4-5-1v-7l5-1 1-4-3-4 5-5z"/><circle cx="214" cy="106" r="6" fill="var(--sky1)"/></g>
</svg>
<span class="eyebrow">Scheduled maintenance</span>
<h1>We're making improvements</h1>
<p class="lead">This WorkSuite server is briefly offline while our team carries out maintenance. Your data is safe, and everything will be back shortly.</p>
{$reasonHtml}{$facts}
<div class="status"><span class="dot" aria-hidden="true"></span><span>Maintenance in progress · this page refreshes every minute</span></div>
</section></main>
<footer><span>WorkSuite · Status 503</span>{$admin}</footer>
<script>document.querySelectorAll("time[data-local]").forEach(function(t){var d=new Date(t.getAttribute("datetime"));if(!isNaN(d))t.textContent=d.toLocaleString([], {day:"numeric",month:"short",hour:"2-digit",minute:"2-digit"})})</script>
</body></html>
HTML;
    }
}
