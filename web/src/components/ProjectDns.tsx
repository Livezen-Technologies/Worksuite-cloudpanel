import { useState } from 'react'
import { api } from '../api/client'
import { useAction, useOne } from '../api/hooks'
import { Card, Empty, Field, StatTile } from './Card'
import { Badge, StatusBadge } from './Badge'
import { ConfirmDialog } from './Overlay'
import { CopyButton } from './CopyButton'
import { StepWizard } from './StepWizard'
import { Tabs } from './Tabs'
import { WizardChecks, WizardChoice } from './WizardShell'
import { useToast } from './Toasts'
import { ago, unwrap } from '../lib/format'
import type { R } from '../lib/util'

/** PDNS: Project → DNS & Domains. Every sub-page reads the one overview endpoint. */
export type DnsPage = 'domains' | 'add' | 'records' | 'validation' | 'provider' | 'ssl' | 'routing' | 'history' | 'permissions'
const PAGES: { id: DnsPage; label: string }[] = [
  { id: 'domains', label: 'Connected domains' }, { id: 'add', label: 'Add domain' }, { id: 'records', label: 'DNS records' },
  { id: 'validation', label: 'DNS validation' }, { id: 'provider', label: 'DNS provider' }, { id: 'ssl', label: 'SSL certificates' },
  { id: 'routing', label: 'Domain routing' }, { id: 'history', label: 'Change history' }, { id: 'permissions', label: 'Permissions' },
]

interface Rec { type: string; name: string; value: string; purpose?: string; status?: string; actual?: string[] }
interface Check { key: string; label: string; status: 'pass' | 'warn' | 'fail' | 'skip'; expected?: string | null; actual?: string | null; message?: string | null; resolvers?: { resolver: string; values: string[] | null; status: string }[] }

const PURPOSE: Record<string, string> = { routing: 'Routes traffic to the ingress', ownership: 'Proves you own the domain', www: 'www redirect', certificate: 'Certificate challenge' }

export function RecordsTable({ records, showActual = false }: { records: Rec[]; showActual?: boolean }) {
  return (
    <div className="overflow-x-auto border border-line rounded-lg">
      <table className="tbl min-w-[620px]">
        <thead><tr className="text-left text-xs text-muted bg-head"><th className="p-2.5">Type</th><th className="p-2.5">Name</th><th className="p-2.5">{showActual ? 'Expected' : 'Value'}</th>{showActual && <th className="p-2.5">Found</th>}<th className="p-2.5">Purpose</th><th className="p-2.5"><span className="sr-only">Copy</span></th></tr></thead>
        <tbody>{records.map((r) => (
          <tr key={r.type + r.name + r.value} className="border-t border-line">
            <td className="p-2.5 font-mono font-semibold">{r.type}</td>
            <td className="p-2.5 font-mono break-all">{r.name}</td>
            <td className="p-2.5 font-mono break-all">{r.value}</td>
            {showActual && <td className="p-2.5 font-mono break-all">{r.actual?.length ? r.actual.join(', ') : <span className="text-muted">nothing</span>} {r.status && <StatusBadge status={r.status === 'pass' ? 'verified' : 'missing'} />}</td>}
            <td className="p-2.5 text-xs text-muted">{PURPOSE[r.purpose ?? ''] ?? ''}</td>
            <td className="p-2.5"><CopyButton value={r.value} label="Copy" /></td>
          </tr>
        ))}</tbody>
      </table>
    </div>
  )
}

