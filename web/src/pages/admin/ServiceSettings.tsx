import { useEffect, useMemo, useState } from 'react'
import { useAction, useOne } from '../../api/hooks'
import { api, isApiError } from '../../api/client'
import { Field, Toggle } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { Icon } from '../../components/Icon'
import { ConfirmDialog, Drawer } from '../../components/Overlay'
import Select from '../../components/Select'
import { useToast } from '../../components/Toasts'
import { ago } from '../../lib/format'

type FieldDef = { key: string; label: string; kind: 'text' | 'secret' | 'url' | 'select'; options: string[] | null }
type Svc = {
  key: string; label: string; category: string; description: string; test: boolean; fields: FieldDef[]
  configured: boolean; enabled: boolean; values: Record<string, unknown>; last_test: { ok: boolean; message: string; at: string } | null; updated_at: string | null
}
type Page = { categories: Record<string, string>; services: Svc[] }

/** Brand marks (Simple Icons, CC0) and colours; services without one show initials. */
const LOGO: Record<string, [string, string]> = {
  stripe: ['stripe', '635BFF'], paypal: ['paypal', '003087'], cloudflare: ['cloudflare', 'F38020'], turnstile: ['cloudflare', 'F38020'],
  route53: ['amazonroute53', '8C4FFF'], google_login: ['google', '4285F4'], recaptcha: ['google', '4285F4'], github_login: ['github', '181717'],
  github: ['github', '181717'], gitlab: ['gitlab', 'FC6D26'], gitea: ['gitea', '609926'], oidc: ['openid', 'F78C40'], sendgrid: ['sendgrid', '51A9E3'],
  mailgun: ['mailgun', 'F06B66'], ses: ['amazonwebservices', '232F3E'], aws: ['amazonwebservices', '232F3E'], s3: ['amazons3', '569A31'],
  hetzner: ['hetzner', 'D50C2D'], digitalocean: ['digitalocean', '0080FF'], vultr: ['vultr', '007BFC'], slack: ['slack', '4A154B'],
  telegram: ['telegram', '26A5E4'], twilio: ['twilio', 'F22F46'], sentry: ['sentry', '362D59'],
}

function Logo({ s, size = 40 }: { s: Svc; size?: number }) {
  const l = LOGO[s.key]
  return (
    <span aria-hidden="true" className="grid place-items-center flex-none rounded-xl bg-white ring-1 ring-line" style={{ width: size, height: size }}>
      {l ? <span style={{ width: size * 0.55, height: size * 0.55, background: `#${l[1]}`, WebkitMask: `url(/service-icons/${l[0]}.svg) center/contain no-repeat`, mask: `url(/service-icons/${l[0]}.svg) center/contain no-repeat` }} />
        : <span className="text-sm font-bold text-[#334155]">{s.label.split(/\s+/).map((w) => w[0]).join('').slice(0, 2)}</span>}
    </span>
  )
}

const state = (s: Svc) => !s.configured ? { tone: 'n' as const, text: 'Not set up' } : !s.enabled ? { tone: 'y' as const, text: 'Off' } : s.last_test && !s.last_test.ok ? { tone: 'r' as const, text: 'Check failed' } : { tone: 'g' as const, text: 'Connected' }

/** SVC-1..4: set up payments, DNS, sign-in apps, captcha, email, Git, cloud, storage and notification services. */
export function ServiceSettings() {
  const page = useOne<Page>('/services')
  const d = page.item
  const [cat, setCat] = useState('all')
  const [q, setQ] = useState('')
  const [open, setOpen] = useState<string | null>(null)
  const list = useMemo(() => (d?.services ?? []).filter((s) => (cat === 'all' || (cat === 'connected' ? s.configured : s.category === cat)) && (!q || `${s.label} ${s.description}`.toLowerCase().includes(q.toLowerCase()))), [d, cat, q])
  const grouped = useMemo(() => Object.entries(d?.categories ?? {}).map(([k, label]) => ({ k, label, items: list.filter((s) => s.category === k) })).filter((g) => g.items.length), [d, list])
  const current = d?.services.find((s) => s.key === open) ?? null
  const connected = (d?.services ?? []).filter((s) => s.configured).length
  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-center gap-3">
        <label className="flex items-center gap-2 flex-1 min-w-[220px] h-10 px-3 rounded-lg border border-line bg-card text-muted focus-within:border-accent">
          <Icon name="search" size={16} />
          <input aria-label="Search services" placeholder="Search services" value={q} onChange={(e) => setQ(e.target.value)} className="flex-1 min-w-0 border-0 outline-0 bg-transparent text-fg text-sm" />
        </label>
        <span className="text-sm text-muted">{connected} of {d?.services.length ?? 0} set up</span>
      </div>
      <div className="flex flex-wrap gap-2" role="group" aria-label="Categories">
        {[['all', 'All'], ['connected', 'Set up'], ...Object.entries(d?.categories ?? {})].map(([k, l]) => (
          <button key={k} type="button" className="chip" aria-pressed={cat === k} onClick={() => setCat(k)}>{l}</button>
        ))}
      </div>
      {page.isLoading && <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">{Array.from({ length: 6 }, (_, i) => <div key={i} className="h-28 rounded-xl bg-head animate-pulse" />)}</div>}
      {grouped.map((g) => (
        <section key={g.k} aria-label={g.label}>
          <h3 className="m-0 mb-2.5 text-sm font-semibold text-muted">{g.label}</h3>
          <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
            {g.items.map((s) => {
              const st = state(s)
              return (
                <button key={s.key} type="button" onClick={() => setOpen(s.key)}
                  className="card text-left p-4 flex flex-col gap-3 cursor-pointer transition-colors hover:border-accent/60">
                  <span className="flex items-center gap-3">
                    <Logo s={s} />
                    <span className="min-w-0 flex-1"><span className="block font-semibold truncate">{s.label}</span><span className="block text-xs text-muted truncate">{s.description}</span></span>
                  </span>
                  <span className="flex items-center justify-between gap-2 text-xs">
                    <Badge tone={st.tone}>{st.text}</Badge>
                    <span className="text-accent font-semibold">{s.configured ? 'Manage →' : 'Set up →'}</span>
                  </span>
                </button>
              )
            })}
          </div>
        </section>
      ))}
      {!page.isLoading && grouped.length === 0 && <p className="m-0 text-sm text-muted">No service matches.</p>}
      {current && <ServiceDrawer s={current} onClose={() => setOpen(null)} onSaved={() => page.refetch()} />}
    </div>
  )
}

