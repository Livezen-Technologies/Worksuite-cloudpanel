import { TwoFactorSetupModal } from '../../components/TwoFactorSetup'
import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'
import { api } from '../../api/client'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, Field, Meter, PageHeader, Toggle, Empty } from '../../components/Card'
import { Badge, StatusBadge } from '../../components/Badge'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { CopyButton } from '../../components/CopyButton'
import { PasskeyManager } from '../../components/Passkeys'
import { useToast } from '../../components/Toasts'
import { ApiTokens } from '../admin/Admin'
import { useAuth } from '../../lib/auth'
import { ago, daysUntil, money, unwrap } from '../../lib/format'
import { num, type R } from '../../lib/util'
import { usageOf } from './PanelLayout'
import { backupDownloadUrl } from '../../lib/download'
import Select from '../../components/Select'

interface DnsRecord { type: string; name: string; value: string; status: 'waiting' | 'verified' | string }
const RECHECK_MS = 120000

export function UserDomains() {
  const [params] = useSearchParams()
  const apps = useList<R>('/app/apps')
  const domains = useList<R>('/app/domains', undefined, { refetchInterval: RECHECK_MS })
  const { run, busy } = useAction()
  const toast = useToast()
  const [f, setF] = useState({ domain: '', app_id: params.get('app') ?? '', redirect_www: true })
  const [err, setErr] = useState<string | null>(null)
  const [active, setActive] = useState<R | null>(null)
  const [check, setCheck] = useState<{ records: DnsRecord[]; https_status?: string; at: number } | null>(null)
  const [checking, setChecking] = useState(false)
  const [del, setDel] = useState<R | null>(null)
  useEffect(() => { if (!f.app_id && apps.rows[0]) setF((x) => ({ ...x, app_id: apps.rows[0].id })) }, [apps.rows, f.app_id])

  const verify = useCallback(async (d: R, quiet = false) => {
    setChecking(true)
    try {
      const r = unwrap<{ records: DnsRecord[]; https_status?: string }>(await api.post(`/app/domains/${d.id}/verify`))
      setCheck({ records: r.records ?? [], https_status: r.https_status, at: Date.now() })
      if (!quiet) toast.info(r.records?.every((x) => x.status === 'verified') ? 'DNS is set up correctly' : 'Some records are not visible yet', 'DNS changes can take up to an hour to spread.')
      domains.refetch()
    } catch (e) { if (!quiet) toast.error(e, 'Could not check DNS') } finally { setChecking(false) }
  }, [toast, domains])

  // UDOM-003: automatic re-check every 2 minutes while records are waiting.
  useEffect(() => {
    if (!active) return
    if (check && check.records.length && check.records.every((r) => r.status === 'verified')) return
    const t = setInterval(() => verify(active, true), RECHECK_MS)
    return () => clearInterval(t)
  }, [active, check, verify])

  const add = async () => {
    setErr(null)
    if (!/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i.test(f.domain.trim())) { setErr('Enter a domain like shop.example.com, without https://'); return }
    try {
      const d = unwrap<R>(await run({ path: '/app/domains', body: { domain: f.domain.trim().toLowerCase(), app_id: f.app_id, redirect_www: f.redirect_www }, label: 'Add domain', success: 'Domain added — now add the DNS records below', silent: false }))
      setActive(d); setF({ ...f, domain: '' })
      if (d.records) setCheck({ records: d.records, https_status: d.https_status, at: Date.now() }); else verify(d, true)
    } catch (e) {
      const ae = e as { code?: string; message?: string }
      setErr(ae.code === 'conflict' ? 'This domain is already connected to another account. If it is yours, contact support.' : ae.message ?? 'Could not add the domain.')
    }
  }
  const allOk = check && check.records.length > 0 && check.records.every((r) => r.status === 'verified')
  return (
    <>
      <PageHeader title="Domains" subtitle="Point your domain at an app — HTTPS certificates are issued and renewed automatically" />
      <Card title="Add a domain">
        <form className="flex flex-wrap gap-3 items-end" onSubmit={(e) => { e.preventDefault(); add() }}>
          <div className="flex-[2_1_220px]"><Field label="Domain" error={err ?? undefined}><input className="input font-mono" value={f.domain} onChange={(e) => setF({ ...f, domain: e.target.value })} placeholder="shop.mybrand.com" /></Field></div>
          <div className="flex-[1_1_160px]"><Field label="App"><Select className="input" value={f.app_id} onChange={(e) => setF({ ...f, app_id: e.target.value })}>{apps.rows.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select></Field></div>
          <label className="flex items-center gap-2 text-sm min-h-[44px]"><input type="checkbox" checked={f.redirect_www} onChange={(e) => setF({ ...f, redirect_www: e.target.checked })} />Redirect www</label>
          <button className="btn btn-primary" disabled={busy || !f.app_id}>Add domain</button>
        </form>
        {active && (
          <div className="mt-5">
            <div className="text-sm font-semibold mb-2">Add these records at your DNS provider for <span className="font-mono">{active.domain ?? active.hostname}</span></div>
            <div className="overflow-x-auto border border-line rounded-lg">
              <table className="w-full text-sm border-collapse min-w-[560px]">
                <thead><tr className="text-left text-xs text-muted bg-head"><th className="p-2.5">Type</th><th className="p-2.5">Name</th><th className="p-2.5">Value</th><th className="p-2.5">Status</th><th className="p-2.5"><span className="sr-only">Copy</span></th></tr></thead>
                <tbody>
                  {(check?.records ?? []).map((r) => (
                    <tr key={r.type + r.name} className="border-t border-line">
                      <td className="p-2.5 font-mono font-semibold">{r.type}</td><td className="p-2.5 font-mono">{r.name}</td><td className="p-2.5 font-mono break-all">{r.value}</td>
                      <td className="p-2.5"><StatusBadge status={r.status} /></td>
                      <td className="p-2.5"><CopyButton value={r.value} label="Copy" className="!min-h-[44px]" /></td>
                    </tr>
                  ))}
                  {!check && <tr><td colSpan={5} className="p-3 text-muted">Looking up the records you need…</td></tr>}
                </tbody>
              </table>
            </div>
            <div className="flex flex-wrap gap-3 items-center mt-3">
              <button type="button" className="btn" disabled={checking} onClick={() => verify(active)}>{checking ? 'Checking…' : 'Check DNS now'}</button>
              <span className="text-xs text-muted" aria-live="polite">{allOk ? `DNS verified. HTTPS: ${check?.https_status ?? 'issuing'}.` : `We check again automatically every 2 minutes${check ? ` · last checked ${ago(new Date(check.at).toISOString())}` : ''}.`}</span>
            </div>
          </div>
        )}
      </Card>
      <Card title="Your domains" pad={false}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm border-collapse min-w-[640px]">
            <thead><tr className="text-left text-xs text-muted bg-head"><th className="py-2.5 px-5">Domain</th><th className="p-2.5">App</th><th className="p-2.5">DNS</th><th className="p-2.5">HTTPS</th><th className="p-2.5">Renews</th><th className="p-2.5"><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {domains.rows.length === 0 && <tr><td colSpan={6} className="p-6 text-center text-muted">No domains yet. Add one above.</td></tr>}
              {domains.rows.map((d) => {
                const renew = daysUntil(d.renewal_at ?? d.certificate?.expires_at ?? d.renews_at)
                const expires = d.certificate?.expires_at ? daysUntil(d.certificate.expires_at) : null
                return (
                  <tr key={d.id} className="border-t border-line">
                    <td className="py-3 px-5 font-mono font-semibold">{d.domain ?? d.hostname}</td>
                    <td className="p-2.5">{d.app_name ?? d.app?.name ?? apps.rows.find((a) => a.id === d.app_id)?.name ?? '—'}</td>
                    <td className="p-2.5"><StatusBadge status={d.dns_status ?? 'pending'} /></td>
                    <td className="p-2.5"><StatusBadge status={d.https_status ?? 'waiting for DNS'} /></td>
                    <td className="p-2.5 text-muted" title={d.certificate?.expires_at ? `Certificate from ${d.certificate.issuer ?? 'Let’s Encrypt'} expires ${new Date(d.certificate.expires_at).toLocaleDateString()}` : undefined}>
                      {d.managed ? 'auto' : renew === null ? (d.https_status === 'issuing' ? 'issuing…' : '—') : `in ${renew} days`}
                      {expires !== null && <span className={`block text-xs ${expires < 14 ? 'text-danger font-semibold' : ''}`}>expires in {expires} days</span>}
                    </td>
                    <td className="p-2.5"><span className="flex gap-2 justify-end"><button type="button" className="btn" onClick={() => { setActive(d); setCheck(null); verify(d, true) }}>DNS records</button><button type="button" className="btn btn-danger" onClick={() => setDel(d)}>Remove</button></span></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </Card>
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} danger title={`Remove ${del?.domain ?? del?.hostname}?`} confirmLabel="Remove domain" busy={busy}
        onConfirm={() => run({ method: 'DELETE', path: `/app/domains/${del!.id}`, label: 'Remove domain', success: 'Domain removed' }).then(() => { if (active?.id === del?.id) setActive(null); setDel(null) }).catch(() => {})}>
        <p className="m-0">Visitors to this domain will no longer reach your app. Your app keeps its free address.</p>
      </ConfirmDialog>
    </>
  )
}

export function UserBackups() {
  const apps = useList<R>('/app/apps')
  const [appId, setAppId] = useState<string>('')
  const backups = useList<R>('/app/backups', { app_id: appId || undefined })
  const { run, busy } = useAction()
  const toast = useToast()
  const [restore, setRestore] = useState<R | null>(null)
  const [pickApp, setPickApp] = useState(false)
  const [newFor, setNewFor] = useState('')
  const appName = (id: string) => apps.rows.find((a) => a.id === id)?.name ?? id
  const [preparing, setPreparing] = useState<string | null>(null)
  const download = async (b: R) => {
    setPreparing(b.id)
    try {
      // First click exports the snapshot (tar.gz) on the server; the link is signed and expires.
      const url = await backupDownloadUrl(`/app/backups/${b.id}/download`, { onExporting: () => toast.success('Preparing your archive… the download starts when it is ready.') })
      window.location.assign(url)
    } catch (e) { toast.error(e, 'Download failed') } finally { setPreparing(null) }
  }
  const backupNow = (id: string) => run({ path: '/app/backups', body: { app_id: id }, label: `Back up ${appName(id)}` }).then(() => setPickApp(false)).catch(() => {})
  return (
    <>
      <PageHeader title="Backups" subtitle="Daily encrypted snapshots of every app’s data, kept for 14 days"
        actions={<button type="button" className="btn btn-primary" disabled={busy} onClick={() => (appId ? backupNow(appId) : setPickApp(true))}>Back up now</button>} />
      <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by app">
        <button type="button" className="chip" aria-pressed={!appId} onClick={() => setAppId('')}>All</button>
        {apps.rows.map((a) => <button key={a.id} type="button" className="chip" aria-pressed={appId === a.id} onClick={() => setAppId(a.id)}>{a.name}</button>)}
      </div>
      <Card pad={false}>
        {backups.isLoading ? <div className="p-5">Loading…</div> : backups.rows.length === 0 ? <Empty>No backups yet. Your first one runs tonight, or press “Back up now”.</Empty> : (
          <ul className="list-none m-0 p-0">
            {backups.rows.map((b) => (
              <li key={b.id} className="flex flex-wrap items-center gap-3 px-5 py-3 border-t border-line first:border-t-0 text-sm">
                <span className="font-semibold min-w-[100px]">{b.app_name ?? appName(b.app_id)}</span>
                <span className="text-muted flex-1 min-w-[140px]">{b.created_at ? new Date(b.created_at).toLocaleString() : '—'}</span>
                <span className="text-muted">{b.size ?? (b.size_mb ? `${b.size_mb} MB` : '—')}</span>
                <StatusBadge status={b.status === 'succeeded' ? 'completed' : b.status ?? (b.verified ? 'verified' : 'unverified')} />
                {b.restore_test && <span title={b.restore_test.message ?? undefined}><Badge tone={b.restore_test.status === 'passed' ? 'g' : b.restore_test.status === 'failed' ? 'r' : 'n'}>{b.restore_test.status === 'passed' ? `restore test passed ${ago(b.restore_test.tested_at)}` : b.restore_test.status === 'failed' ? 'restore test failed' : 'restore test running'}</Badge></span>}
                <button type="button" className="btn" disabled={b.status === 'failed' || preparing === b.id} onClick={() => download(b)}>{preparing === b.id ? 'Preparing…' : 'Download'}</button>
                <button type="button" className="btn" disabled={b.status === 'failed'} onClick={() => setRestore(b)}>Restore</button>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <ConfirmDialog open={!!restore} onClose={() => setRestore(null)} title={`Restore ${restore ? (restore.app_name ?? appName(restore.app_id)) : ''}?`} confirmLabel="Restore now" busy={busy}
        onConfirm={() => run({ path: `/app/backups/${restore!.id}/restore`, label: 'Restore backup' }).then(() => setRestore(null)).catch(() => {})}>
        <p className="m-0">The app restarts with data from {restore?.created_at ? new Date(restore.created_at).toLocaleString() : 'this snapshot'}. A fresh backup is taken first, so you can undo.</p>
      </ConfirmDialog>
      <Modal open={pickApp} onClose={() => setPickApp(false)} title="Back up now"
        footer={<><button type="button" className="btn" onClick={() => setPickApp(false)}>Cancel</button><button type="button" className="btn btn-primary" disabled={!newFor || busy} onClick={() => backupNow(newFor)}>Back up</button></>}>
        <Field label="Which app?"><Select className="input" value={newFor} onChange={(e) => setNewFor(e.target.value)}><option value="">Choose…</option>{apps.rows.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select></Field>
      </Modal>
    </>
  )
}

/** Plans carry `price_cents` + `currency` (docs/API.md appendix). */
function planPrice(p: R): number | undefined {
  if (p.price_cents != null) return num(p.price_cents) / 100
  return p.price != null ? num(p.price) : undefined
}

export function Billing() {
  const { item: b, isLoading } = useOne<R>('/app/billing')
  const plans = useOne<R[] | R>('/app/billing/plans')
  const invoices = useList<R>('/app/billing/invoices')
  const { run, busy } = useAction()
  const [compare, setCompare] = useState(false)
  const [portalBusy, setPortalBusy] = useState(false)
  const toast = useToast()
  // UBIL-003: a fresh Stripe Customer Portal / Checkout session; card data stays on Stripe.
  const openPortal = async () => {
    setPortalBusy(true)
    try {
      const r = unwrap<{ url?: string }>(await api.post('/app/billing/portal'))
      if (r.url) window.location.assign(r.url)
    } catch (e) { toast.error(e, 'Could not open the payment portal') } finally { setPortalBusy(false) }
  }
  if (isLoading) return <Card>Loading…</Card>
  const plan = (typeof b?.plan === 'string' ? { name: b.plan } : b?.plan ?? {}) as R
  const planList = (Array.isArray(plans.item) ? plans.item : (plans.item as R | undefined)?.plans ?? []) as R[]
  const bars = ([['Apps', 'apps', ''], ['RAM', 'ram_mb', 'GB'], ['Storage', 'storage_gb', 'GB'], ['Bandwidth', 'bandwidth_gb', 'GB']] as const).map(([label, key, unit]) => {
    const u = usageOf(b, key)
    const f = (v: number) => (key === 'ram_mb' ? (v / 1024).toFixed(1) : String(Math.round(v * 10) / 10))
    return { label, text: `${f(u.used)} of ${f(u.limit)}${unit ? ' ' + unit : ''}`, pct: u.pct }
  })
  const pm = b?.payment_method as R | string | undefined
  return (
    <>
      <PageHeader title="Billing" subtitle="Your plan, this month’s usage and invoices" />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(280px,1fr))]">
        <Card>
          <div className="text-[13px] text-muted">Current plan</div>
          <div className="text-xl font-bold mt-1.5">{plan.name ?? '—'}{planPrice(plan) != null ? ` · ${money(planPrice(plan), plan.currency)} / month` : ''}</div>
          <div className="text-sm text-muted mt-1">{b?.limits ? `${num(b.limits.apps)} apps · ${num(b.limits.ram_mb) / 1024} GB RAM · ${num(b.limits.storage_gb)} GB storage` : ''}</div>
          <div className="flex gap-2 mt-4 flex-wrap"><button type="button" className="btn btn-primary" onClick={() => setCompare(true)}>Upgrade</button><button type="button" className="btn" onClick={() => setCompare(true)}>Compare plans</button></div>
        </Card>
        <Card>
          <div className="flex justify-between text-[13px] text-muted"><span>This month so far</span>{b?.renews_at && <span>renews {new Date(b.renews_at).toLocaleDateString()}</span>}</div>
          <div className="text-2xl font-bold mt-1.5 mb-3">{b?.month_to_date?.amount_cents != null ? money(num(b.month_to_date.amount_cents) / 100, b.month_to_date.currency) : money(b?.month_to_date?.amount ?? b?.month_to_date)}</div>
          <div className="flex flex-col gap-3">
            {bars.map((x) => (
              <div key={x.label}>
                <div className="flex justify-between text-[13px] mb-1"><span>{x.label}</span><span className={x.pct > 90 ? 'font-semibold text-danger' : 'text-muted'}>{x.text}{x.pct > 90 ? ' · almost full' : ''}</span></div>
                <Meter value={x.pct} label={`${x.label} usage`} color={x.pct > 90 ? 'var(--r-fg)' : 'var(--accent)'} />
              </div>
            ))}
          </div>
        </Card>
        <Card>
          <div className="text-[13px] text-muted">Payment method</div>
          <div className="flex items-center gap-2 mt-2 font-semibold"><Badge tone="n">{typeof pm === 'object' ? (pm?.brand ?? 'CARD') : 'CARD'}</Badge>{typeof pm === 'object' ? (pm?.last4 ? `Card ending ${pm.last4}` : 'No card on file') : (pm ?? 'No card on file')}</div>
          <div className="text-sm text-muted mt-2">Billing email: {b?.billing_email ?? '—'}</div>
          <p className="text-xs text-muted">Card details are handled by our payment provider; we never store them.</p>
          {b?.payment_provider === 'stripe'
            ? <button type="button" className="btn" disabled={portalBusy} onClick={openPortal}>{portalBusy ? 'Opening Stripe…' : (typeof pm === 'object' && pm?.last4 ? 'Manage in Stripe' : 'Add card with Stripe')}</button>
            : (b?.payment_portal_url ?? b?.portal_url ?? (typeof pm === 'object' ? pm?.update_url : undefined)) ? <a className="btn no-underline" href={b?.payment_portal_url ?? b?.portal_url ?? (pm as R).update_url} target="_blank" rel="noreferrer">Update</a> : <button type="button" className="btn" disabled title="Payment provider not connected">Update</button>}
        </Card>
      </div>
      <Card title="Invoices" pad={false}>
        {invoices.rows.length === 0 ? <Empty>No invoices yet.</Empty> : (
          <ul className="list-none m-0 p-0">
            {invoices.rows.map((iv) => (
              <li key={iv.id ?? iv.number} className="flex flex-wrap items-center gap-3 px-5 py-3 border-t border-line first:border-t-0 text-sm">
                <span className="font-mono font-semibold">{iv.number}</span>
                <span className="text-muted flex-1 min-w-[140px]">{iv.period ?? iv.period_label}</span>
                <span className="font-semibold" title={iv.tax_cents ? `incl. ${money(num(iv.tax_cents) / 100, iv.currency)} tax` : undefined}>{iv.amount_cents != null ? money(num(iv.amount_cents) / 100, iv.currency) : money(iv.amount ?? iv.total)}</span>
                <StatusBadge status={iv.status} />
                {iv.pdf_url ? <a href={iv.pdf_url} target="_blank" rel="noreferrer" className="inline-flex items-center min-h-[44px] font-semibold" aria-label={`Download invoice ${iv.number} as PDF`}>PDF</a> : <span className="text-muted text-xs">PDF soon</span>}
              </li>
            ))}
          </ul>
        )}
      </Card>
      <Modal open={compare} onClose={() => setCompare(false)} title="Compare plans" width={820}>
        {planList.length === 0 ? <Empty>Plans are not available right now.</Empty> : (
          <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
            {planList.map((p) => {
              const cur = p.current === true || (p.code ?? p.id ?? p.name) === (plan.code ?? plan.id ?? plan.name)
              return (
                <div key={p.code ?? p.id ?? p.name} className="card p-4 flex flex-col gap-2" style={cur ? { border: '2px solid var(--accent)' } : undefined}>
                  <div className="font-bold">{p.name}</div>
                  <div className="text-lg font-semibold">{money(planPrice(p), p.currency)} / mo</div>
                  <div className="text-xs text-muted">{p.limits ? `${num(p.limits.apps)} apps · ${num(p.limits.ram_mb) / 1024} GB RAM · ${num(p.limits.storage_gb)} GB storage · ${num(p.limits.bandwidth_gb)} GB bandwidth` : p.description}</div>
                  {cur ? <Badge tone="g">current plan</Badge> : <button type="button" className="btn btn-primary mt-auto" disabled={busy} onClick={() => run({ path: '/app/billing/plan', body: { plan: p.code ?? p.id ?? p.name }, label: `Switch to ${p.name}`, success: `You are now on ${p.name}` }).then(() => setCompare(false)).catch(() => {})}>Switch to {p.name}</button>}
                </div>
              )
            })}
          </div>
        )}
      </Modal>
    </>
  )
}

export function Account() {
  const { item: acct, isLoading } = useOne<R>('/app/account')
  const { me, refresh } = useAuth()
  const { run, busy } = useAction()
  const toast = useToast()
  const [p, setP] = useState<R>({})
  const [mfa, setMfa] = useState<{ secret: string; otpauth_url: string } | null>(null)
  const [invite, setInvite] = useState(false)
  const [inv, setInv] = useState({ email: '', role: 'developer' })
  useEffect(() => { if (acct) setP({ name: acct.name ?? acct.user?.name, email: acct.email ?? acct.user?.email, organization: acct.organization?.name ?? acct.organization ?? '', photo_url: acct.photo_url }) }, [acct])
  if (isLoading) return <Card>Loading…</Card>
  const mfaOn = !!(acct?.mfa_enabled ?? acct?.user?.mfa_enabled ?? me?.user?.mfa_enabled)
  const team = (acct?.team ?? acct?.members ?? []) as R[]
  const startMfa = async () => { try { setMfa(unwrap(await api.post('/me/mfa/enable'))) } catch (e) { toast.error(e, 'Could not start two-factor setup') } }
  const confirmMfa = async (otp: string) => {
    try { await api.post('/me/mfa/confirm', { otp }); toast.success('Two-factor authentication is on'); setMfa(null); refresh(); return true } catch { return false }
  }
  return (
    <>
      <PageHeader title="Account" subtitle="Profile, team, sign-in security and API tokens" />
      <div className="flex flex-wrap gap-4 items-start">
        <Card className="flex-[1_1_320px]" title="Profile">
          <form className="flex flex-col gap-4" onSubmit={(e) => { e.preventDefault(); run({ method: 'PATCH', path: '/app/account', body: p, label: 'Save profile', success: 'Profile saved' }).then(() => refresh()).catch(() => {}) }}>
            <div className="flex items-center gap-3">
              {p.photo_url ? <img src={p.photo_url} alt="" className="w-14 h-14 rounded-full object-cover" /> : <span aria-hidden="true" className="w-14 h-14 rounded-full bg-accent text-white font-bold flex items-center justify-center">{String(p.name ?? '?').split(' ').map((x: string) => x[0]).join('').slice(0, 2).toUpperCase()}</span>}
              <label className="btn cursor-pointer">Change photo<input type="file" accept="image/*" className="sr-only" onChange={(e) => { const f = e.target.files?.[0]; if (!f) return; const r = new FileReader(); r.onload = () => setP({ ...p, photo_url: String(r.result) }); r.readAsDataURL(f) }} /></label>
            </div>
            <Field label="Full name"><input className="input" value={p.name ?? ''} onChange={(e) => setP({ ...p, name: e.target.value })} /></Field>
            <Field label="Email"><input className="input" type="email" value={p.email ?? ''} onChange={(e) => setP({ ...p, email: e.target.value })} /></Field>
            <Field label="Organisation"><input className="input" value={p.organization ?? ''} onChange={(e) => setP({ ...p, organization: e.target.value })} /></Field>
            <button className="btn btn-primary self-start" disabled={busy}>Save</button>
          </form>
        </Card>
        <div className="flex-[1_1_320px] flex flex-col gap-4 min-w-0">
          <Card title="Sign-in security">
            <div className="flex items-center gap-3 py-2">
              <div className="flex-1"><div className="font-semibold text-sm">Two-factor authentication</div><div className="text-xs text-muted">{mfaOn ? 'On · authenticator app' : 'Adds a code from your phone when you sign in'}</div></div>
              {mfaOn ? <Badge tone="g">on</Badge> : <button type="button" className="btn" onClick={startMfa}>Turn on</button>}
            </div>
            <div className="flex items-center gap-3 py-2 border-t border-line">
              <div className="flex-1"><div className="font-semibold text-sm">Email me on new sign-ins</div><div className="text-xs text-muted">Alerts for unknown devices</div></div>
              <Toggle checked={!!acct?.sign_in_alerts} label="Email me on new sign-ins" onChange={(v) => run({ method: 'PATCH', path: '/app/account', body: { sign_in_alerts: v }, label: 'Update alerts', success: v ? 'Sign-in alerts on' : 'Sign-in alerts off' }).catch(() => {})} />
            </div>
          </Card>
          <PasskeyManager />
          <Card title="Team" actions={<button type="button" className="btn" onClick={() => setInvite(true)}>Invite</button>}>
            {team.length === 0 ? <div className="text-sm text-muted">Only you so far.</div> : team.map((m, i) => (
              <div key={m.id ?? i} className="flex items-center gap-3 py-2 border-t border-line first:border-t-0">
                <span aria-hidden="true" className="w-8 h-8 rounded-full bg-sel text-accent-fg text-xs font-bold flex items-center justify-center">{String(m.name ?? m.email ?? '?').slice(0, 2).toUpperCase()}</span>
                <span className="flex-1 text-sm font-semibold truncate">{m.name ?? m.email}</span><Badge tone="n">{m.role}</Badge>
              </div>
            ))}
          </Card>
          <ApiTokens path="/app/account/tokens" abilities={['deploy']} />
        </div>
      </div>
      <TwoFactorSetupModal setup={mfa} onClose={() => setMfa(null)} onConfirm={confirmMfa} />
      <Modal open={invite} onClose={() => setInvite(false)} title="Invite a team member"
        footer={<><button type="button" className="btn" onClick={() => setInvite(false)}>Cancel</button><button type="button" className="btn btn-primary" disabled={!/^\S+@\S+\.\S+$/.test(inv.email) || busy} onClick={() => run({ path: '/app/account/team', body: inv, label: 'Invite', success: `Invite sent to ${inv.email}` }).then(() => { setInvite(false); setInv({ email: '', role: 'developer' }) }).catch(() => {})}>Send invite</button></>}>
        <div className="flex flex-col gap-4">
          <Field label="Email"><input className="input" type="email" value={inv.email} onChange={(e) => setInv({ ...inv, email: e.target.value })} /></Field>
          <fieldset className="border-0 p-0 m-0"><legend className="text-[13px] font-semibold mb-2">Role</legend>
            {([['owner', 'Owner', 'Everything, including billing and deleting apps'], ['developer', 'Developer', 'Deploy and manage apps and domains'], ['billing', 'Billing', 'Plans, invoices and payment only']] as const).map(([v, l, s]) => (
              <label key={v} className="flex gap-3 items-start py-2 min-h-[44px] cursor-pointer"><input type="radio" name="role" checked={inv.role === v} onChange={() => setInv({ ...inv, role: v })} className="mt-1" /><span><span className="block font-semibold text-sm">{l}</span><span className="block text-xs text-muted">{s}</span></span></label>
            ))}
          </fieldset>
        </div>
      </Modal>
    </>
  )
}
