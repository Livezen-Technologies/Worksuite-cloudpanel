import { useState } from 'react'
import { useAction, useList } from '../../api/hooks'
import { Card, Field, PageHeader, Empty } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import Select from '../../components/Select'

type Plan = {
  id: string; code: string; name: string; vcpu: number; ram_mb: number; storage_gb: number
  price_cents: number; currency: string; sort: number; subscribers: number
  limits: Record<string, number>
}
type Customer = {
  id: string; name: string; slug: string; billing_email: string | null
  plan: { id: string; code: string; name: string } | null
  subscription_status: string | null; users: number
}

const money = (cents: number, ccy: string) =>
  `${ccy} ${(cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`

const BLANK = {
  code: '', name: '', vcpu: 1, ram_mb: 2048, storage_gb: 20, price_cents: 0, currency: 'LKR', sort: 0,
  apps: 5, bandwidth_gb: 200, backups_per_day: 1, backup_retention_days: 7,
}

/* ------------------------------------------------------------------ packages */

export function Packages() {
  const plans = useList<Plan>('/plans')
  const { run, busy } = useAction()
  const [form, setForm] = useState<typeof BLANK & { id?: string } | null>(null)

  const edit = (p: Plan) => setForm({
    id: p.id, code: p.code, name: p.name, vcpu: p.vcpu, ram_mb: p.ram_mb, storage_gb: p.storage_gb,
    price_cents: p.price_cents, currency: p.currency, sort: p.sort,
    apps: p.limits?.apps ?? 0, bandwidth_gb: p.limits?.bandwidth_gb ?? 0,
    backups_per_day: p.limits?.backups_per_day ?? 0, backup_retention_days: p.limits?.backup_retention_days ?? 0,
  })

  const save = async () => {
    if (!form) return
    const { id, apps, bandwidth_gb, backups_per_day, backup_retention_days, ...rest } = form
    const body = { ...rest, limits: { apps, bandwidth_gb, backups_per_day, backup_retention_days, ram_mb: form.ram_mb, storage_gb: form.storage_gb } }
    const ok = await run({
      method: id ? 'PATCH' : 'POST', path: id ? `/plans/${id}` : '/plans', body,
      label: id ? `Update ${form.name}` : `Create ${form.name}`, success: 'Package saved',
    }).catch(() => null)
    if (ok) setForm(null)
  }

  const num = (k: keyof typeof BLANK) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => f && ({ ...f, [k]: Number(e.target.value) }))

  return (
    <>
      <PageHeader title="Packages" subtitle="Resource envelopes sold to hosting customers"
        actions={<button type="button" className="btn btn-primary" onClick={() => setForm({ ...BLANK })}>New package</button>} />

      {form && (
        <Card title={form.id ? `Edit ${form.name || 'package'}` : 'New package'}>
          <form className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]"
                onSubmit={(e) => { e.preventDefault(); save() }}>
            <Field label="Code" hint="Lowercase, used in APIs. Cannot clash.">
              <input className="input font-mono text-xs" required value={form.code} placeholder="starter-lk"
                     onChange={(e) => setForm({ ...form, code: e.target.value })} disabled={!!form.id} />
            </Field>
            <Field label="Name"><input className="input" required value={form.name} placeholder="Starter"
                     onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label="Price (minor units)" hint="1500 = 15.00">
              <input className="input" type="number" min={0} value={form.price_cents} onChange={num('price_cents')} /></Field>
            <Field label="Currency">
              <Select className="input" value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
                {['LKR', 'USD', 'EUR', 'GBP', 'AED'].map((c) => <option key={c}>{c}</option>)}
              </Select>
            </Field>
            <Field label="vCPU"><input className="input" type="number" step="0.1" min={0.1} value={form.vcpu}
                     onChange={(e) => setForm({ ...form, vcpu: Number(e.target.value) })} /></Field>
            <Field label="RAM (MB)"><input className="input" type="number" min={128} value={form.ram_mb} onChange={num('ram_mb')} /></Field>
            <Field label="Storage (GB)"><input className="input" type="number" min={1} value={form.storage_gb} onChange={num('storage_gb')} /></Field>
            <Field label="Max applications"><input className="input" type="number" min={0} value={form.apps} onChange={num('apps')} /></Field>
            <Field label="Bandwidth (GB/month)"><input className="input" type="number" min={0} value={form.bandwidth_gb} onChange={num('bandwidth_gb')} /></Field>
            <Field label="Backups per day"><input className="input" type="number" min={0} value={form.backups_per_day} onChange={num('backups_per_day')} /></Field>
            <Field label="Backup retention (days)"><input className="input" type="number" min={0} value={form.backup_retention_days} onChange={num('backup_retention_days')} /></Field>
            <Field label="Sort order"><input className="input" type="number" min={0} value={form.sort} onChange={num('sort')} /></Field>
            <div className="col-span-full flex justify-end gap-2">
              <button type="button" className="btn" onClick={() => setForm(null)}>Cancel</button>
              <button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save package'}</button>
            </div>
          </form>
        </Card>
      )}

      {!plans.isLoading && plans.rows.length === 0 && <Empty>No packages yet. Create one to start selling hosting.</Empty>}
      <DataTable label="Packages" rows={plans.rows} loading={plans.isLoading} rowKey={(r) => r.id}
        columns={[
          { key: 'name', header: 'Package', render: (r) => <span className="font-semibold">{r.name}</span> },
          { key: 'code', header: 'Code', render: (r) => <span className="font-mono text-xs text-muted">{r.code}</span> },
          { key: 'price_cents', header: 'Price', value: (r) => r.price_cents, render: (r) => <span className="tabular-nums">{money(r.price_cents, r.currency)}</span> },
          { key: 'vcpu', header: 'vCPU', render: (r) => <span className="tabular-nums">{r.vcpu}</span> },
          { key: 'ram_mb', header: 'RAM', render: (r) => <span className="tabular-nums">{(r.ram_mb / 1024).toFixed(1)} GB</span> },
          { key: 'storage_gb', header: 'Storage', render: (r) => <span className="tabular-nums">{r.storage_gb} GB</span> },
          { key: 'apps', header: 'Apps', value: (r) => r.limits?.apps ?? 0 },
          { key: 'subscribers', header: 'Customers', render: (r) => <Badge tone={r.subscribers ? 'b' : 'n'}>{r.subscribers}</Badge> },
          {
            key: 'actions', header: '', sortable: false, render: (r) => (
              <span className="flex gap-2">
                <button type="button" className="btn btn-sm" onClick={() => edit(r)}>Edit</button>
                <button type="button" className="btn btn-sm" disabled={busy || r.subscribers > 0}
                        title={r.subscribers > 0 ? 'Move its customers to another package first' : 'Delete package'}
                        onClick={() => run({ method: 'DELETE', path: `/plans/${r.id}`, label: `Delete ${r.name}`, success: 'Package deleted' }).catch(() => {})}>
                  Delete
                </button>
              </span>
            ),
          },
        ]} />
    </>
  )
}

