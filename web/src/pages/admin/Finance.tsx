import { useMemo, useState } from 'react'
import { useList, useOne, useAction } from '../../api/hooks'
import { Card, Field, PageHeader, StatTile, Empty, Toggle } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { Icon } from '../../components/Icon'
import { DataTable } from '../../components/DataTable'
import { LineChart } from '../../components/Charts'
import { Drawer } from '../../components/Overlay'
import Select from '../../components/Select'

/**
 * Finance (FIN-1..10). Everything in this file reads from /finance/*, which
 * answers across every tenant -- these are the platform administrator's
 * views. The customer's own billing page is a different screen on a scoped
 * endpoint, and the two must not be confused.
 */

export type Money = { amount_cents: number; currency: string }

/**
 * Money is formatted from integer cents with the browser's own currency
 * rules, so LKR, EUR and USD each get the separators and symbol their
 * readers expect instead of a hard-coded prefix.
 */
export function money(cents: number | null | undefined, currency = 'EUR'): string {
  const n = (cents ?? 0) / 100
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 2 }).format(n)
  } catch {
    return `${currency} ${n.toLocaleString(undefined, { minimumFractionDigits: 2 })}`
  }
}

export const when = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/** One tone per status, so a word means the same colour on every finance screen. */
const TONES: Record<string, 'g' | 'y' | 'r' | 'b' | 'n'> = {
  paid: 'g', succeeded: 'g', active: 'g', refunded: 'b', approved: 'g',
  open: 'b', unpaid: 'y', partially_paid: 'y', pending: 'y', processing: 'y', trialing: 'b', draft: 'n',
  overdue: 'r', failed: 'r', disputed: 'r', rejected: 'r', cancelled: 'r', suspended: 'r', expired: 'n',
  void: 'n', credit_note: 'b', partially_refunded: 'y',
}
export const Status = ({ value }: { value?: string | null }) =>
  value ? <Badge tone={TONES[value] ?? 'n'}>{value.replace(/_/g, ' ')}</Badge> : <span className="text-muted">—</span>

export const RANGES = [
  { id: 'today', label: 'Today' }, { id: '7d', label: '7 days' }, { id: '30d', label: '30 days' },
  { id: 'month', label: 'This month' }, { id: 'year', label: 'This year' }, { id: 'custom', label: 'Custom' },
] as const
export type RangeId = (typeof RANGES)[number]['id']

/** The shared date-range control. Custom reveals the two dates and nothing else does. */
export function RangePicker({ value, onChange, from, to, onFrom, onTo }: {
  value: RangeId; onChange: (v: RangeId) => void
  from: string; to: string; onFrom: (v: string) => void; onTo: (v: string) => void
}) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <div role="group" aria-label="Date range" className="flex flex-wrap gap-1 rounded-lg bg-head p-1">
        {RANGES.map((r) => (
          <button key={r.id} type="button" onClick={() => onChange(r.id)} aria-pressed={value === r.id}
            className={`rounded-md px-2.5 py-1.5 text-xs font-medium ${value === r.id ? 'bg-card text-fg shadow-sm' : 'text-muted hover:text-fg'}`}>
            {r.label}
          </button>
        ))}
      </div>
      {value === 'custom' && (
        <>
          <input type="date" aria-label="From" className="input w-auto" value={from} onChange={(e) => onFrom(e.target.value)} />
          <span className="text-muted text-sm">to</span>
          <input type="date" aria-label="To" className="input w-auto" value={to} onChange={(e) => onTo(e.target.value)} />
        </>
      )}
    </div>
  )
}

/** The query the range control produces, ready to hand to useList. */
export function useRange() {
  const today = new Date().toISOString().slice(0, 10)
  const [range, setRange] = useState<RangeId>('30d')
  const [from, setFrom] = useState(today)
  const [to, setTo] = useState(today)
  const query = useMemo(() => (range === 'custom' ? { range, from, to } : { range }), [range, from, to])
  const picker = <RangePicker value={range} onChange={setRange} from={from} to={to} onFrom={setFrom} onTo={setTo} />
  return { query, picker }
}