export function ProjectDns({ projectId, initial = 'domains' }: { projectId: string; initial?: DnsPage }) {
  const base = `/app/projects/${projectId}/dns`
  const q = useOne<R>(base, undefined, { refetchInterval: 120000 })
  const [page, setPage] = useState<DnsPage>(initial)
  const [selected, setSelected] = useState<string | null>(null)
  const d = q.item ?? {}
  const domains = (d.domains as R[]) ?? []
  const perms = new Set((d.permissions as string[]) ?? [])
  const cur = domains.find((x) => x.id === selected) ?? domains[0]
  const open = (id: string, p: DnsPage) => { setSelected(id); setPage(p) }
  const pages = PAGES.filter((p) => (p.id !== 'add' || perms.has('add')))

  return (
    <div className="flex flex-col gap-3">
      <Tabs label="DNS & Domains" tabs={pages} value={page} onChange={setPage} />
      {page !== 'domains' && page !== 'add' && page !== 'history' && page !== 'permissions' && domains.length > 0 && (
        <label className="text-sm flex items-center gap-2">Domain
          <select className="input max-w-xs" aria-label="Domain" value={String(cur?.id ?? '')} onChange={(e) => setSelected(e.target.value)}>
            {domains.map((x) => <option key={String(x.id)} value={String(x.id)}>{String(x.name)}</option>)}
          </select>
        </label>
      )}
      {page === 'domains' && <Connected data={d} perms={perms} onManage={(id) => open(id, 'validation')} onAdd={() => setPage('add')} base={base} />}
      {page === 'add' && <AddDomain data={d} base={base} onDone={(id) => { q.refetch(); open(id, 'records') }} />}
      {page !== 'domains' && page !== 'add' && page !== 'history' && page !== 'permissions' && !cur && <Card><Empty icon="globe">No domains in this project yet.</Empty></Card>}
      {page === 'records' && cur && <Records domain={cur} base={base} canPublish={perms.has('records')} />}
      {page === 'validation' && cur && <Validation key={String(cur.id)} domain={cur} base={base} />}
      {page === 'provider' && cur && <Provider domain={cur} base={base} canPublish={perms.has('records')} canConnect={perms.has('provider')} />}
      {page === 'ssl' && cur && <Ssl domain={cur} base={base} canManage={perms.has('ssl')} />}
      {page === 'routing' && <Routing data={d} base={base} canChange={perms.has('records')} />}
      {page === 'history' && <History base={base} />}
      {page === 'permissions' && <Permissions base={base} isOwner={!!d.is_owner} labels={(d.permission_labels as Record<string, string>) ?? {}} />}
    </div>
  )
}

