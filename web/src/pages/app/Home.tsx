import { Link } from 'react-router'
import { useList, useOne } from '../../api/hooks'
import { StatusBadge } from '../../components/Badge'
import { StatTile } from '../../components/Card'
import { Icon } from '../../components/Icon'
import { useAuth } from '../../lib/auth'
import { num, type R } from '../../lib/util'
import { appDomain, appState } from './Apps'
import { usageOf } from './PanelLayout'

/** One resource group on the overview: a coloured tag, a count, a few rows and a link to the full list. */
function Group({ title, tag, color, to, rows, empty, loading }: { title: string; tag: string; color: string; to: string; rows: { key: string; name: string; spec?: string; addr?: string; status?: string; href?: string }[]; empty: React.ReactNode; loading?: boolean }) {
  return (
    <section className="card overflow-hidden" aria-label={title}>
      <div className="flex items-center gap-2.5 px-4 py-3 border-b border-line">
        <span aria-hidden="true" className="grid place-items-center w-[28px] h-[28px] rounded-lg" style={{ background: `color-mix(in srgb, ${color} 14%, transparent)`, color }}><Icon name={tag} size={16} /></span>
        <b className="text-sm">{title}</b><span className="text-xs text-muted">({rows.length})</span>
        <Link to={to} className="ml-auto text-[13px] font-semibold no-underline">View all →</Link>
      </div>
      {loading ? <div className="px-4 py-4 text-sm text-muted">Loading…</div> : rows.length === 0 ? <div className="px-4 py-4 text-sm text-muted">{empty}</div> : (
        <ul className="m-0 p-0 list-none">
          {rows.slice(0, 5).map((r) => (
            <li key={r.key} className="grid gap-3 items-center px-4 py-2.5 border-t border-line first:border-t-0 text-[13px] [grid-template-columns:minmax(0,1.5fr)_minmax(0,1.4fr)_minmax(0,1fr)_auto]">
              {r.href ? <Link to={r.href} className="font-semibold truncate no-underline text-fg hover:text-accent">{r.name}</Link> : <b className="truncate">{r.name}</b>}
              <span className="text-muted truncate">{r.spec ?? ''}</span>
              <span className="font-mono text-xs truncate">{r.addr ?? ''}</span>
              {r.status ? <StatusBadge status={r.status} /> : <span />}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

const GUIDES: [string, string][] = [['Host a Docker app', '/app/host'], ['Create a WordPress site', '/app/new-site'], ['Connect a domain', '/app/domains'], ['Set up business email', '/app/email'], ['Move a site here', '/app/move']]

/** Customer home: everything in the account at a glance (design: User · Project overview). */
export function Home() {
  const { me } = useAuth()
  const apps = useList<R>('/app/apps', undefined, { refetchInterval: 30000 })
  const domains = useList<R>('/app/domains')
  const mail = useOne<R>('/app/mail')
  const incidents = useList<R>('/app/incidents', undefined, { refetchInterval: 60000 })
  const { item: b } = useOne<R>('/app/billing')
  const org = String((me as R | null)?.organization?.name ?? (me as R | null)?.organizations?.[0]?.name ?? me?.user?.name ?? 'My account')
  const initials = org.split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase()
  const mailDomains = ((mail.item?.domains ?? []) as R[])
  const mailboxes = mailDomains.reduce((n, d) => n + ((d.mailboxes as R[] | undefined)?.length ?? num(d.mailbox_count)), 0)
  const open = incidents.rows.filter((i) => !i.resolved_at && i.status !== 'resolved')
  const down = apps.rows.filter((a) => !['running', 'healthy'].includes(appState(a.state ?? a.status)))
  const appsUse = usageOf(b, 'apps'), bw = usageOf(b, 'bandwidth_gb')
  const plan = typeof b?.plan === 'string' ? b.plan : b?.plan?.name
  const stats = [
    { icon: 'layers', k: 'Resources', v: String(apps.rows.length + domains.rows.length + mailboxes), d: `${apps.rows.length} apps · ${domains.rows.length} domains · ${mailboxes} mailboxes` },
    { icon: 'tag', k: 'Plan', v: plan ?? '—', d: appsUse.limit ? `${appsUse.used} of ${appsUse.limit} apps used` : 'no app limit' },
    { icon: 'pulse', k: 'Bandwidth', v: `${Math.round(bw.used)} GB`, d: bw.limit ? `of ${bw.limit} GB included` : 'this month' },
    { icon: 'warn', k: 'Alerts', v: String(open.length + down.length), d: open[0]?.title ? String(open[0].title) : down[0] ? `${down[0].name} is ${appState(down[0].state ?? down[0].status)}` : 'all clear', tone: open.length + down.length ? 'r' : 'g' },
  ]
  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-center gap-3.5">
        <span aria-hidden="true" className="grid place-items-center w-11 h-11 rounded-[10px] bg-sel text-accent font-bold text-lg">{initials}</span>
        <div className="flex-1 min-w-[220px]"><h1 className="m-0 text-2xl font-bold tracking-tight">{org}</h1><p className="m-0 mt-1 text-[13.5px] text-muted">Everything in your account at a glance</p></div>
        <Link to="/app/new-site" className="btn no-underline">New site</Link>
        <Link to="/app/host" className="btn btn-primary no-underline gap-1.5"><Icon name="plus" size={16} />Host an app</Link>
      </div>
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(190px,1fr))]">
        {stats.map((s) => <StatTile key={s.k} label={s.k} value={s.v} delta={s.d} deltaTone="n" valueTone={s.tone as 'g' | 'r' | undefined} icon={<Icon name={s.icon} size={16} />} iconTone={s.tone === 'r' ? 'r' : s.tone === 'g' ? 'g' : 'b'} />)}
      </div>
      <Group title="Apps" tag="box" color="#2563EB" to="/app/apps" loading={apps.isLoading}
        empty={<>Nothing hosted yet. <Link to="/app/host" className="font-semibold">Host your first app</Link>.</>}
        rows={apps.rows.map((a) => ({ key: String(a.id), name: String(a.name), href: `/app/apps/${a.id}`, spec: String(a.image ?? a.source?.repo ?? a.kind ?? ''), addr: appDomain(a), status: appState(a.state ?? a.status) }))} />
      <Group title="Domains" tag="globe" color="#7C3AED" to="/app/domains" loading={domains.isLoading}
        empty={<>No domains yet. <Link to="/app/domains" className="font-semibold">Connect a domain</Link>.</>}
        rows={domains.rows.map((d) => ({ key: String(d.id), name: String(d.name ?? d.domain), spec: d.ssl_status || d.certificate_status ? `HTTPS ${d.ssl_status ?? d.certificate_status}` : undefined, addr: d.app_name ? String(d.app_name) : undefined, status: String(d.status ?? d.verification_status ?? '') || undefined }))} />
      <Group title="Email" tag="inbox" color="#0D9488" to="/app/email" loading={mail.isLoading}
        empty={<>No email yet. <Link to="/app/email" className="font-semibold">Set up business email</Link>.</>}
        rows={mailDomains.map((d) => ({ key: String(d.id), name: String(d.domain ?? d.name), spec: `${(d.mailboxes as R[] | undefined)?.length ?? num(d.mailbox_count)} mailboxes`, status: String(d.status ?? '') || undefined }))} />
      <section className="card p-4 flex flex-wrap items-center gap-3 border-dashed">
        <span className="flex-1 min-w-[220px]"><b>Learn and get started</b><span className="block text-[13px] text-muted">Host an app, connect a domain, set up email or move an existing site.</span></span>
        {GUIDES.map(([l, to]) => <Link key={to} to={to} className="no-underline px-3 py-2 rounded-full bg-sel text-accent text-[12.5px] font-semibold">{l}</Link>)}
      </section>
    </div>
  )
}
