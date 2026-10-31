<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

/**
 * SVC-6: captcha on sign-in, sign-up and password reset requests. The first enabled of
 * Turnstile, reCAPTCHA v3 and hCaptcha (with a site key and a secret) is used.
 */
class CaptchaService
{
    public const PROVIDERS = ['turnstile', 'recaptcha', 'hcaptcha'];

    private const VERIFY = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
        'hcaptcha' => 'https://api.hcaptcha.com/siteverify',
    ];

    public function __construct(private PlatformServices $services) {}

    /** ['provider' => ..., 'site_key' => ...] for the login page, or null when captcha is off. */
    public function active(): ?array
    {
        foreach (self::PROVIDERS as $p) {
            $s = $this->services->get($p);
            if ($s && ! empty($s['config']['site_key']) && ! empty($s['credentials']['secret_key'])) {
                return ['provider' => $p, 'site_key' => (string) $s['config']['site_key'], 'secret' => (string) $s['credentials']['secret_key'], 'min_score' => (float) ($s['config']['min_score'] ?? 0.5)];
            }
        }

        return null;
    }

    public function publicConfig(): ?array
    {
        $a = $this->active();

        return $a ? ['provider' => $a['provider'], 'site_key' => $a['site_key']] : null;
    }

    /** Throws a 422 on the "captcha" field unless the token checks out (or captcha is off). */
    public function check(?string $token, string $action = 'login'): void
    {
        $a = $this->active();
        if (! $a) {
            return;
        }
        if (! $token) {
            throw ApiException::validation(['captcha' => ['Complete the bot check.']]);
        }
        try {
            $r = Http::asForm()->timeout(8)->post(self::VERIFY[$a['provider']], ['secret' => $a['secret'], 'response' => $token, 'remoteip' => request()->ip()])->json();
        } catch (\Throwable) {
            // The provider is down: let people in rather than lock everyone out; the rate limit still applies.
            report(new \RuntimeException('Captcha verification unavailable'));

            return;
        }
        $ok = (bool) ($r['success'] ?? false);
        if ($ok && $a['provider'] === 'recaptcha') {
            $ok = (float) ($r['score'] ?? 0) >= $a['min_score'] && (! isset($r['action']) || $r['action'] === $action);
        }
        if (! $ok) {
            throw ApiException::validation(['captcha' => ['The bot check failed. Try again.']]);
        }
    }
}