function Connected({ data, perms, onManage, onAdd, base }: { data: R; perms: Set<string>; onManage: (id: string) => void; onAdd: () => void; base: string }) {
  const { run, busy } = useAction()
  const [del, setDel] = useState<R | null>(null)
  const c = (data.counts ?? {}) as R
  const domains = (data.domains as R[]) ?? []
  const activePrimary = del && del.is_primary && del.dns_status === 'verified'
  return (
    <>
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(160px,1fr))]">
        <StatTile label="Domains" value={Number(c.total ?? 0)} />
        <StatTile label="Verified" value={Number(c.verified ?? 0)} valueTone="g" />
        <StatTile label="Pending" value={Number(c.pending ?? 0)} valueTone={Number(c.pending ?? 0) ? 'y' : 'n'} />
        <StatTile label="SSL active" value={Number(c.ssl_active ?? 0)} />
      </div>
      <Card title="Connected domains" pad={false} actions={perms.has('add') ? <button type="button" className="btn btn-primary btn-sm" onClick={onAdd}>+ Add domain</button> : undefined}>
        <div className="overflow-x-auto">
          <table className="tbl min-w-[720px]">
            <thead><tr className="text-left text-xs text-muted bg-head"><th className="py-2.5 px-5">Domain</th><th className="p-2.5">App</th><th className="p-2.5">Verification</th><th className="p-2.5">SSL</th><th className="p-2.5">Status</th><th className="p-2.5"><span className="sr-only">Actions</span></th></tr></thead>
            <tbody>
              {domains.length === 0 && <tr><td colSpan={6} className="!p-0"><Empty icon="globe">No domains yet. Add a custom domain or a free Livezen Cloud subdomain.</Empty></td></tr>}
              {domains.map((x) => (
                <tr key={String(x.id)} className="border-t border-line">
                  <td className="py-3 px-5 font-mono font-semibold">{String(x.name)} {x.is_primary ? <Badge tone="b">Primary</Badge> : null}{x.platform_subdomain ? <Badge tone="n">Livezen</Badge> : null}</td>
                  <td className="p-2.5">{String(x.app_name ?? '—')}</td>
                  <td className="p-2.5"><StatusBadge status={x.ownership_verified ? String(x.dns_status) : 'awaiting ownership'} /></td>
                  <td className="p-2.5"><StatusBadge status={String(x.https_status ?? 'pending')} /></td>
                  <td className="p-2.5 text-xs text-muted">{String(x.status ?? '')}</td>
                  <td className="p-2.5 whitespace-nowrap">
                    <button type="button" className="btn btn-sm" onClick={() => onManage(String(x.id))}>Manage</button>
                    {perms.has('primary') && !x.is_primary && x.dns_status === 'verified' && <button type="button" className="btn btn-sm ml-2" disabled={busy} onClick={() => run({ method: 'PATCH', path: `${base}/domains/${x.id}`, body: { is_primary: true }, label: 'Make primary', success: `${x.name} is now primary` }).catch(() => {})}>Make primary</button>}
                    {perms.has('remove') && <button type="button" className="btn btn-sm ml-2" onClick={() => setDel(x)}>Remove</button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} danger busy={busy} title={`Remove ${del?.name ?? ''}?`} confirmLabel="Remove domain"
        confirmText={activePrimary ? String(del?.name) : undefined}
        onConfirm={() => del && run({ method: 'DELETE', path: `${base}/domains/${del.id}${activePrimary ? `?confirm=${encodeURIComponent(String(del.name))}` : ''}`, label: 'Remove domain', success: 'Domain removed' }).then(() => setDel(null)).catch(() => {})}>
        <p className="m-0 text-sm">{activePrimary ? 'This is the project’s active primary domain. Visitors will stop reaching your app on it. Type the domain name to confirm.' : 'The domain stops routing to your app. Your DNS records at the provider are not changed.'}</p>
      </ConfirmDialog>
    </>
  )
}

function AddDomain({ data, base, onDone }: { data: R; base: string; onDone: (id: string) => void }) {
  const apps = (data.apps as R[]) ?? []
  const { run, busy } = useAction()
  const [step, setStep] = useState(0)
  const [kind, setKind] = useState<'custom' | 'platform'>('custom')
  const [name, setName] = useState('')
  const [appId, setAppId] = useState(String(apps[0]?.id ?? ''))
  const [method, setMethod] = useState<'manual' | 'provider'>('manual')
  const [www, setWww] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [created, setCreated] = useState<R | null>(null)
  const suffix = `.${String(data.platform_domain ?? 'livezen.app')}`
  if (apps.length === 0) return <Card><Empty icon="globe">Add an app to this project first; a domain routes to an app.</Empty></Card>
  if (created) {
    const published = (created.published as R[]) ?? []
    return (
      <Card title={`Next: set up DNS for ${String(created.name)}`}>
        {created.platform_subdomain ? <p className="m-0 text-sm">Livezen Cloud subdomains need no DNS changes. HTTPS is issued automatically.</p> : <>
          <p className="mt-0 text-sm">{method === 'provider' ? 'We wrote the records your DNS provider was missing. Records that already existed were left alone.' : 'Create exactly these records at your DNS provider. We check again every 2 minutes and tell you when they are visible.'}</p>
          {published.length > 0 && <ul className="text-sm">{published.map((p) => <li key={String(p.type) + String(p.name)}><span className="font-mono">{String(p.type)} {String(p.name)}</span>: <StatusBadge status={String(p.status)} /> {p.message ? <span className="text-muted">{String(p.message)}</span> : null}</li>)}</ul>}
          <RecordsTable records={(created.records as Rec[]) ?? []} />
        </>}
        <div className="mt-3 flex gap-2"><button type="button" className="btn btn-primary" onClick={() => onDone(String(created.id))}>Done</button></div>
      </Card>
    )
  }
  const submit = () => {
    setErr(null)
    run<R>({ path: `${base}/domains`, body: { kind, name: name.trim().toLowerCase(), app_id: appId, method, www_redirect: www }, label: 'Add domain', success: 'Domain added', silent: true })
      .then((r) => setCreated(unwrap<R>(r))).catch((e: { message?: string }) => setErr(e?.message ?? 'Could not add the domain.'))
  }
  return (
    <Card title="Add a domain">
      <StepWizard step={step} onStep={setStep} busy={busy} error={err} finishLabel="Add domain" onFinish={submit} steps={[
        { label: 'Domain', validate: () => (kind === 'custom' ? /^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i.test(name.trim()) : /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i.test(name.trim())) ? null : kind === 'custom' ? 'Enter a domain like shop.example.com, without https://' : 'Use letters, digits and hyphens, like my-shop.',
          content: <div className="flex flex-col gap-3">
            <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
              <WizardChoice on={kind === 'custom'} onClick={() => setKind('custom')} title="Your own domain" text="A domain you registered, such as shop.example.com." icon="globe" />
              <WizardChoice on={kind === 'platform'} onClick={() => setKind('platform')} title="Livezen Cloud subdomain" text={`Free, ready at once: name${suffix}`} icon="layers" />
            </div>
            <Field label={kind === 'custom' ? 'Domain' : 'Subdomain'}>
              <span className="flex items-center gap-1"><input className="input font-mono flex-1" aria-label={kind === 'custom' ? 'Domain' : 'Subdomain'} value={name} onChange={(e) => setName(e.target.value)} placeholder={kind === 'custom' ? 'shop.example.com' : 'my-shop'} />{kind === 'platform' && <span className="font-mono text-sm text-muted">{suffix}</span>}</span>
            </Field>
          </div> },
        { label: 'Application', validate: () => appId ? null : 'Choose an app.',
          content: <Field label="Route to app"><select className="input" aria-label="Route to app" value={appId} onChange={(e) => setAppId(e.target.value)}>{apps.map((a) => <option key={String(a.id)} value={String(a.id)}>{String(a.name)}</option>)}</select></Field> },
        ...(kind === 'custom' ? [{ label: 'DNS method', content: <div className="flex flex-col gap-3">
          <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
            <WizardChoice on={method === 'manual'} onClick={() => setMethod('manual')} title="I’ll add the records" text="We show the exact records to create at your DNS provider." icon="pencil" />
            <WizardChoice on={method === 'provider'} onClick={() => setMethod('provider')} title="Automatic (DNS provider)" text="For a zone connected under DNS (Cloudflare). Only the missing records are created; existing ones are never changed." icon="refresh" />
          </div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={www} onChange={(e) => setWww(e.target.checked)} />Also redirect www.{name || 'example.com'}</label>
        </div> }] : []),
        { label: 'Review', content: <div className="text-sm flex flex-col gap-1">
          <div><span className="text-muted">Domain:</span> <span className="font-mono">{kind === 'platform' ? name + suffix : name}</span></div>
          <div><span className="text-muted">App:</span> {String(apps.find((a) => a.id === appId)?.name ?? '')}</div>
          {kind === 'custom' && <div><span className="text-muted">Method:</span> {method === 'manual' ? 'Manual DNS records' : 'Automatic through the DNS provider'}</div>}
          <p className="text-xs text-muted mb-0">Your domain points at the platform ingress ({((data.ingress as R)?.hostname as string) || ((data.ingress as R)?.ips as string[] ?? []).join(', ')}), not at a server, so moving the app never changes your DNS.{kind === 'custom' ? ' A TXT record proves you own the domain before it goes live.' : ''}</p>
        </div> },
      ]} />
    </Card>
  )
}

function Records({ domain, base, canPublish }: { domain: R; base: string; canPublish: boolean }) {
  const { run, busy } = useAction()
  const [out, setOut] = useState<R[] | null>(null)
  return (
    <Card title={`DNS records for ${String(domain.name)}${domain.platform_subdomain ? '' : domain.subdomain ? ' (subdomain: CNAME)' : ' (apex domain: A/AAAA)'}`} subtitle={domain.platform_subdomain ? 'Managed by Livezen Cloud.' : 'Create these at your DNS provider. Leave your other records (MX, SPF, DKIM, DMARC) as they are.'}
      actions={canPublish && domain.dns_zone ? <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run<R>({ path: `${base}/domains/${domain.id}/publish`, label: 'Write records to provider' }).then((r) => setOut((unwrap<R>(r).records as R[]) ?? [])).catch(() => {})}>Write missing records to {String((domain.dns_zone as R).provider ?? 'provider')}</button> : undefined}>
      <RecordsTable records={(domain.records as Rec[]) ?? []} />
      {out && <ul className="text-sm mb-0">{out.map((p) => <li key={String(p.type) + String(p.name)}><span className="font-mono">{String(p.type)} {String(p.name)}</span>: <StatusBadge status={String(p.status)} /> {p.message ? <span className="text-muted">{String(p.message)}</span> : null}</li>)}</ul>}
    </Card>
  )
}

