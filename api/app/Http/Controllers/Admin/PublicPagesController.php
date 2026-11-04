<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\PublicPagesService;
use Illuminate\Http\Request;

/** Hosting → Public pages (SRS PUB-1..4). */
class PublicPagesController extends Controller
{
    public function __construct(private PublicPagesService $pages) {}

    public function show(Request $request)
    {
        $s = $this->pages->settings();
        $panel = rtrim((string) config('platform.panel_url'), '/');

        return $this->data([
            'installed' => $this->pages->installed(), 'maintenance' => (bool) $s['maintenance'], 'retry_after' => $s['retry_after'], 'grace_days' => $s['grace_days'],
            'bypass_query' => $s['bypass_token'] ? 'ws_bypass='.$s['bypass_token'] : null,
            'pages' => collect(PublicPagesService::PAGES)->map(fn ($d, $key) => ['key' => $key, 'status' => $d['status']] + array_replace(array_intersect_key($d, array_flip(['title', 'message'])), $s['pages'][$key] ?? []))->values(),
            'sites' => Domain::query()->where('platform_subdomain', false)->orderBy('name')->get()->map(fn (Domain $d) => [
                'id' => $d->id, 'name' => $d->name, 'maintenance' => (bool) ($d->options['pages']['maintenance'] ?? false),
                'showing' => $this->pages->takeover($d), 'custom' => array_keys($d->options['pages']['text'] ?? []),
            ]),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'maintenance' => 'sometimes|boolean', 'retry_after' => 'sometimes|integer|min:30|max:86400', 'grace_days' => 'sometimes|integer|min:0|max:90',
            'pages' => 'sometimes|array', 'pages.*.title' => 'nullable|string|max:120', 'pages.*.message' => 'nullable|string|max:1000',
            'pages.*.link_label' => 'nullable|string|max:60', 'pages.*.link_url' => 'nullable|url:https,http|max:500',
        ]);
        $request->attributes->set('audit_skip', true);
        $this->pages->saveSettings($data, $request->user()->id);

        return $this->show($request);
    }

    public function site(Request $request, string $id)
    {
        $data = $request->validate([
            'maintenance' => 'sometimes|boolean', 'pages' => 'sometimes|array', 'pages.*.title' => 'nullable|string|max:120',
            'pages.*.message' => 'nullable|string|max:1000', 'pages.*.link_label' => 'nullable|string|max:60', 'pages.*.link_url' => 'nullable|url:https,http|max:500',
        ]);
        $request->attributes->set('audit_skip', true);
        $this->pages->saveSite(Domain::findOrFail($id), $data, $request->user()->id);

        return $this->show($request);
    }

    public function install(Request $request)
    {
        $request->attributes->set('audit_skip', true);

        return $this->data(['servers' => $this->pages->install($request->user()->id)], 202);
    }

    /** A page as visitors see it. */
    public function preview(string $key)
    {
        abort_unless(isset(PublicPagesService::PAGES[$key]), 404);

        $html = $this->pages->render($key);
        if ($key === 'captcha') {
            // The preview has no bouncer: show where the widget goes instead of the template fields.
            $html = str_replace(['<script src="{{ .FrontendJS }}" async defer></script>', '<div class="{{ .FrontendKey }}" data-sitekey="{{ .SiteKey }}" data-callback="captchaCallback"></div>'],
                ['', '<div style="border:1px dashed #9ca3af;border-radius:6px;padding:18px;text-align:center;color:#6b7280">The Turnstile, reCAPTCHA or hCaptcha check appears here</div>'], $html);
        }

        return $this->data(['html' => $html]);
    }
}
