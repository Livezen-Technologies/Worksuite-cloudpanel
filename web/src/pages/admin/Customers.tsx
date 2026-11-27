import { useState } from 'react'
import { Link } from 'react-router'
import { useList, useOne, useAction } from '../../api/hooks'
import { errorMessage, isApiError } from '../../api/client'
import { Card, Field, PageHeader, StatTile, Empty } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { Icon } from '../../components/Icon'
import { DataTable } from '../../components/DataTable'
import { LineChart } from '../../components/Charts'
import { Drawer } from '../../components/Overlay'
import Select from '../../components/Select'
import { RangePicker, useRange, when } from './Finance'

/**
 * CUST-2..5: the Customers section -- dashboard, directory, companies,
 * contacts and groups. The per-customer profile is the existing
 * CustomerProfile page, extended rather than duplicated.
 */

/** 'all' is the no-filter choice in these selects, so it must not reach the API. */
const unlessAll = (v: string) => (v === 'all' ? undefined : v)

const STATUS_TONE: Record<string, 'g' | 'y' | 'r' | 'b' | 'n'> = {
  active: 'g', trial: 'b', pending: 'y', suspended: 'r', closed: 'n',
}

function Status({ value }: { value?: string | null }) {
  if (!value) return <span className="text-muted">—</span>
  return <Badge tone={STATUS_TONE[value] ?? 'n'}>{value.replace(/_/g, ' ')}</Badge>
}

function Filters({ children }: { children: React.ReactNode }) {
  return <div className="mb-3 flex flex-wrap items-end gap-2">{children}</div>
}

type FilterOptions = {
  statuses: string[]
  types: string[]
  groups: { id: string; name: string; colour: string | null; kind: string; discount_percent: number }[]
  account_managers: { id: string; name: string }[]
  countries: string[]
}

type Customer = {
  id: string; name: string; email: string | null; phone: string | null; country: string | null
  customer_type: string; company: string | null
  groups: { id: string; name: string; colour: string | null }[]
  account_manager: string | null; account_manager_id: string | null
  services: number; applications: number; vps: number; domains: number; users: number
  plan: string | null; subscription_status: string | null; onboarding_status: string
  status: string; registered_at: string | null; last_activity_at: string | null; archived_at: string | null
}

/* ---------------------------------------------------------------- overview */

type Overview = {
  total_customers: number; active_customers: number; suspended_customers: number
  new_today: number; new_this_month: number; new_in_range: number
  pending_verification: number
  onboarding_pending: number; onboarding_completed: number; onboarding_failed: number
  total_companies: number; total_groups: number
  hosting_customers: number; vps_customers: number; expiring_services: number
  retention_rate: number | null
  by_type: { label: string; total: number }[]
  by_status: { label: string; total: number }[]
  growth: { month: string; customers: number }[]
}