function Validation({ domain, base }: { domain: R; base: string }) {
  const toast = useToast()
  const last = domain.last_validation as R | null
  const [res, setRes] = useState<R | null>(last ? { checks: last.checks, checked_at: last.at, ok: last.ok } : null)
  const [running, setRunning] = useState(false)
  const go = async () => {
    setRunning(true)
    try { setRes(unwrap<R>(await api.post(`${base}/domains/${domain.id}/validate`))) } catch (e) { toast.error(e, 'Validation failed') } finally { setRunning(false) }
  }
  const checks = ((res?.checks as Check[]) ?? [])
  return (
    <Card title={`DNS validation for ${String(domain.name)}`} subtitle={res?.checked_at ? `Last run ${ago(String(res.checked_at))}` : 'Runs every check live from our servers.'}
      actions={<button type="button" className="btn btn-primary btn-sm" disabled={running} onClick={go}>{running ? 'Validating…' : 'Validate DNS'}</button>}>
      {checks.length === 0 ? <p className="m-0 text-sm text-muted">Not validated yet.</p> : <>
        <WizardChecks label="Validation results" checks={checks.map((c) => ({ key: c.key, label: c.label, status: c.status === 'skip' ? 'pending' : c.status, message: [c.message, c.expected ? `Expected: ${c.expected}.` : '', c.actual ? `Found: ${c.actual}.` : ''].filter(Boolean).join(' ') }))} />
        {checks.find((c) => c.key === 'propagation')?.resolvers && (
          <div className="mt-3 text-xs text-muted">Resolvers: {checks.find((c) => c.key === 'propagation')!.resolvers!.map((r) => `${r.resolver} ${r.status === 'pass' ? '✓' : r.status === 'unknown' ? '?' : '✗'}`).join(' · ')}</div>
        )}
        {(res?.records as Rec[])?.length ? <div className="mt-4"><div className="text-sm font-semibold mb-2">Expected vs actual</div><RecordsTable records={res!.records as Rec[]} showActual /></div> : null}
      </>}
    </Card>
  )
}