function ServiceDrawer({ s, onClose, onSaved }: { s: Svc; onClose: () => void; onSaved: () => void }) {
  const { run, busy } = useAction()
  const toast = useToast()
  const [vals, setVals] = useState<Record<string, string>>({})
  const [enabled, setEnabled] = useState(s.configured ? s.enabled : true)
  const [testing, setTesting] = useState(false)
  const [remove, setRemove] = useState(false)
  useEffect(() => {
    setVals(Object.fromEntries(s.fields.map((f) => [f.key, f.kind === 'secret' ? '' : String(s.values[f.key] ?? (f.options?.[0] ?? ''))])))
    setEnabled(s.configured ? s.enabled : true)
  }, [s.key]) // eslint-disable-line react-hooks/exhaustive-deps
  const save = () => run({ method: 'PATCH', path: `/services/${s.key}`, body: { enabled, values: vals }, label: `Save ${s.label}`, success: `${s.label} saved` }).then(onSaved).catch(() => {})
  const test = async () => {
    setTesting(true)
    try {
      await api.patch(`/services/${s.key}`, { enabled, values: vals })
      const r = (await api.post<{ data: { ok: boolean; message: string } }>(`/services/${s.key}/test`)).data
      if (r.ok) toast.success(r.message)
      else toast.error(new Error(r.message), r.message)
      onSaved()
    } catch (e) { toast.error(e, isApiError(e) ? e.message : 'The test could not run') } finally { setTesting(false) }
  }
  return (
    <Drawer open onClose={onClose} title={s.label} width={460}
      footer={<>
        {s.configured && <button type="button" className="btn btn-ghost mr-auto text-[var(--r-fg)]" onClick={() => setRemove(true)}>Remove</button>}
        {s.test && <button type="button" className="btn gap-1.5" disabled={testing || busy} onClick={test}><Icon name="refresh" size={15} />{testing ? 'Testing…' : 'Test connection'}</button>}
        <button type="button" className="btn btn-primary" disabled={busy} onClick={save}>Save</button>
      </>}>
      <div className="flex flex-col gap-5">
        <div className="flex items-center gap-3"><Logo s={s} size={48} /><div className="min-w-0"><div className="font-semibold">{s.label}</div><div className="text-sm text-muted">{s.description}</div></div></div>
        {s.last_test && (
          <div role="status" className={`rounded-lg px-3 py-2.5 text-[13px] ${s.last_test.ok ? 'bg-g-bg text-g-fg' : 'bg-r-bg text-r-fg'}`}>
            {s.last_test.message} <span className="opacity-75">· {ago(s.last_test.at)}</span>
          </div>
        )}
        <label className="flex items-center justify-between gap-3 text-sm font-semibold"><span>Use this service</span><Toggle checked={enabled} onChange={setEnabled} label={`Use ${s.label}`} /></label>
        {s.fields.map((f) => {
          const sec = s.values[f.key] as { set?: boolean; hint?: string } | undefined
          return (
            <Field key={f.key} label={f.label} hint={f.kind === 'secret' ? (sec?.set ? `Saved (${sec.hint}). Leave blank to keep it.` : 'Stored encrypted; never shown again.') : undefined}>
              {f.kind === 'select'
                ? <Select className="input" value={vals[f.key] ?? ''} onChange={(e) => setVals({ ...vals, [f.key]: e.target.value })}>{(f.options ?? []).map((o) => <option key={o} value={o}>{o}</option>)}</Select>
                : <input className={`input ${f.kind !== 'text' ? 'font-mono' : ''}`} type={f.kind === 'secret' ? 'password' : f.kind === 'url' ? 'url' : 'text'} autoComplete="off"
                    placeholder={f.kind === 'secret' && sec?.set ? '••••••••' : undefined} value={vals[f.key] ?? ''} onChange={(e) => setVals({ ...vals, [f.key]: e.target.value })} />}
            </Field>
          )
        })}
        {!s.test && <p className="m-0 text-xs text-muted">This service has no automatic connection test; check it where it is used.</p>}
        {s.updated_at && <p className="m-0 text-xs text-muted">Last changed {ago(s.updated_at)}.</p>}
      </div>
      <ConfirmDialog open={remove} onClose={() => setRemove(false)} danger title={`Remove ${s.label}?`} confirmLabel="Remove"
        onConfirm={() => run({ method: 'DELETE', path: `/services/${s.key}`, label: `Remove ${s.label}`, success: `${s.label} removed` }).then(() => { setRemove(false); onSaved(); onClose() }).catch(() => {})}>
        <p className="m-0">Its saved keys are deleted. Anything that uses {s.label} stops working until you set it up again.</p>
      </ConfirmDialog>
    </Drawer>
  )
}