/* ----------------------------------------------------------------- overview */

type Kpis = {
  currency: string
  total_revenue_cents: number; mrr_cents: number; arr_cents: number
  active_subscriptions: number; trial_subscriptions: number; expired_subscriptions: number
  outstanding_cents: number; outstanding_invoices: number
  overdue_cents: number; overdue_invoices: number
  successful_payments: number; failed_payments: number; payment_success_rate: number | null
  pending_refunds: number; refunded_cents: number
  customers: number; arpc_cents: number; churn_rate: number | null
}
type Point = { month: string; revenue_cents: number; refund_cents: number; payments: number; new_subscriptions: number }
type Overview = {
  kpis: Kpis; series: Point[]
  breakdowns: { by_gateway: { gateway: string; payments: number; amount_cents: number }[]; by_plan: { plan: string; code: string | null; subscriptions: number }[] }
}

export function FinanceOverview() {
  const { query, picker } = useRange()
  const { item, isLoading } = useOne<Overview>('/finance/overview', query)
  const k = item?.kpis
  const ccy = k?.currency ?? 'EUR'
  const series = item?.series ?? []

  return (
    <>
      <PageHeader title="Finance" subtitle="Revenue, subscriptions and what is still owed" actions={picker} />

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile label="Total revenue" value={money(k?.total_revenue_cents, ccy)} icon={<Icon name="wallet" size={16} />} iconTone="g" />
        <StatTile label="MRR" value={money(k?.mrr_cents, ccy)} icon={<Icon name="pulse" size={16} />} iconTone="b" />
        <StatTile label="ARR" value={money(k?.arr_cents, ccy)} icon={<Icon name="pulse" size={16} />} iconTone="b" />
        <StatTile label="Revenue per customer" value={money(k?.arpc_cents, ccy)} icon={<Icon name="users" size={16} />} />
        <StatTile label="Active subscriptions" value={k?.active_subscriptions ?? '—'} icon={<Icon name="rocket" size={16} />} iconTone="g" />
        <StatTile label="On trial" value={k?.trial_subscriptions ?? '—'} icon={<Icon name="clock" size={16} />} />
        <StatTile label="Expired or cancelled" value={k?.expired_subscriptions ?? '—'} icon={<Icon name="archive" size={16} />} />
        <StatTile label="Churn" value={k?.churn_rate == null ? '—' : `${k.churn_rate}%`}
          valueTone={k?.churn_rate != null && k.churn_rate > 5 ? 'r' : 'n'} icon={<Icon name="pulse" size={16} />} />
        <StatTile label="Outstanding" value={money(k?.outstanding_cents, ccy)} delta={`${k?.outstanding_invoices ?? 0} invoices`} icon={<Icon name="file" size={16} />} iconTone="y" />
        <StatTile label="Overdue" value={money(k?.overdue_cents, ccy)} delta={`${k?.overdue_invoices ?? 0} invoices`}
          valueTone={(k?.overdue_cents ?? 0) > 0 ? 'r' : 'n'} icon={<Icon name="bell" size={16} />} iconTone="r" />
        <StatTile label="Payments" value={`${k?.successful_payments ?? 0} ok · ${k?.failed_payments ?? 0} failed`}
          delta={k?.payment_success_rate == null ? undefined : `${k.payment_success_rate}% success`} icon={<Icon name="gear" size={16} />} />
        <StatTile label="Refunds" value={money(k?.refunded_cents, ccy)} delta={`${k?.pending_refunds ?? 0} pending`} icon={<Icon name="share" size={16} />} iconTone="y" />
      </div>

      <Card title="Revenue and refunds" subtitle={`By calendar month, in ${ccy}`}>
        {series.length === 0 && !isLoading ? <Empty icon="chart">Nothing has been billed yet.</Empty> : (
          <LineChart label="Revenue and refunds by month" unit="" autoScale
            xLabels={series.map((p, i) => (i % 2 === 0 || i === series.length - 1 ? p.month.slice(2) : ''))}
            series={[
              { label: 'Revenue', color: 'var(--g-fg)', data: series.map((p) => p.revenue_cents / 100) },
              { label: 'Refunds', color: 'var(--r-fg)', data: series.map((p) => p.refund_cents / 100) },
            ]} />
        )}
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Subscription growth" subtitle="New subscriptions each month">
          <LineChart label="New subscriptions by month" unit="" autoScale
            xLabels={series.map((p, i) => (i % 2 === 0 || i === series.length - 1 ? p.month.slice(2) : ''))}
            series={[{ label: 'New', color: 'var(--accent)', data: series.map((p) => p.new_subscriptions) }]} />
        </Card>
        <Card title="How customers pay" subtitle="Collected in the selected range, by gateway">
          <DataTable label="Revenue by gateway" rows={item?.breakdowns.by_gateway ?? []} loading={isLoading}
            rowKey={(r) => r.gateway} empty="No payments in this range."
            columns={[
              { key: 'gateway', header: 'Gateway', render: (r) => <span className="font-medium capitalize">{r.gateway}</span> },
              { key: 'payments', header: 'Payments' },
              { key: 'amount_cents', header: 'Collected', render: (r) => <span className="tabular-nums">{money(r.amount_cents, ccy)}</span> },
            ]} />
        </Card>
      </div>

      <Card title="Package distribution" subtitle="Active subscriptions on each package">
        <DataTable label="Subscriptions by package" rows={item?.breakdowns.by_plan ?? []} loading={isLoading}
          rowKey={(r) => r.code ?? r.plan} empty="No active subscriptions."
          columns={[
            { key: 'plan', header: 'Package', render: (r) => <span className="font-medium">{r.plan}</span> },
            { key: 'code', header: 'Code', render: (r) => <span className="font-mono text-xs text-muted">{r.code ?? '—'}</span> },
            { key: 'subscriptions', header: 'Customers', render: (r) => <Badge tone="b">{r.subscriptions}</Badge> },
          ]} />
      </Card>
    </>
  )
}