function Provider({ domain, base, canPublish, canConnect }: { domain: R; base: string; canPublish: boolean; canConnect: boolean }) {
  const providers = useOne<R[]>(`${base}/providers`)
  const { run, busy } = useAction()
  const [token, setToken] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [pick, setPick] = useState<string | null>(null)
  const [zones, setZones] = useState<R[] | null>(null)
  const [drop, setDrop] = useState<R | null>(null)
  const list = (providers.item as R[] | undefined) ?? []
  const zone = domain.dns_zone as R | null
  const connect = () => {
    setErr(null)
    run<R>({ path: `${base}/providers`, body: { type: 'cloudflare', token: token.trim() }, label: 'Connect Cloudflare', success: 'Cloudflare connected', silent: true })
      .then((r) => { const a = unwrap<R>(r); setToken(''); setPick(String(a.id)); setZones((a.zones as R[]) ?? []); providers.refetch() })
      .catch((e: { message?: string; fields?: Record<string, string[]> }) => setErr(e?.fields?.token?.[0] ?? e?.message ?? 'Cloudflare refused the token.'))
  }
  const loadZones = (id: string) => { setPick(id); setZones(null); api.get<{ data: R[] }>(`${base}/providers/${id}/zones`).then((r) => setZones(r.data)).catch(() => setZones([])) }
  return (
    <div className="flex flex-col gap-3">
      <Card title="DNS provider" subtitle="Connect your own Cloudflare account. The token is checked with Cloudflare, stored encrypted for this organisation only, and never shown again.">
        {list.length === 0 && <p className="mt-0 text-sm text-muted">No DNS provider connected. Records for {String(domain.name)} are added by hand until you connect one.</p>}
        {list.length > 0 && <ul className="list-none m-0 p-0 mb-3">{list.map((a) => (
          <li key={String(a.id)} className="flex flex-wrap items-center gap-2 py-2 border-t border-line first:border-t-0 text-sm">
            <span className="font-semibold">{String(a.name)}</span><Badge tone="n">{String(a.type)}</Badge>{a.source === 'customer' ? null : <Badge tone="b">Connected by Livezen</Badge>}
            <span className="text-muted">{((a.zones as R[]) ?? []).map((z) => String(z.name)).join(', ') || 'no zones in use'}</span>
            <span className="ml-auto flex gap-2">
              {canConnect && a.type === 'cloudflare' && <button type="button" className="btn btn-sm" onClick={() => loadZones(String(a.id))}>Choose zone</button>}
              {canConnect && !!a.can_disconnect && <button type="button" className="btn btn-sm" onClick={() => setDrop(a)}>Disconnect</button>}
            </span>
          </li>
        ))}</ul>}
        {canConnect ? (
          <div className="flex flex-col gap-2">
            <Field label="Cloudflare API token" hint="Create it in Cloudflare under My Profile → API Tokens with Zone:Read and DNS:Edit, limited to the zone that holds your domain.">
              <input className="input font-mono" type="password" autoComplete="off" aria-label="Cloudflare API token" value={token} onChange={(e) => setToken(e.target.value)} />
            </Field>
            {err && <p role="alert" className="m-0 text-sm text-danger">{err}</p>}
            <div><button type="button" className="btn btn-primary btn-sm" disabled={busy || token.trim().length < 20} onClick={connect}>Connect Cloudflare</button></div>
          </div>
        ) : <p className="m-0 text-sm text-muted">Ask the project owner for the “Connect a DNS provider” permission to connect an account.</p>}
        {pick && (
          <div className="mt-3">
            <div className="text-sm font-semibold mb-2">Zone for {String(domain.name)}</div>
            {zones === null ? <p className="m-0 text-sm text-muted">Loading zones…</p> : zones.length === 0 ? <p className="m-0 text-sm text-muted">This token can see no zones.</p> : (
              <ul className="list-none m-0 p-0" aria-label="Cloudflare zones">{zones.map((z) => {
                const fits = String(domain.name) === z.name || String(domain.name).endsWith(`.${String(z.name)}`)
                return (
                  <li key={String(z.id)} className="flex items-center gap-2 py-1.5 text-sm">
                    <span className="font-mono">{String(z.name)}</span> <StatusBadge status={String(z.status)} />
                    <button type="button" className="btn btn-sm ml-auto" disabled={!fits || busy} title={fits ? undefined : `${String(domain.name)} is not in this zone`}
                      onClick={() => run({ path: `${base}/domains/${domain.id}/zone`, body: { provider_id: pick, zone_id: z.id }, label: `Use ${String(z.name)}`, success: 'Zone connected' }).then(() => { setPick(null); providers.refetch() }).catch(() => {})}>Use for this domain</button>
                  </li>
                )
              })}</ul>
            )}
          </div>
        )}
      </Card>
      {zone && <ZoneRecords domain={domain} base={base} />}
      {zone && <Records domain={domain} base={base} canPublish={canPublish} />}
      <ConfirmDialog open={!!drop} onClose={() => setDrop(null)} danger busy={busy} title={`Disconnect ${String(drop?.name ?? '')}?`} confirmLabel="Disconnect"
        onConfirm={() => drop && run({ method: 'DELETE', path: `${base}/providers/${drop.id}`, label: 'Disconnect provider', success: 'Disconnected' }).then(() => { setDrop(null); setPick(null); providers.refetch() }).catch(() => {})}>
        <p className="m-0 text-sm">The panel forgets the token and stops writing records. Nothing is deleted at Cloudflare: your domains keep working.</p>
      </ConfirmDialog>
    </div>
  )
}