export function CustomersOverview() {
  const { query, picker } = useRange()
  const { item, isLoading } = useOne<Overview>('/customer-accounts/overview', query)
  const k = item
  const growth = item?.growth ?? []

  return (
    <>
      <PageHeader title="Customers" subtitle="Who the panel hosts, and how that is changing" actions={picker} />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatTile label="Total customers" value={k?.total_customers ?? '—'} icon={<Icon name="users" size={16} />} />
        <StatTile label="Active" value={k?.active_customers ?? '—'} icon={<Icon name="check" size={16} />} iconTone="g" />
        <StatTile label="Suspended" value={k?.suspended_customers ?? '—'}
          valueTone={(k?.suspended_customers ?? 0) > 0 ? 'r' : 'n'} icon={<Icon name="pause" size={16} />} iconTone="r" />
        <StatTile label="Awaiting verification" value={k?.pending_verification ?? '—'} icon={<Icon name="clock" size={16} />} iconTone="y" />

        <StatTile label="New today" value={k?.new_today ?? '—'} icon={<Icon name="rocket" size={16} />} iconTone="g" />
        <StatTile label="New this month" value={k?.new_this_month ?? '—'} delta={`${k?.new_in_range ?? 0} in range`} icon={<Icon name="chart" size={16} />} />
        <StatTile label="Companies" value={k?.total_companies ?? '—'} icon={<Icon name="layers" size={16} />} />
        <StatTile label="Groups" value={k?.total_groups ?? '—'} icon={<Icon name="tag" size={16} />} />

        <StatTile label="Hosting customers" value={k?.hosting_customers ?? '—'} icon={<Icon name="globe" size={16} />} />
        <StatTile label="VPS customers" value={k?.vps_customers ?? '—'} icon={<Icon name="server" size={16} />} />
        <StatTile label="Services expiring in 30 days" value={k?.expiring_services ?? '—'}
          valueTone={(k?.expiring_services ?? 0) > 0 ? 'y' : 'n'} icon={<Icon name="bell" size={16} />} iconTone="y" />
        <StatTile label="Retention" value={k?.retention_rate == null ? '—' : `${k.retention_rate}%`}
          delta={k?.retention_rate == null ? 'nobody to measure yet' : undefined} icon={<Icon name="pulse" size={16} />} />
      </div>

      <div className="grid gap-4 xl:grid-cols-3">
        <StatTile label="Onboarding in progress" value={k?.onboarding_pending ?? '—'} icon={<Icon name="clock" size={16} />} iconTone="b" />
        <StatTile label="Onboarding completed" value={k?.onboarding_completed ?? '—'} icon={<Icon name="check" size={16} />} iconTone="g" />
        <StatTile label="Onboarding failed" value={k?.onboarding_failed ?? '—'}
          valueTone={(k?.onboarding_failed ?? 0) > 0 ? 'r' : 'n'} icon={<Icon name="close" size={16} />} iconTone="r" />
      </div>

      <Card title="Customer growth" subtitle="New customers each month">
        {growth.length === 0 && !isLoading ? <Empty icon="chart">No customers yet.</Empty> : (
          <LineChart label="New customers by month" unit="" autoScale
            xLabels={growth.map((p, i) => (i % 2 === 0 || i === growth.length - 1 ? p.month.slice(2) : ''))}
            series={[{ label: 'New', color: 'var(--accent)', data: growth.map((p) => p.customers) }]} />
        )}
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="By type" subtitle="How the book of customers splits">
          <DataTable label="Customers by type" rows={item?.by_type ?? []} loading={isLoading}
            rowKey={(r) => r.label} empty="No customers yet."
            columns={[
              { key: 'label', header: 'Type', render: (r) => <span className="font-medium capitalize">{r.label}</span> },
              { key: 'total', header: 'Customers', render: (r) => <Badge tone="b">{r.total}</Badge> },
            ]} />
        </Card>
        <Card title="By status" subtitle="Where accounts stand">
          <DataTable label="Customers by status" rows={item?.by_status ?? []} loading={isLoading}
            rowKey={(r) => r.label} empty="No customers yet."
            columns={[
              { key: 'label', header: 'Status', render: (r) => <Status value={r.label} /> },
              { key: 'total', header: 'Customers' },
            ]} />
        </Card>
      </div>

    </>
  )
}

/* --------------------------------------------------------------- directory */