/* ----------------------------------------------------------------- customers */

const NEW_CUSTOMER = { name: '', billing_email: '', plan_id: '', owner_name: '', owner_email: '', owner_password: '' }

export function Customers() {
  const customers = useList<Customer>('/customers')
  const plans = useList<Plan>('/plans')
  const { run, busy } = useAction()
  const [form, setForm] = useState<typeof NEW_CUSTOMER | null>(null)

  const create = async () => {
    if (!form) return
    const ok = await run({ path: '/customers', body: form, label: `Create ${form.name}`, success: 'Customer created' }).catch(() => null)
    if (ok) setForm(null)
  }

  return (
    <>
      <PageHeader title="Hosting customers" subtitle="Tenants, their owner account and the package they are on"
        actions={<button type="button" className="btn btn-primary" disabled={plans.rows.length === 0}
                         title={plans.rows.length === 0 ? 'Create a package first' : undefined}
                         onClick={() => setForm({ ...NEW_CUSTOMER, plan_id: plans.rows[0]?.id ?? '' })}>New customer</button>} />

      {form && (
        <Card title="New hosting customer">
          <form className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]"
                onSubmit={(e) => { e.preventDefault(); create() }}>
            <Field label="Company name"><input className="input" required value={form.name}
                     onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Acme Lanka (Pvt) Ltd" /></Field>
            <Field label="Billing email"><input className="input" type="email" required value={form.billing_email}
                     onChange={(e) => setForm({ ...form, billing_email: e.target.value })} /></Field>
            <Field label="Package">
              <Select className="input" value={form.plan_id} onChange={(e) => setForm({ ...form, plan_id: e.target.value })}>
                {plans.rows.map((p) => <option key={p.id} value={p.id}>{p.name} — {money(p.price_cents, p.currency)}</option>)}
              </Select>
            </Field>
            <Field label="Owner name"><input className="input" required value={form.owner_name}
                     onChange={(e) => setForm({ ...form, owner_name: e.target.value })} /></Field>
            <Field label="Owner email" hint="They sign in to the customer panel at /app.">
              <input className="input" type="email" required value={form.owner_email}
                     onChange={(e) => setForm({ ...form, owner_email: e.target.value })} /></Field>
            <Field label="Owner password" hint="At least 12 characters. Share it securely; they should change it.">
              <input className="input" type="password" required minLength={12} value={form.owner_password}
                     onChange={(e) => setForm({ ...form, owner_password: e.target.value })} /></Field>
            <div className="col-span-full flex justify-end gap-2">
              <button type="button" className="btn" onClick={() => setForm(null)}>Cancel</button>
              <button className="btn btn-primary" disabled={busy}>{busy ? 'Creating…' : 'Create customer'}</button>
            </div>
          </form>
        </Card>
      )}

      {!customers.isLoading && customers.rows.length === 0 && <Empty>No hosting customers yet.</Empty>}
      <DataTable label="Customers" rows={customers.rows} loading={customers.isLoading} rowKey={(r) => r.id}
        columns={[
          { key: 'name', header: 'Customer', render: (r) => <span className="font-semibold">{r.name}</span> },
          { key: 'slug', header: 'Slug', render: (r) => <span className="font-mono text-xs text-muted">{r.slug}</span> },
          { key: 'billing_email', header: 'Billing email', value: (r) => r.billing_email ?? '—' },
          { key: 'users', header: 'Users', render: (r) => <span className="tabular-nums">{r.users}</span> },
          {
            key: 'status', header: 'Status', render: (r) =>
              <Badge tone={r.subscription_status === 'active' ? 'g' : 'n'}>{r.subscription_status ?? 'none'}</Badge>,
          },
          {
            key: 'plan', header: 'Package', sortable: false, value: (r) => r.plan?.name ?? '—',
            render: (r) => (
              <Select className="input !w-auto !min-h-8 text-xs" value={r.plan?.id ?? ''}
                      aria-label={`Package for ${r.name}`}
                      onChange={(e) => run({ path: `/customers/${r.id}/plan`, body: { plan_id: e.target.value }, label: `Move ${r.name}`, success: 'Package changed' }).catch(() => {})}>
                {!r.plan && <option value="">Choose…</option>}
                {plans.rows.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </Select>
            ),
          },
        ]} />
    </>
  )
}