function ZoneRecords({ domain, base }: { domain: R; base: string }) {
  const q = useOne<R>(`${base}/domains/${domain.id}/zone-records`)
  const rows = ((q.item?.records as R[]) ?? [])
  return (
    <Card title={`Records in ${String((q.item?.zone as R | null)?.name ?? (domain.dns_zone as R)?.name ?? 'the zone')}`} subtitle="Records marked Managed are the ones the panel created for this domain. The rest are yours and are never changed or deleted." pad={false}>
      {rows.length === 0 ? <Empty icon="globe">No records for {String(domain.name)} in the zone yet.</Empty> : (
        <div className="overflow-x-auto"><table className="tbl min-w-[620px]" aria-label="Zone records">
          <thead><tr className="text-left text-xs text-muted bg-head"><th className="py-2.5 px-5">Type</th><th className="p-2.5">Name</th><th className="p-2.5">Value</th><th className="p-2.5">Owner</th></tr></thead>
          <tbody>{rows.map((r, i) => (
            <tr key={i} className="border-t border-line">
              <td className="py-2.5 px-5 font-mono font-semibold">{String(r.type)}</td><td className="p-2.5 font-mono break-all">{String(r.name)}</td><td className="p-2.5 font-mono break-all">{String(r.value)}</td>
              <td className="p-2.5">{r.managed ? <Badge tone="b">Managed</Badge> : <Badge tone="n">Yours — kept</Badge>}</td>
            </tr>
          ))}</tbody>
        </table></div>
      )}
    </Card>
  )
}