/* ------------------------------------------------------------------ reports */

type Report = Overview & { top_customers: { organization_id: string; customer: string | null; payments: number; amount_cents: number }[] }

export function FinanceReports() {
  const { query, picker } = useRange()
  const { item, isLoading } = useOne<Report>('/finance/reports', query)
  const { item: summary } = useOne<{ currency: string; period: { from: string; to: string }; lines: { label: string; cents: number; display: string }[] }>('/finance/reports/summary', query)
  const ccy = item?.kpis.currency ?? 'EUR'
  const qs = new URLSearchParams(query as Record<string, string>).toString()

  return (
    <>
      <PageHeader title="Reports" subtitle="Revenue analytics over any period"
        actions={<div className="flex flex-wrap items-center gap-2">
          {picker}
          <a className="btn" href={`/api/v1/finance/reports/export?${qs}`}>Export CSV</a>
        </div>} />

      <Card title="Summary" subtitle={summary ? `${summary.period.from} to ${summary.period.to}` : undefined}>
        <dl className="grid gap-3 sm:grid-cols-3 xl:grid-cols-5">
          {(summary?.lines ?? []).map((l) => (
            <div key={l.label} className="rounded-[10px] border border-line bg-head p-3.5">
              <dt className="text-xs text-muted">{l.label}</dt>
              {/* Format here rather than trusting the server's string, so a negative reads -EUR1.00 and not EUR-1.00. */}
              <dd className={`mt-1 text-lg font-semibold tabular-nums ${l.cents < 0 ? 'text-[var(--r-fg)]' : ''}`}>{money(l.cents, ccy)}</dd>
            </div>
          ))}
        </dl>
      </Card>

      <Card title="Revenue trend" subtitle={`Twelve months, in ${ccy}`}>
        <LineChart label="Revenue by month" unit="" autoScale
          xLabels={(item?.series ?? []).map((p, i) => (i % 2 === 0 ? p.month.slice(2) : ''))}
          series={[{ label: 'Revenue', color: 'var(--g-fg)', data: (item?.series ?? []).map((p) => p.revenue_cents / 100) }]} />
      </Card>

      <Card title="Top customers" subtitle="By what they paid in the selected range">
        <DataTable label="Top customers" rows={item?.top_customers ?? []} loading={isLoading}
          rowKey={(r) => r.organization_id} empty="No payments in this range."
          columns={[
            { key: 'customer', header: 'Customer', render: (r) => <span className="font-medium">{r.customer ?? r.organization_id}</span> },
            { key: 'payments', header: 'Payments' },
            { key: 'amount_cents', header: 'Paid', render: (r) => <span className="tabular-nums">{money(r.amount_cents, ccy)}</span> },
          ]} />
      </Card>
    </>
  )
}

