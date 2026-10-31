import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react'

export type CaptchaConfig = { provider: 'turnstile' | 'recaptcha' | 'hcaptcha'; site_key: string } | null
export interface CaptchaHandle { token: () => Promise<string | null>; reset: () => void }

declare global { interface Window { turnstile?: any; hcaptcha?: any; grecaptcha?: any } } // eslint-disable-line @typescript-eslint/no-explicit-any

const SRC: Record<string, (k: string) => string> = {
  turnstile: () => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
  hcaptcha: () => 'https://js.hcaptcha.com/1/api.js?render=explicit',
  recaptcha: (k) => `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(k)}`,
}

function load(src: string): Promise<void> {
  const existing = document.querySelector(`script[src="${src}"]`)
  if (existing) return (existing as HTMLScriptElement).dataset.ready ? Promise.resolve() : new Promise((r) => existing.addEventListener('load', () => r()))
  return new Promise((resolve, reject) => {
    const s = Object.assign(document.createElement('script'), { src, async: true, defer: true })
    s.onload = () => { s.dataset.ready = '1'; resolve() }
    s.onerror = () => reject(new Error('captcha script'))
    document.head.appendChild(s)
  })
}

/** SVC-6: the bot check the panel is set up with (Turnstile, hCaptcha or invisible reCAPTCHA v3). */
export const Captcha = forwardRef<CaptchaHandle, { config: CaptchaConfig; action?: string }>(function Captcha({ config, action = 'login' }, ref) {
  const box = useRef<HTMLDivElement>(null)
  const widget = useRef<unknown>(null)
  const latest = useRef<string | null>(null)
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    if (!config) return
    let gone = false
    load(SRC[config.provider](config.site_key)).then(() => {
      if (gone || !box.current) return
      const opts = { sitekey: config.site_key, callback: (t: string) => { latest.current = t }, 'expired-callback': () => { latest.current = null }, action }
      if (config.provider === 'turnstile') widget.current = window.turnstile?.render(box.current, opts)
      if (config.provider === 'hcaptcha') widget.current = window.hcaptcha?.render(box.current, opts)
    }).catch(() => setFailed(true))
    return () => { gone = true }
  }, [config?.provider, config?.site_key]) // eslint-disable-line react-hooks/exhaustive-deps
  useImperativeHandle(ref, () => ({
    token: async () => {
      if (!config) return null
      if (config.provider === 'recaptcha') {
        const g = window.grecaptcha
        if (!g) return null
        await new Promise<void>((r) => g.ready(r))
        return g.execute(config.site_key, { action })
      }
      return latest.current
    },
    reset: () => {
      latest.current = null
      if (config?.provider === 'turnstile') window.turnstile?.reset(widget.current)
      if (config?.provider === 'hcaptcha') window.hcaptcha?.reset(widget.current)
    },
  }), [config, action])
  if (!config) return null
  if (failed) return <p className="m-0 text-xs text-danger">The bot check could not load. Check your connection or ad blocker, then reload.</p>
  return config.provider === 'recaptcha'
    ? <p className="m-0 text-[11px] text-muted">Protected by reCAPTCHA (Google <a href="https://policies.google.com/privacy" target="_blank" rel="noreferrer">Privacy</a> · <a href="https://policies.google.com/terms" target="_blank" rel="noreferrer">Terms</a>).</p>
    : <div ref={box} className="min-h-[65px]" />
})