function Ssl({ domain, base, canManage }: { domain: R; base: string; canManage: boolean }) {
  const { run, busy } = useAction()
  const c = domain.certificate as R | null
  return (
    <Card title={`SSL certificate for ${String(domain.name)}`} actions={canManage && domain.dns_status === 'verified' ? <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${base}/domains/${domain.id}/certificate`, label: 'Request certificate' }).catch(() => {})}>Request / renew now</button> : undefined}>
      {!c ? <p className="m-0 text-sm text-muted">{domain.dns_status === 'verified' ? 'Issuing…' : 'Issued automatically once DNS and ownership are verified.'}</p> : (
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm m-0">
          <dt className="text-muted">Status</dt><dd className="m-0"><StatusBadge status={String(c.status)} /></dd>
          <dt className="text-muted">Issuer</dt><dd className="m-0">{String(c.issuer ?? '—')}</dd>
          <dt className="text-muted">Expires</dt><dd className="m-0">{c.expires_at ? `${new Date(String(c.expires_at)).toLocaleDateString()} (${String(c.days_left)} days)` : '—'}</dd>
          {domain.last_error ? <><dt className="text-muted">Last error</dt><dd className="m-0 text-danger">{String(domain.last_error)}</dd></> : null}
        </dl>
      )}
    </Card>
  )
}

function Routing({ data, base, canChange }: { data: R; base: string; canChange: boolean }) {
  const { run, busy } = useAction()
  const apps = (data.apps as R[]) ?? []
  const ingress = (data.ingress ?? {}) as R
  return (
    <Card title="Domain routing" subtitle={`All domains point at the cluster ingress ${String(ingress.hostname ?? ((ingress.ips as string[]) ?? []).join(', '))}; the ingress routes each name to its app wherever it runs.`} pad={false}>
      <ul className="list-none m-0 p-0">{((data.domains as R[]) ?? []).map((x) => (
        <li key={String(x.id)} className="flex flex-wrap items-center gap-3 py-2.5 px-5 border-t border-line first:border-t-0 text-sm">
          <span className="font-mono flex-1 min-w-[200px]">{String(x.name)}</span>
          <span aria-hidden="true">→</span>
          {canChange ? <select className="input max-w-[220px]" aria-label={`App for ${x.name}`} disabled={busy} value={String(x.app_id ?? '')} onChange={(e) => run({ method: 'PATCH', path: `${base}/domains/${x.id}`, body: { app_id: e.target.value }, label: 'Change routing', success: 'Routing changed' }).catch(() => {})}>
            {apps.map((a) => <option key={String(a.id)} value={String(a.id)}>{String(a.name)}</option>)}
          </select> : <span>{String(x.app_name ?? '—')}</span>}
        </li>
      ))}</ul>
    </Card>
  )
}

function History({ base }: { base: string }) {
  const h = useOne<R[]>(`${base}/history`)
  const rows = (h.item as R[] | undefined) ?? []
  const LABEL: Record<string, string> = { 'domain.added': 'Added', 'domain.removed': 'Removed', 'domain.primary_changed': 'Primary changed', 'domain.ownership_verified': 'Ownership verified', 'domain.dns_published': 'Records written to provider', 'domain.routing_changed': 'Routing changed', 'domain.certificate_requested': 'Certificate requested', 'domain.permissions_changed': 'Permissions changed', 'domain.permissions_revoked': 'All permissions revoked', 'domain.zone_attached': 'Zone connected', 'domain.state_changed': 'Status changed', 'dns.provider_connected': 'DNS provider connected', 'dns.provider_disconnected': 'DNS provider disconnected' }
  return (
    <Card title="DNS change history" pad={false}>
      {rows.length === 0 ? <Empty icon="clock">No changes yet.</Empty> : (
        <ul className="list-none m-0 p-0">{rows.map((r) => (
          <li key={String(r.id)} className="flex flex-wrap gap-3 py-2.5 px-5 border-t border-line first:border-t-0 text-sm">
            <span className="font-semibold">{LABEL[String(r.action)] ?? String(r.action)}</span>
            <span className="font-mono">{String(r.domain ?? '')}</span>
            <span className="text-muted ml-auto">{String(r.actor ?? '')} · {r.at ? ago(String(r.at)) : ''}</span>
          </li>
        ))}</ul>
      )}
    </Card>
  )
}

function Permissions({ base, isOwner, labels }: { base: string; isOwner: boolean; labels: Record<string, string> }) {
  const g = useOne<R[]>(`${base}/permissions`)
  const { run, busy } = useAction()
  const [revoke, setRevoke] = useState<R | null>(null)
  const rows = (g.item as R[] | undefined) ?? []
  const keys = Object.keys(labels)
  return (
    <Card title="Who can manage this project’s DNS" subtitle={isOwner ? 'Everyone in the project can view its domains. Grant the rest per person.' : 'Only the project owner can change these.'} pad={false}>
      <div className="overflow-x-auto"><table className="tbl min-w-[640px]">
        <thead><tr className="text-left text-xs text-muted bg-head"><th className="py-2.5 px-5">Member</th>{keys.map((k) => <th key={k} className="p-2.5">{labels[k]}</th>)}{isOwner && <th className="p-2.5"><span className="sr-only">Actions</span></th>}</tr></thead>
        <tbody>{rows.map((m) => {
          const has = new Set((m.permissions as string[]) ?? [])
          return (
            <tr key={String(m.user_id)} className="border-t border-line">
              <td className="py-2.5 px-5">{String(m.name ?? m.email)} {m.owner ? <Badge tone="b">Owner</Badge> : null}</td>
              {keys.map((k) => <td key={k} className="p-2.5"><input type="checkbox" aria-label={`${labels[k]} for ${m.name ?? m.email}`} checked={has.has(k)} disabled={!isOwner || !!m.owner || k === 'view' || busy}
                onChange={(e) => { const next = new Set(has); if (e.target.checked) next.add(k); else next.delete(k); run({ method: 'PATCH', path: `${base}/permissions/${m.user_id}`, body: { permissions: [...next].filter((p) => p !== 'view') }, label: 'Save permissions', success: 'Permissions saved' }).catch(() => {}) }} /></td>)}
              {isOwner && <td className="p-2.5">{!m.owner && has.size > 1 && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => setRevoke(m)}>Revoke all</button>}</td>}
            </tr>
          )
        })}</tbody>
      </table></div>
      <ConfirmDialog open={!!revoke} onClose={() => setRevoke(null)} danger busy={busy} title={`Revoke all DNS permissions of ${String(revoke?.name ?? revoke?.email ?? '')}?`} confirmLabel="Revoke all"
        onConfirm={() => revoke && run({ method: 'DELETE', path: `${base}/permissions/${revoke.user_id}`, label: 'Revoke DNS permissions', success: 'Permissions revoked' }).then(() => { setRevoke(null); g.refetch() }).catch(() => {})}>
        <p className="m-0 text-sm">They keep seeing the project’s domains, but can no longer add, change, publish or remove anything. This is recorded in the change history.</p>
      </ConfirmDialog>
    </Card>
  )
}