/* ----------------------------------------------------------------- settings */

type FinanceSettingsShape = {
  currency: string; invoice_prefix: string; invoice_number_padding: number; invoice_due_days: number
  invoice_footer: string; tax_enabled: boolean; tax_name: string; tax_percent: number; tax_inclusive: boolean
  auto_generate_invoices: boolean; auto_collect: boolean
  reminder_days_before_due: number[]; reminder_days_after_due: number[]
  retry_attempts: number; retry_interval_days: number
  refund_auto_approve_under_cents: number; refund_require_reason: boolean
}
type Gateway = { name: string; label: string; configured: boolean; default: boolean }

export function FinanceSettings() {
  const { item, refetch } = useOne<{ settings: FinanceSettingsShape; gateways: Gateway[] }>('/finance/settings')
  const { run, busy } = useAction()
  const [draft, setDraft] = useState<Partial<FinanceSettingsShape>>({})
  const s = { ...(item?.settings ?? {}), ...draft } as FinanceSettingsShape
  const set = <K extends keyof FinanceSettingsShape>(k: K, v: FinanceSettingsShape[K]) => setDraft((d) => ({ ...d, [k]: v }))

  const save = async () => {
    await run({ method: 'PATCH', path: '/finance/settings', body: draft, label: 'Save finance settings', success: 'Finance settings saved' }).catch(() => null)
    setDraft({})
    refetch()
  }

  // A comma-separated list is how people think about reminder days; the API
  // wants integers, so the parsing happens here rather than on the server.
  const days = (v: number[] | undefined) => (v ?? []).join(', ')
  const parseDays = (text: string) => text.split(',').map((x) => parseInt(x.trim(), 10)).filter((n) => Number.isFinite(n) && n >= 0)

  if (!item) return <PageHeader title="Finance settings" subtitle="Loading…" />

  return (
    <>
      <PageHeader title="Finance settings" subtitle="Numbering, tax, reminders and refunds"
        actions={<button type="button" className="btn btn-primary" disabled={busy || Object.keys(draft).length === 0} onClick={save}>
          {busy ? 'Saving…' : 'Save changes'}
        </button>} />

      <Card title="Invoicing" subtitle="How invoices are numbered and when they fall due">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Currency">
            <Select className="input" value={s.currency} onChange={(e) => set('currency', e.target.value)}>
              {['EUR', 'USD', 'GBP', 'LKR', 'AED', 'INR', 'AUD'].map((c) => <option key={c}>{c}</option>)}
            </Select>
          </Field>
          <Field label="Invoice prefix" hint="INV- gives INV-00001.">
            <input className="input font-mono" value={s.invoice_prefix} onChange={(e) => set('invoice_prefix', e.target.value)} />
          </Field>
          <Field label="Number padding" hint="Digits after the prefix.">
            <input className="input" type="number" min={1} max={12} value={s.invoice_number_padding}
              onChange={(e) => set('invoice_number_padding', Number(e.target.value))} />
          </Field>
          <Field label="Due after (days)">
            <input className="input" type="number" min={0} max={365} value={s.invoice_due_days}
              onChange={(e) => set('invoice_due_days', Number(e.target.value))} />
          </Field>
          <div className="sm:col-span-2">
            <Field label="Invoice footer" hint="Printed at the foot of every invoice.">
              <textarea className="input" rows={2} value={s.invoice_footer ?? ''} onChange={(e) => set('invoice_footer', e.target.value)} />
            </Field>
          </div>
        </div>
      </Card>

      <Card title="Tax" subtitle="Applied to invoices the panel raises">
        <div className="grid gap-4 sm:grid-cols-2">
          <Toggle checked={s.tax_enabled} onChange={(v) => set('tax_enabled', v)} label="Charge tax on invoices" />
          <Toggle checked={s.tax_inclusive} onChange={(v) => set('tax_inclusive', v)} label="Prices already include tax" />
          <Field label="Tax name" hint="VAT, GST, Sales tax…">
            <input className="input" value={s.tax_name} onChange={(e) => set('tax_name', e.target.value)} disabled={!s.tax_enabled} />
          </Field>
          <Field label="Rate (%)">
            <input className="input" type="number" step="0.01" min={0} max={100} value={s.tax_percent}
              onChange={(e) => set('tax_percent', Number(e.target.value))} disabled={!s.tax_enabled} />
          </Field>
        </div>
      </Card>

      <Card title="Automation" subtitle="What happens without anyone pressing anything">
        <div className="grid gap-4 sm:grid-cols-2">
          <Toggle checked={s.auto_generate_invoices} onChange={(v) => set('auto_generate_invoices', v)} label="Generate subscription invoices each month" />
          <Toggle checked={s.auto_collect} onChange={(v) => set('auto_collect', v)} label="Collect automatically through the gateway" />
          <Field label="Remind before due (days)" hint="Comma separated — 3 means three days before.">
            <input className="input" defaultValue={days(s.reminder_days_before_due)}
              onBlur={(e) => set('reminder_days_before_due', parseDays(e.target.value))} />
          </Field>
          <Field label="Remind after due (days)">
            <input className="input" defaultValue={days(s.reminder_days_after_due)}
              onBlur={(e) => set('reminder_days_after_due', parseDays(e.target.value))} />
          </Field>
          <Field label="Retry a failed collection" hint="Times, before the invoice is left to the customer.">
            <input className="input" type="number" min={0} max={10} value={s.retry_attempts}
              onChange={(e) => set('retry_attempts', Number(e.target.value))} />
          </Field>
          <Field label="Days between retries">
            <input className="input" type="number" min={1} max={30} value={s.retry_interval_days}
              onChange={(e) => set('retry_interval_days', Number(e.target.value))} />
          </Field>
        </div>
      </Card>

      <Card title="Refunds">
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Approve automatically under (minor units)" hint="0 means every refund is decided by a person.">
            <input className="input" type="number" min={0} value={s.refund_auto_approve_under_cents}
              onChange={(e) => set('refund_auto_approve_under_cents', Number(e.target.value))} />
          </Field>
          <Toggle checked={s.refund_require_reason} onChange={(v) => set('refund_require_reason', v)} label="A reason is required on every request" />
        </div>
      </Card>

      <FinanceGatewayList gateways={item.gateways} />
    </>
  )
}

