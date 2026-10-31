import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, Empty, Field, PageHeader, StatTile, FormActions } from '../../components/Card'
import { Badge, StatusBadge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { Icon } from '../../components/Icon'
import { Modal } from '../../components/Overlay'
import Select from '../../components/Select'
import { Tabs } from '../../components/Tabs'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'
import { startImpersonation } from '../../lib/impersonation'

type Tab = 'overview' | 'members' | 'apps' | 'domains' | 'billing' | 'activity' | 'settings'
const money = (cents: unknown, cur: unknown) => new Intl.NumberFormat(undefined, { style: 'currency', currency: String(cur || 'USD') }).format(Number(cents ?? 0) / 100)

/** CUS-1: one hosting customer with everything an admin manages, in tabs. */
export default function CustomerProfile() {
  const { id = '' } = useParams()
  const nav = useNavigate()
  const { item: c, isLoading, refetch } = useOne<R>(`/customers/${id}`)
  const plans = useList<R>('/plans')
  const { run, busy } = useAction()
  const [tab, setTab] = useState<Tab>(() => ((['overview', 'members', 'apps', 'domains', 'billing', 'activity', 'settings'] as Tab[]).find((t) => location.hash === `#${t}`) ?? 'overview'))
  useEffect(() => { history.replaceState(null, '', `#${tab}`) }, [tab])
  const [suspend, setSuspend] = useState(false)
  const [asCustomer, setAsCustomer] = useState(false)
  const [why, setWhy] = useState('')
  const [asErr, setAsErr] = useState<string | null>(null)
  const [reason, setReason] = useState('')
  const [f, setF] = useState({ name: '', billing_email: '', notes: '' })
  useEffect(() => { if (c) setF({ name: String(c.name ?? ''), billing_email: String(c.billing_email ?? ''), notes: String(c.notes ?? '') }) }, [c?.id, c?.name, c?.billing_email, c?.notes]) // eslint-disable-line react-hooks/exhaustive-deps
  if (isLoading || !c) return <Card>{isLoading ? 'Loading customer…' : <Empty>Customer not found.</Empty>}</Card>
  const n = (c.counts ?? {}) as R
  const suspended = c.status === 'suspended'
  const act = (path: string, label: string, body?: R) => run({ path: `/customers/${id}/${path}`, body, label, success: `${label} done` }).then(() => refetch()).catch(() => {})
  const initials = String(c.name).split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase()

  return (
    <>
      <Link to="/admin/customers" className="inline-flex items-center gap-1 text-sm font-semibold no-underline mb-2"><Icon name="left" size={15} />Hosting customers</Link>
      <PageHeader title={String(c.name)} subtitle={<span className="inline-flex flex-wrap items-center gap-2"><span className="font-mono text-xs">{c.slug}</span>·<span>customer since {ago(String(c.created_at))}</span></span>}
        actions={<>
          <button type="button" className="btn" onClick={() => { setAsErr(null); setAsCustomer(true) }}><Icon name="eye" size={16} />Sign in as customer…</button>
          <Select className="input !w-auto min-w-40" aria-label="Package" value={String(c.plan?.id ?? '')} onChange={(e) => act('plan', 'Change package', { plan_id: e.target.value })}>
            {!c.plan && <option value="">No package</option>}
            {plans.rows.map((p) => <option key={String(p.id)} value={String(p.id)}>{String(p.name)}</option>)}
          </Select>
          {suspended
            ? <button type="button" className="btn btn-primary" disabled={busy} onClick={() => act('reactivate', 'Reactivate')}>Reactivate</button>
            : <button type="button" className="btn btn-danger" disabled={busy} onClick={() => setSuspend(true)}>Suspend…</button>}
        </>} />
      <div className="card p-4 mb-5 flex flex-wrap items-center gap-4">
        <span aria-hidden="true" className="w-12 h-12 rounded-xl bg-sel text-accent font-bold text-lg grid place-items-center">{initials}</span>
        <div className="flex-1 min-w-0">
          <div className="flex flex-wrap items-center gap-2"><span className="font-semibold">{c.billing_email || 'No billing email'}</span>
            <Badge tone={suspended ? 'r' : 'g'}>{suspended ? 'suspended' : 'active'}</Badge>{c.plan && <Badge tone="b">{String(c.plan.name)}</Badge>}</div>
          {suspended && c.suspension && <div className="text-sm text-[var(--r-fg)] mt-1">Suspended {ago(String(c.suspension.at))} by {String(c.suspension.by)}: {String(c.suspension.reason)}</div>}
        </div>
        <button type="button" className="btn btn-sm" onClick={() => nav('/admin/users')}>Users & Teams</button>
      </div>
      <Tabs label="Customer sections" value={tab} onChange={setTab} tabs={[
        { id: 'overview', label: 'Overview' }, { id: 'members', label: `Members (${n.members ?? 0})` }, { id: 'apps', label: `Apps & projects (${n.apps ?? 0})` },
        { id: 'domains', label: `Domains (${n.domains ?? 0})` }, { id: 'billing', label: 'Billing' }, { id: 'activity', label: 'Activity' }, { id: 'settings', label: 'Settings & notes' },
      ]} />
      <div className="mt-5 flex flex-col gap-4">
        {tab === 'overview' && <>
          <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(170px,1fr))]">
            <StatTile label="Apps" value={String(n.apps ?? 0)} delta={`${n.running ?? 0} running`} icon={<Icon name="rocket" size={16} />} />
            <StatTile label="Projects" value={String(n.projects ?? 0)} icon={<Icon name="layers" size={16} />} />
            <StatTile label="Domains" value={String(n.domains ?? 0)} />
            <StatTile label="Mailboxes" value={String(n.mailboxes ?? 0)} />
            <StatTile label="Members" value={String(n.members ?? 0)} />
          </div>
          <Card title="Package" subtitle={c.subscription ? `Renews ${c.subscription.current_period_end ? new Date(String(c.subscription.current_period_end)).toLocaleDateString() : '—'}` : 'No package yet'}>
            {c.plan ? (
              <dl className="m-0 grid gap-x-6 gap-y-2 text-sm [grid-template-columns:repeat(auto-fill,minmax(160px,1fr))]">
                {Object.entries((c.plan.limits ?? {}) as R).map(([k, v]) => <div key={k}><dt className="text-muted capitalize">{k.replace(/_/g, ' ')}</dt><dd className="m-0 font-semibold">{String(v)}</dd></div>)}
              </dl>
            ) : <Empty>Pick a package in the top right.</Empty>}
          </Card>
        </>}
        {tab === 'members' && <DataTable label="Members" tableId="customer-members" rows={(c.members ?? []) as R[]} rowKey={(r) => String(r.id)}
          columns={[
            { key: 'name', header: 'Name', render: (r) => <div><div className="font-semibold">{r.name}</div><div className="text-xs text-muted">{r.email}</div></div> },
            { key: 'role', header: 'Role', render: (r) => <Badge tone="n">{String(r.role).replace(/_/g, ' ')}</Badge> },
            { key: 'mfa', header: 'Two-factor', value: (r) => (r.mfa_enabled ? 'on' : 'off'), render: (r) => <Badge tone={r.mfa_enabled ? 'g' : 'y'}>{r.mfa_enabled ? 'on' : 'off'}</Badge> },
            { key: 'status', header: 'Status', render: (r) => <StatusBadge status={String(r.status)} /> },
            { key: 'last', header: 'Last sign-in', value: (r) => String(r.last_login_at ?? ''), render: (r) => (r.last_login_at ? ago(String(r.last_login_at)) : 'never') },
          ]} />}
        {tab === 'apps' && <>
          <DataTable label="Apps" tableId="customer-apps" rows={(c.apps ?? []) as R[]} rowKey={(r) => String(r.id)} onRowClick={(r) => nav(`/admin/applications/${r.id}`)}
            actions={(r) => [{ label: `Open ${r.name}`, icon: 'eye', onClick: () => nav(`/admin/applications/${r.id}`) }]}
            columns={[
              { key: 'name', header: 'App', render: (r) => <span className="font-semibold">{r.name}</span> },
              { key: 'image', header: 'Image', render: (r) => <span className="font-mono text-xs text-muted">{r.image ?? '—'}</span> },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={String(r.status)} /> },
              { key: 'deployed', header: 'Last deployed', value: (r) => String(r.last_deployed_at ?? ''), render: (r) => (r.last_deployed_at ? ago(String(r.last_deployed_at)) : 'never') },
            ]} />
          <Card title="Projects">{((c.projects ?? []) as R[]).length ? <ul className="m-0 p-0 list-none flex flex-wrap gap-2">{((c.projects ?? []) as R[]).map((p) => <li key={String(p.id)}><Link to="/admin/projects" className="chip no-underline">{String(p.name)}</Link></li>)}</ul> : <Empty>No projects.</Empty>}</Card>
        </>}
        {tab === 'domains' && <DataTable label="Domains" tableId="customer-domains" rows={(c.domains ?? []) as R[]} rowKey={(r) => String(r.id)}
          columns={[
            { key: 'name', header: 'Domain', render: (r) => <span className="font-semibold">{r.name}</span> },
            { key: 'status', header: 'Status', render: (r) => <StatusBadge status={String(r.status ?? '—')} /> },
            { key: 'created_at', header: 'Added', value: (r) => String(r.created_at ?? ''), render: (r) => ago(String(r.created_at)) },
          ]} />}
        {tab === 'billing' && <DataTable label="Invoices" tableId="customer-invoices" rows={(c.invoices ?? []) as R[]} rowKey={(r) => String(r.id)} empty="No invoices yet."
          actions={(r) => r.pdf_url ? [{ label: `Download ${r.number}`, icon: 'download', onClick: () => window.open(String(r.pdf_url), '_blank', 'noopener') }] : []}
          columns={[
            { key: 'number', header: 'Invoice', render: (r) => <span className="font-mono text-xs">{r.number}</span> },
            { key: 'period', header: 'Period', value: (r) => String(r.period_start), render: (r) => `${r.period_start} – ${r.period_end}` },
            { key: 'amount', header: 'Amount', align: 'right', value: (r) => Number(r.amount_cents), render: (r) => money(r.amount_cents, r.currency) },
            { key: 'status', header: 'Status', render: (r) => <StatusBadge status={String(r.status)} /> },
          ]} />}
        {tab === 'activity' && <DataTable label="Activity" tableId="customer-activity" rows={(c.activity ?? []) as R[]} rowKey={(r) => String(r.id)} pageSize={25}
          columns={[
            { key: 'created_at', header: 'When', value: (r) => String(r.created_at), render: (r) => ago(String(r.created_at)) },
            { key: 'action', header: 'Action', render: (r) => <span className="font-mono text-xs">{r.action}</span> },
            { key: 'actor', header: 'By', value: (r) => String(r.actor ?? 'system') },
            { key: 'ip', header: 'IP', render: (r) => <span className="font-mono text-xs">{r.ip ?? '—'}</span> },
          ]} />}
        {tab === 'settings' && <Card title="Account" subtitle="Only staff see the notes">
          <form className="flex flex-col gap-4 max-w-2xl" onSubmit={(e) => { e.preventDefault(); run({ method: 'PATCH', path: `/customers/${id}`, body: f, label: 'Save customer', success: 'Customer saved' }).then(() => refetch()).catch(() => {}) }}>
            <Field label="Name"><input className="input" required maxLength={120} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
            <Field label="Billing email"><input className="input" type="email" required value={f.billing_email} onChange={(e) => setF({ ...f, billing_email: e.target.value })} /></Field>
            <Field label="Internal notes"><textarea className="input min-h-32 py-2" maxLength={5000} value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} placeholder="Contract terms, contacts, anything the team should know" /></Field>
            <FormActions><button className="btn btn-primary" disabled={busy}>Save</button></FormActions>
          </form>
        </Card>}
      </div>
      <Modal open={asCustomer} onClose={() => setAsCustomer(false)} title={`Sign in as ${c.name}?`}
        footer={<><button type="button" className="btn" onClick={() => setAsCustomer(false)}>Cancel</button>
          <button type="button" className="btn btn-primary" disabled={why.trim().length < 5} onClick={() => startImpersonation(String(c.id), why.trim()).catch((e: Error) => setAsErr(e.message))}>Sign in as customer</button></>}>
        <div className="flex flex-col gap-4">
          <p className="m-0">You’ll see their panel exactly as they do, for up to an hour. Their owner gets an email, it’s recorded in their activity, and you can’t change their password, two-factor or passkeys.</p>
          <Field label="Reason (sent to the customer)" required><input className="input" autoFocus maxLength={300} value={why} onChange={(e) => setWhy(e.target.value)} placeholder="Ticket 4411: help with a failed deploy" /></Field>
          {asErr && <div role="alert" className="text-sm text-danger">{asErr}</div>}
        </div>
      </Modal>
      <Modal open={suspend} onClose={() => setSuspend(false)} title={`Suspend ${c.name}?`}
        footer={<><button type="button" className="btn" onClick={() => setSuspend(false)}>Cancel</button>
          <button type="button" className="btn btn-danger" disabled={busy || reason.trim().length < 3} onClick={() => act('suspend', 'Suspend', { reason }).then(() => { setSuspend(false); setReason('') })}>Suspend customer</button></>}>
        <div className="flex flex-col gap-4">
          <p className="m-0">Their {String(n.apps ?? 0)} apps show the “suspended” page until you reactivate them. Data is kept.</p>
          <Field label="Reason (shown to staff)"><input className="input" maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Unpaid invoice INV-2026-009" /></Field>
        </div>
      </Modal>
    </>
  )
}