export function CustomerDirectory() {
  const [status, setStatus] = useState('all')
  const [type, setType] = useState('all')
  const [group, setGroup] = useState('all')
  const [manager, setManager] = useState('all')
  const [archived, setArchived] = useState(false)
  const { item: options } = useOne<FilterOptions>('/customer-accounts/filters')
  const query = {
    status: unlessAll(status), customer_type: unlessAll(type), group_id: unlessAll(group),
    account_manager_id: unlessAll(manager), archived: archived ? 1 : undefined,
  }
  const customers = useList<Customer>('/customer-accounts', query)
  const exportQs = new URLSearchParams(
    Object.entries(query).filter(([, v]) => v !== undefined).map(([k, v]) => [k, String(v)]),
  ).toString()

  return (
    <>
      <PageHeader title={archived ? 'Archived customers' : 'All customers'}
        subtitle="Every account the panel hosts, and what it is on"
        actions={<a className="btn" href={`/api/v1/customer-accounts/export?${exportQs}`}>Export CSV</a>} />

      <Filters>
        <Field label="Status">
          <Select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="all">Any status</option>
            {(options?.statuses ?? []).map((s) => <option key={s} value={s}>{s}</option>)}
          </Select>
        </Field>
        <Field label="Type">
          <Select className="input" value={type} onChange={(e) => setType(e.target.value)}>
            <option value="all">Any type</option>
            {(options?.types ?? []).map((t) => <option key={t} value={t}>{t}</option>)}
          </Select>
        </Field>
        <Field label="Group">
          <Select className="input" value={group} onChange={(e) => setGroup(e.target.value)}>
            <option value="all">Any group</option>
            {(options?.groups ?? []).map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
          </Select>
        </Field>
        <Field label="Account manager">
          <Select className="input" value={manager} onChange={(e) => setManager(e.target.value)}>
            <option value="all">Anyone</option>
            {(options?.account_managers ?? []).map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
          </Select>
        </Field>
        <button type="button" className={`btn ${archived ? 'btn-primary' : ''}`} onClick={() => setArchived(!archived)}>
          {archived ? 'Showing archive' : 'Show archive'}
        </button>
      </Filters>

      <DataTable label="Customers" rows={customers.rows} loading={customers.isLoading} rowKey={(r) => r.id}
        empty={archived ? 'Nothing is archived.' : 'No customers match.'}
        columns={[
          { key: 'name', header: 'Customer', render: (r) => (
            <Link to={`/admin/customers/${r.id}`} className="font-medium">{r.name}</Link>
          ) },
          { key: 'email', header: 'Email', render: (r) => <span className="text-muted">{r.email ?? '—'}</span> },
          { key: 'country', header: 'Country', render: (r) => r.country ?? '—' },
          { key: 'customer_type', header: 'Type', render: (r) => <span className="capitalize">{r.customer_type}</span> },
          { key: 'company', header: 'Company', render: (r) => r.company ?? '—' },
          { key: 'groups', header: 'Groups', render: (r) => r.groups.length === 0 ? <span className="text-muted">—</span> : (
            <span className="flex flex-wrap gap-1">{r.groups.map((g) => <Badge key={g.id} tone="b">{g.name}</Badge>)}</span>
          ) },
          { key: 'account_manager', header: 'Manager', render: (r) => r.account_manager ?? '—' },
          { key: 'services', header: 'Services', render: (r) => (
            <span className="tabular-nums" title={`${r.applications} apps, ${r.vps} VPS, ${r.domains} domains`}>{r.services}</span>
          ) },
          { key: 'plan', header: 'Package', render: (r) => r.plan ?? '—' },
          { key: 'subscription_status', header: 'Subscription', render: (r) => <Status value={r.subscription_status} /> },
          { key: 'onboarding_status', header: 'Onboarding', render: (r) => (
            r.onboarding_status === 'none' ? <span className="text-muted">—</span> : <Status value={r.onboarding_status} />
          ) },
          { key: 'status', header: 'Status', render: (r) => <Status value={r.status} /> },
          { key: 'registered_at', header: 'Registered', render: (r) => when(r.registered_at) },
          { key: 'last_activity_at', header: 'Last activity', render: (r) => when(r.last_activity_at) },
        ]} />
    </>
  )
}

/* --------------------------------------------------------------- companies */

type Company = {
  id: string; organization_id: string; customer: string | null; company_name: string | null
  registration_number: string | null; tax_id: string | null; vat_number: string | null
  industry: string | null; website: string | null; phone: string | null; email: string | null
  contact_name: string | null; address_line1: string | null; address_line2: string | null
  city: string | null; region: string | null; postal_code: string | null; country: string | null
  currency: string; balance_cents: number
}

export function Companies() {
  const companies = useList<Company>('/companies')
  const [open, setOpen] = useState<Company | null>(null)
  const [draft, setDraft] = useState<Partial<Company>>({})
  const { run, busy } = useAction()

  const save = async () => {
    if (!open) return
    await run({
      method: 'PATCH', path: `/companies/${open.organization_id}`, body: draft,
      label: 'Save company', success: 'Company saved',
    }).then(() => { setOpen(null); companies.refetch() }).catch(() => {})
  }

  return (
    <>
      <PageHeader title="Companies" subtitle="The business identity each account is invoiced under" />

      <DataTable label="Companies" rows={companies.rows} loading={companies.isLoading} rowKey={(r) => r.id}
        onRowClick={(r) => { setOpen(r); setDraft({}) }} empty="No company details recorded yet."
        columns={[
          { key: 'company_name', header: 'Company', render: (r) => <span className="font-medium">{r.company_name ?? '—'}</span> },
          { key: 'customer', header: 'Customer', render: (r) => (
            <Link to={`/admin/customers/${r.organization_id}`}>{r.customer ?? '—'}</Link>
          ) },
          { key: 'industry', header: 'Industry', render: (r) => r.industry ?? '—' },
          { key: 'registration_number', header: 'Reg. no.', render: (r) => r.registration_number ?? '—' },
          { key: 'vat_number', header: 'VAT', render: (r) => r.vat_number ?? r.tax_id ?? '—' },
          { key: 'email', header: 'Billing email', render: (r) => <span className="text-muted">{r.email ?? '—'}</span> },
          { key: 'country', header: 'Country', render: (r) => r.country ?? '—' },
        ]} />

      <Drawer open={!!open} onClose={() => setOpen(null)} title={open?.company_name ?? 'Company'}>
        {open && (
          <div className="grid gap-3 sm:grid-cols-2">
            {([
              ['company_name', 'Company name'], ['registration_number', 'Registration number'],
              ['tax_id', 'Tax id'], ['vat_number', 'VAT number'],
              ['industry', 'Industry'], ['website', 'Website'],
              ['phone', 'Phone'], ['email', 'Billing email'],
              ['contact_name', 'Billing contact'], ['address_line1', 'Address line 1'],
              ['address_line2', 'Address line 2'], ['city', 'City'],
              ['region', 'Region'], ['postal_code', 'Postal code'], ['country', 'Country (2 letters)'],
            ] as [keyof Company, string][]).map(([key, label]) => (
              <Field key={String(key)} label={label}>
                <input className="input" value={String(draft[key] ?? open[key] ?? '')}
                  onChange={(e) => setDraft({ ...draft, [key]: e.target.value })} />
              </Field>
            ))}
            <div className="sm:col-span-2 flex justify-end gap-2">
              <button type="button" className="btn" onClick={() => setOpen(null)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={busy} onClick={save}>Save company</button>
            </div>
          </div>
        )}
      </Drawer>
    </>
  )
}

/* ------------------------------------------------------------------ groups */

type Clause = { field: string; op: string; value?: string | number | string[] }
type Rules = { all?: Clause[]; any?: Clause[] }

type Group = {
  id: string; name: string; slug: string; kind: string; discount_percent: number
  colour: string | null; description: string | null; is_system: boolean; members: number
  rules: Rules | null; plan_overrides: Record<string, number> | null
  rules_run_at: string | null; rules_matched: number | null
}

type RuleFields = { fields: { key: string; numeric: boolean }[]; operators: string[] }

/** The operators that take no value, and the ones that take a list. */
const VALUELESS = ['is_null', 'not_null']
const LISTED = ['in', 'not_in']

const OP_LABEL: Record<string, string> = {
  eq: 'is', ne: 'is not', gt: 'is more than', gte: 'is at least', lt: 'is less than',
  lte: 'is at most', in: 'is one of', not_in: 'is none of', contains: 'contains',
  is_null: 'is empty', not_null: 'is set',
}

const BLANK_CLAUSE: Clause = { field: 'customer_type', op: 'eq', value: '' }

/**
 * A refused rule set says *which* rule is wrong in the field errors; the
 * envelope's own message is only "the given data was invalid", which would
 * leave the operator guessing.
 */
const ruleMessage = (e: unknown) =>
  (isApiError(e) && Object.values(e.fields)[0]?.[0]) || errorMessage(e)

/**
 * The rule builder for a dynamic group. It only ever produces field/op/value
 * triples from the server's own whitelist -- there is no free-text query to
 * type, because the evaluator would refuse one anyway.
 */
function RuleBuilder({ rules, fields, onChange }: {
  rules: Rules; fields: RuleFields | undefined; onChange: (r: Rules) => void
}) {
  const mode: 'all' | 'any' = rules.any ? 'any' : 'all'
  const clauses = (rules[mode] ?? []) as Clause[]
  const set = (next: Clause[]) => onChange({ [mode]: next } as Rules)

  const edit = (i: number, patch: Partial<Clause>) =>
    set(clauses.map((c, j) => (j === i ? { ...c, ...patch } : c)))

  return (
    <div className="grid gap-2">
      <div className="flex items-center gap-2 text-sm">
        <span className="text-muted">A customer belongs when</span>
        <Select className="input w-auto" value={mode}
          onChange={(e) => onChange({ [e.target.value]: clauses } as Rules)}>
          <option value="all">every rule matches</option>
          <option value="any">any rule matches</option>
        </Select>
      </div>

      {clauses.map((c, i) => {
        const field = fields?.fields.find((f) => f.key === c.field)
        return (
          <div key={i} className="flex flex-wrap items-center gap-2">
            <Select className="input w-auto" value={c.field} onChange={(e) => edit(i, { field: e.target.value, value: '' })}>
              {(fields?.fields ?? []).map((f) => (
                <option key={f.key} value={f.key}>{f.key.replace(/_/g, ' ')}</option>
              ))}
            </Select>
            <Select className="input w-auto" value={c.op} onChange={(e) => edit(i, { op: e.target.value, value: '' })}>
              {(fields?.operators ?? []).map((o) => <option key={o} value={o}>{OP_LABEL[o] ?? o}</option>)}
            </Select>
            {!VALUELESS.includes(c.op) && (
              <input className="input flex-1 min-w-32" type={field?.numeric ? 'number' : 'text'}
                placeholder={LISTED.includes(c.op) ? 'comma separated' : 'value'}
                value={Array.isArray(c.value) ? c.value.join(', ') : String(c.value ?? '')}
                onChange={(e) => edit(i, {
                  value: LISTED.includes(c.op)
                    ? e.target.value.split(',').map((v) => v.trim()).filter(Boolean)
                    : field?.numeric ? Number(e.target.value) : e.target.value,
                })} />
            )}
            <button type="button" className="btn btn-sm" aria-label="Remove rule"
              onClick={() => set(clauses.filter((_, j) => j !== i))}><Icon name="trash" size={14} /></button>
          </div>
        )
      })}

      <button type="button" className="btn btn-sm w-fit" onClick={() => set([...clauses, { ...BLANK_CLAUSE }])}>
        Add rule
      </button>
    </div>
  )
}

const BLANK_GROUP = { name: '', kind: 'static', discount_percent: 0, description: '' }

const EMPTY_RULES: Rules = { all: [{ ...BLANK_CLAUSE }] }

type Preview = { total: number; sample: { id: string; name: string; status: string; customer_type: string; country: string | null; active_services: number }[] }

export function CustomerGroups() {
  const groups = useList<Group>('/customer-groups')
  const fields = useOne<RuleFields>('/customer-group-fields')
  const { run, busy } = useAction()
  const [form, setForm] = useState<typeof BLANK_GROUP | null>(null)
  const [edit, setEdit] = useState<Group | null>(null)
  const [rules, setRules] = useState<Rules>(EMPTY_RULES)
  const [preview, setPreview] = useState<Preview | null>(null)
  const [ruleError, setRuleError] = useState<string | null>(null)

  const create = async () => {
    if (!form) return
    await run({ method: 'POST', path: '/customer-groups', body: form, label: 'Create group', success: 'Group created' })
      .then(() => { setForm(null); groups.refetch() }).catch(() => {})
  }

  const saveEdit = async () => {
    if (!edit) return
    await run({
      method: 'PATCH', path: `/customer-groups/${edit.id}`,
      body: {
        discount_percent: edit.discount_percent, description: edit.description, colour: edit.colour,
        ...(edit.kind === 'dynamic' ? { rules } : {}),
      },
      label: 'Save group', success: 'Group saved',
    }).then(() => { setEdit(null); groups.refetch() }).catch(() => {})
  }

  const openEdit = (g: Group) => {
    setEdit(g)
    setRules(g.rules ?? EMPTY_RULES)
    setPreview(null)
    setRuleError(null)
  }

  /**
   * Counts what the rules on screen would match, without saving them. The
   * toast is suppressed -- a preview is not an event worth announcing -- so a
   * rejected rule set is reported next to the builder instead.
   */
  const runPreview = async () => {
    if (!edit) return
    setRuleError(null)
    await run<{ data: Preview }>({
      method: 'POST', path: `/customer-groups/${edit.id}/preview-rules`, body: { rules },
      label: 'Preview rules', silent: true,
    }).then((d) => setPreview(d.data))
      .catch((e: unknown) => setRuleError(ruleMessage(e)))
  }

  return (
    <>
      <PageHeader title="Customer groups" subtitle="Segments, and the discount each one carries"
        actions={<button type="button" className="btn btn-primary" onClick={() => setForm({ ...BLANK_GROUP })}>New group</button>} />

      <DataTable label="Customer groups" rows={groups.rows} loading={groups.isLoading} rowKey={(r) => r.id}
        onRowClick={(r) => openEdit(r)} empty="No groups yet."
        actions={(r) => [
          { label: 'Edit', icon: 'pencil', onClick: () => openEdit(r) },
          {
            label: 'Recompute members', icon: 'refresh', disabled: busy || r.kind !== 'dynamic' || !r.rules,
            onClick: () => run({
              method: 'POST', path: `/customer-groups/${r.id}/recompute`, label: 'Recompute',
              success: 'Membership refreshed from the rules',
            }).then(() => groups.refetch()).catch(() => {}),
          },
          {
            label: 'Delete', icon: 'trash', danger: true, disabled: busy || r.is_system,
            confirm: `Group ${r.name} is deleted and its members un-grouped.`,
            onClick: () => run({ method: 'DELETE', path: `/customer-groups/${r.id}`, label: `Delete ${r.name}`, success: 'Group deleted' })
              .then(() => groups.refetch()).catch(() => {}),
          },
        ]}
        columns={[
          { key: 'name', header: 'Group', render: (r) => (
            <span className="font-medium">{r.name}{r.is_system && <span className="ml-2 text-xs text-muted">built in</span>}</span>
          ) },
          { key: 'kind', header: 'Membership', render: (r) => <Badge tone={r.kind === 'dynamic' ? 'b' : 'n'}>{r.kind}</Badge> },
          { key: 'members', header: 'Customers', render: (r) => <span className="tabular-nums">{r.members}</span> },
          { key: 'discount_percent', header: 'Discount', render: (r) => r.discount_percent ? `${r.discount_percent}%` : '—' },
          { key: 'description', header: 'Notes', render: (r) => <span className="text-muted">{r.description ?? '—'}</span> },
        ]} />

      <p className="mt-3 text-xs text-muted">
        A dynamic group is refilled from its rules each night, and on demand from the row's menu; a customer added
        by hand stays whatever the rules say. Where two of a customer's groups carry a discount, the larger one
        applies, and it reaches the invoice as a line of its own.
      </p>

      <Drawer open={!!form} onClose={() => setForm(null)} title="New customer group">
        {form && (
          <div className="grid gap-3">
            <Field label="Name"><input className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label="Membership">
              <Select className="input" value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                <option value="static">Filled by hand</option>
                <option value="dynamic">By rule (set the rules after creating it)</option>
              </Select>
            </Field>
            <Field label="Discount %" hint="Applied by Finance when this customer is billed.">
              <input className="input" type="number" min={0} max={100} value={form.discount_percent}
                onChange={(e) => setForm({ ...form, discount_percent: Number(e.target.value) })} />
            </Field>
            <Field label="Notes"><input className="input" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
            <div className="flex justify-end gap-2">
              <button type="button" className="btn" onClick={() => setForm(null)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={busy || !form.name} onClick={create}>Create group</button>
            </div>
          </div>
        )}
      </Drawer>

      <Drawer open={!!edit} onClose={() => setEdit(null)} title={edit?.name ?? 'Group'}>
        {edit && (
          <div className="grid gap-3">
            {edit.is_system && <p className="text-xs text-muted">A built-in group keeps its name and membership kind; its pricing and notes are yours.</p>}
            <Field label="Discount %">
              <input className="input" type="number" min={0} max={100} value={edit.discount_percent}
                onChange={(e) => setEdit({ ...edit, discount_percent: Number(e.target.value) })} />
            </Field>
            <Field label="Notes">
              <input className="input" value={edit.description ?? ''} onChange={(e) => setEdit({ ...edit, description: e.target.value })} />
            </Field>

            {edit.kind === 'dynamic' && (
              <>
                <Field label="Membership rules" hint="Checked against every account that is not archived.">
                  <RuleBuilder rules={rules} fields={fields.item} onChange={(r) => { setRules(r); setPreview(null) }} />
                </Field>
                <div className="flex items-center gap-2">
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={runPreview}>Preview matches</button>
                  {edit.rules_run_at && (
                    <span className="text-xs text-muted">
                      last run {new Date(edit.rules_run_at).toLocaleString()} · {edit.rules_matched ?? 0} matched
                    </span>
                  )}
                </div>
                {ruleError && <p className="text-sm text-danger">{ruleError}</p>}
                {preview && (
                  <Card title={`${preview.total} customer${preview.total === 1 ? '' : 's'} would match`}
                    subtitle={preview.total > preview.sample.length ? `showing the first ${preview.sample.length}` : undefined}>
                    {preview.sample.length === 0 ? <Empty>Nothing matches these rules yet.</Empty> : (
                      <ul className="m-0 grid gap-1 p-0 text-sm [list-style:none]">
                        {preview.sample.map((r) => (
                          <li key={r.id} className="flex items-center justify-between gap-2">
                            <span>{r.name}</span>
                            <span className="text-xs text-muted">{r.customer_type} · {r.active_services} service{r.active_services === 1 ? '' : 's'}</span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </Card>
                )}
                <p className="text-xs text-muted">Saving the rules does not move anyone; use “Recompute members” for that.</p>
              </>
            )}

            <div className="flex justify-end gap-2">
              <button type="button" className="btn" onClick={() => setEdit(null)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={busy} onClick={saveEdit}>Save group</button>
            </div>
          </div>
        )}
      </Drawer>
    </>
  )
}

/* ---------------------------------------------------------------- contacts */

type Contact = {
  id: string; organization_id: string; name: string; email: string; phone: string | null
  role: string; is_primary: boolean; notes: string | null
}

export function CustomerContacts() {
  const [role, setRole] = useState('all')
  const customers = useList<Customer>('/customer-accounts', { per_page: 500 })
  const [org, setOrg] = useState('')
  const contacts = useList<Contact>(org ? `/customer-accounts/${org}/contacts` : null)
  const { run, busy } = useAction()
  const [form, setForm] = useState<Partial<Contact> | null>(null)

  const rows = (contacts.rows ?? []).filter((c) => role === 'all' || c.role === role)

  const save = async () => {
    if (!form || !org) return
    const editing = !!form.id
    await run({
      method: editing ? 'PATCH' : 'POST',
      path: editing ? `/contacts/${form.id}` : `/customer-accounts/${org}/contacts`,
      body: { name: form.name, email: form.email, phone: form.phone, role: form.role, is_primary: !!form.is_primary, notes: form.notes },
      label: editing ? 'Save contact' : 'Add contact', success: editing ? 'Contact saved' : 'Contact added',
    }).then(() => { setForm(null); contacts.refetch() }).catch(() => {})
  }

  return (
    <>
      <PageHeader title="Contacts" subtitle="The people attached to an account, with or without a login"
        actions={<button type="button" className="btn btn-primary" disabled={!org}
          onClick={() => setForm({ role: 'billing', is_primary: false })}>Add contact</button>} />

      <Filters>
        <Field label="Customer">
          <Select className="input" value={org} onChange={(e) => setOrg(e.target.value)}>
            <option value="">Choose a customer…</option>
            {customers.rows.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </Select>
        </Field>
        <Field label="Role">
          <Select className="input" value={role} onChange={(e) => setRole(e.target.value)}>
            <option value="all">Any role</option>
            {['owner', 'admin', 'billing', 'technical', 'abuse'].map((r) => <option key={r} value={r}>{r}</option>)}
          </Select>
        </Field>
      </Filters>

      {!org ? <Empty icon="users">Choose a customer to see and edit its contacts.</Empty> : (
        <DataTable label="Contacts" rows={rows} loading={contacts.isLoading} rowKey={(r) => r.id}
          onRowClick={(r) => setForm(r)} empty="This customer has no contacts recorded."
          actions={(r) => [
            { label: 'Edit', icon: 'pencil', onClick: () => setForm(r) },
            {
              label: 'Remove', icon: 'trash', danger: true, disabled: busy,
              confirm: `${r.name} is removed from this account's contacts.`,
              onClick: () => run({ method: 'DELETE', path: `/contacts/${r.id}`, label: `Remove ${r.name}`, success: 'Contact removed' })
                .then(() => contacts.refetch()).catch(() => {}),
            },
          ]}
          columns={[
            { key: 'name', header: 'Name', render: (r) => (
              <span className="flex items-center gap-2 font-medium">{r.name}{r.is_primary && <Badge tone="g">primary</Badge>}</span>
            ) },
            { key: 'role', header: 'Role', render: (r) => <Badge tone="b">{r.role}</Badge> },
            { key: 'email', header: 'Email', render: (r) => <span className="text-muted">{r.email}</span> },
            { key: 'phone', header: 'Phone', render: (r) => r.phone ?? '—' },
            { key: 'notes', header: 'Notes', render: (r) => <span className="text-muted">{r.notes ?? '—'}</span> },
          ]} />
      )}

      <Drawer open={!!form} onClose={() => setForm(null)} title={form?.id ? form.name ?? 'Contact' : 'Add contact'}>
        {form && (
          <div className="grid gap-3">
            <Field label="Name"><input className="input" value={form.name ?? ''} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label="Email"><input className="input" type="email" value={form.email ?? ''} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
            <Field label="Phone"><input className="input" value={form.phone ?? ''} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></Field>
            <Field label="Role">
              <Select className="input" value={form.role ?? 'billing'} onChange={(e) => setForm({ ...form, role: e.target.value })}>
                {['owner', 'admin', 'billing', 'technical', 'abuse'].map((r) => <option key={r} value={r}>{r}</option>)}
              </Select>
            </Field>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={!!form.is_primary} onChange={(e) => setForm({ ...form, is_primary: e.target.checked })} />
              Primary contact for this account
            </label>
            <Field label="Notes"><input className="input" value={form.notes ?? ''} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
            <div className="flex justify-end gap-2">
              <button type="button" className="btn" onClick={() => setForm(null)}>Cancel</button>
              <button type="button" className="btn btn-primary" disabled={busy || !form.name || !form.email} onClick={save}>
                {form.id ? 'Save contact' : 'Add contact'}
              </button>
            </div>
          </div>
        )}
      </Drawer>
    </>
  )
}

export { RangePicker }