/* ---------------------------------------------------------------- gateways */

function FinanceGatewayList({ gateways }: { gateways: Gateway[] }) {
  const { run, busy } = useAction()
  const [result, setResult] = useState<Record<string, string>>({})

  const test = async (name: string) => {
    const res = await run<{ data: { ok: boolean; message?: string } }>({
      method: 'POST', path: '/finance/settings/test-gateway', body: { gateway: name },
      label: `Test ${name}`, success: 'Gateway checked',
    }).catch(() => null)
    setResult((r) => ({ ...r, [name]: res?.data?.ok ? 'Reachable' : (res?.data?.message ?? 'Could not reach it') }))
  }

  return (
    <Card title="Payment gateways" subtitle="Credentials are set under Settings → Third-party services, and are never shown here">
      {gateways.length === 0 ? <Empty icon="gear">No gateway is configured, so nothing can be collected automatically.</Empty> : (
        <DataTable label="Payment gateways" rows={gateways} rowKey={(r) => r.name}
          actions={(r) => [{ label: `Test ${r.label}`, icon: 'pulse', disabled: busy, onClick: () => test(r.name) }]}
          columns={[
            { key: 'label', header: 'Gateway', render: (r) => <span className="font-medium">{r.label}</span> },
            { key: 'name', header: 'Driver', render: (r) => <span className="font-mono text-xs text-muted">{r.name}</span> },
            { key: 'default', header: 'Default', render: (r) => (r.default ? <Badge tone="g">default</Badge> : <span className="text-muted">—</span>) },
            { key: 'configured', header: 'Status', render: (r) => <Badge tone={r.configured ? 'g' : 'n'}>{r.configured ? 'configured' : 'not set up'}</Badge> },
            { key: 'result', header: 'Last check', render: (r) => <span className="text-xs text-muted">{result[r.name] ?? '—'}</span> },
          ]} />
      )}
    </Card>
  )
}

export function FinanceGateways() {
  const { item } = useOne<{ settings: FinanceSettingsShape; gateways: Gateway[] }>('/finance/settings')
  return (
    <>
      <PageHeader title="Payment gateways" subtitle="Who can take money, and whether they are reachable" />
      <FinanceGatewayList gateways={item?.gateways ?? []} />
    </>
  )
}

/* ------------------------------------------------------------ billing tabs */

type Profile = {
  id: string; organization_id: string; customer: string | null
  company_name: string | null; contact_name: string | null; email: string | null; phone: string | null
  address_line1: string | null; address_line2: string | null; city: string | null; region: string | null
  postal_code: string | null; country: string | null; tax_id: string | null; vat_number: string | null
  tax_exempt: boolean; currency: string; balance_cents: number; invoice_notes: string | null
}

export function FinanceProfiles() {
  const profiles = useList<Profile>('/finance/billing-profiles')
  const { run, busy } = useAction()
  const [edit, setEdit] = useState<Profile | null>(null)

  const save = async () => {
    if (!edit) return
    const { id, organization_id, customer, balance_cents, ...body } = edit
    const ok = await run({
      method: 'PATCH', path: `/finance/billing-profiles/${organization_id}`, body,
      label: `Update ${customer ?? 'billing profile'}`, success: 'Billing profile saved',
    }).catch(() => null)
    if (ok) { setEdit(null); profiles.refetch() }
  }

  return (
    <>
      <PageHeader title="Billing profiles" subtitle="Who each invoice is addressed to, and under which tax identity" />

      {edit && (
        <Drawer open onClose={() => setEdit(null)} width={560} title={`Billing details — ${edit.customer ?? ''}`}
          footer={<>
            <button type="button" className="btn" onClick={() => setEdit(null)}>Cancel</button>
            <button type="submit" form="profile-form" className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button>
          </>}>
          <form id="profile-form" className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); save() }}>
            <Field label="Company"><input className="input" value={edit.company_name ?? ''} onChange={(e) => setEdit({ ...edit, company_name: e.target.value })} /></Field>
            <Field label="Contact"><input className="input" value={edit.contact_name ?? ''} onChange={(e) => setEdit({ ...edit, contact_name: e.target.value })} /></Field>
            <Field label="Billing email"><input className="input" type="email" value={edit.email ?? ''} onChange={(e) => setEdit({ ...edit, email: e.target.value })} /></Field>
            <Field label="Phone"><input className="input" value={edit.phone ?? ''} onChange={(e) => setEdit({ ...edit, phone: e.target.value })} /></Field>
            <div className="sm:col-span-2"><Field label="Address"><input className="input" value={edit.address_line1 ?? ''} onChange={(e) => setEdit({ ...edit, address_line1: e.target.value })} /></Field></div>
            <div className="sm:col-span-2"><Field label="Address line 2"><input className="input" value={edit.address_line2 ?? ''} onChange={(e) => setEdit({ ...edit, address_line2: e.target.value })} /></Field></div>
            <Field label="City"><input className="input" value={edit.city ?? ''} onChange={(e) => setEdit({ ...edit, city: e.target.value })} /></Field>
            <Field label="Region"><input className="input" value={edit.region ?? ''} onChange={(e) => setEdit({ ...edit, region: e.target.value })} /></Field>
            <Field label="Postal code"><input className="input" value={edit.postal_code ?? ''} onChange={(e) => setEdit({ ...edit, postal_code: e.target.value })} /></Field>
            <Field label="Country" hint="Two letters, ISO 3166."><input className="input uppercase" maxLength={2} value={edit.country ?? ''} onChange={(e) => setEdit({ ...edit, country: e.target.value.toUpperCase() })} /></Field>
            <Field label="Tax ID"><input className="input" value={edit.tax_id ?? ''} onChange={(e) => setEdit({ ...edit, tax_id: e.target.value })} /></Field>
            <Field label="VAT number"><input className="input" value={edit.vat_number ?? ''} onChange={(e) => setEdit({ ...edit, vat_number: e.target.value })} /></Field>
            <Field label="Currency">
              <Select className="input" value={edit.currency} onChange={(e) => setEdit({ ...edit, currency: e.target.value })}>
                {['EUR', 'USD', 'GBP', 'LKR', 'AED', 'INR', 'AUD'].map((c) => <option key={c}>{c}</option>)}
              </Select>
            </Field>
            <div className="sm:col-span-2">
              <Toggle checked={edit.tax_exempt} onChange={(v) => setEdit({ ...edit, tax_exempt: v })} label="Exempt from tax" />
            </div>
            <div className="sm:col-span-2">
              <Field label="Notes on every invoice"><textarea className="input" rows={2} value={edit.invoice_notes ?? ''} onChange={(e) => setEdit({ ...edit, invoice_notes: e.target.value })} /></Field>
            </div>
          </form>
        </Drawer>
      )}

      <DataTable label="Billing profiles" rows={profiles.rows} loading={profiles.isLoading} rowKey={(r) => r.id}
        empty="No billing profiles yet. One is created the first time a customer is invoiced."
        actions={(r) => [{ label: `Edit ${r.customer ?? 'profile'}`, icon: 'pencil', onClick: () => setEdit(r) }]}
        columns={[
          { key: 'customer', header: 'Customer', render: (r) => <span className="font-semibold">{r.customer ?? '—'}</span> },
          { key: 'company_name', header: 'Company', render: (r) => r.company_name ?? <span className="text-muted">—</span> },
          { key: 'email', header: 'Billing email', render: (r) => r.email ?? <span className="text-muted">—</span> },
          { key: 'country', header: 'Country', render: (r) => r.country ?? <span className="text-muted">—</span> },
          { key: 'tax_id', header: 'Tax ID', render: (r) => <span className="font-mono text-xs">{r.tax_id ?? r.vat_number ?? '—'}</span> },
          { key: 'balance_cents', header: 'Credit', render: (r) => <span className="tabular-nums">{money(r.balance_cents, r.currency)}</span> },
        ]} />
    </>
  )
}
