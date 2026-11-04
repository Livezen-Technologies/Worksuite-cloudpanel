import { useState } from 'react'
import { useList, useOne, useAction } from '../../api/hooks'
import { Card, Field, PageHeader, Empty, StatTile } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { Icon } from '../../components/Icon'
import { DataTable } from '../../components/DataTable'
import { Drawer, Modal } from '../../components/Overlay'
import Select from '../../components/Select'
import { money, when, Status } from './Finance'

/** 'all' is the no-filter choice in these selects, so it must not reach the API. */
const unlessAll = (v: string) => (v === 'all' ? undefined : v)

/** FIN-3..6: the ledger screens -- invoices, payments, refunds, subscriptions, transactions. */

type Customer = { id: string; name: string }
type Plan = { id: string; name: string; code: string; price_cents: number; currency: string }

type Invoice = {
  id: string; number: string; kind: string; organization_id: string; customer: string | null
  subtotal_cents: number; tax_cents: number; amount_cents: number; refunded_cents: number
  currency: string; status: string; period_start: string | null; period_end: string | null
  due_at: string | null; paid_at: string | null; collection_attempts: number; created_at: string | null
}
type InvoiceDetail = Invoice & {
  line_items: { description: string; quantity: number; unit_cents: number; amount_cents: number }[]
  refundable_cents: number
  payments: { id: string; amount_cents: number; currency: string; gateway: string; method: string; status: string; paid_at: string | null }[]
  billing_profile: Record<string, string | null> | null
}

/** A filter bar that keeps its state in one object, so adding a filter is one line. */
function Filters({ children }: { children: React.ReactNode }) {
  return <div className="mb-3 flex flex-wrap items-end gap-2">{children}</div>
}

/* ----------------------------------------------------------------- invoices */

const BLANK_INVOICE = {
  organization_id: '', currency: 'EUR', due_at: '', status: 'draft' as 'draft' | 'open',
  items: [{ description: '', quantity: 1, unit_cents: 0 }], discount_cents: 0,
}

export function FinanceInvoices() {
  const [status, setStatus] = useState('all')
  const [search, setSearch] = useState('')
  const invoices = useList<Invoice>('/finance/invoices', { status: unlessAll(status), search: search || undefined })
  const customers = useList<Customer>('/customers')
  const { run, busy } = useAction()
  const [open, setOpen] = useState<string | null>(null)
  const [form, setForm] = useState<typeof BLANK_INVOICE | null>(null)
  const [pay, setPay] = useState<Invoice | null>(null)

  const detail = useOne<InvoiceDetail>(open ? `/finance/invoices/${open}` : null)

  const create = async () => {
    if (!form) return
    const ok = await run({
      method: 'POST', path: '/finance/invoices',
      body: { ...form, due_at: form.due_at || null },
      label: 'Create invoice', success: 'Invoice created',
    }).catch(() => null)
    if (ok) { setForm(null); invoices.refetch() }
  }

  const total = (form?.items ?? []).reduce((n, i) => n + Math.round(i.quantity * i.unit_cents), 0) - (form?.discount_cents ?? 0)

  return (
    <>
      <PageHeader title="Invoices" subtitle="Every customer's invoices and credit notes"
        actions={<button type="button" className="btn btn-primary" onClick={() => setForm({ ...BLANK_INVOICE, items: [{ description: '', quantity: 1, unit_cents: 0 }] })}>New invoice</button>} />

      <Filters>
        <Field label="Status">
          <Select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
            {['all', 'draft', 'open', 'partially_paid', 'paid', 'overdue', 'void', 'refunded'].map((s) =>
              <option key={s} value={s}>{s === 'all' ? 'Any status' : s.replace(/_/g, ' ')}</option>)}
          </Select>
        </Field>
        <Field label="Search">
          <input className="input w-auto" placeholder="Invoice number" value={search} onChange={(e) => setSearch(e.target.value)} />
        </Field>
      </Filters>

      <DataTable label="Invoices" rows={invoices.rows} loading={invoices.isLoading} rowKey={(r) => r.id}
        onRowClick={(r) => setOpen(r.id)} empty="No invoices match."
        actions={(r) => [
          { label: 'Open', icon: 'eye', onClick: () => setOpen(r.id) },
          { label: 'Record a payment', icon: 'wallet', disabled: r.status === 'paid' || r.kind === 'credit_note', onClick: () => setPay(r) },
          { label: 'Send to the customer', icon: 'share', disabled: busy, onClick: () => run({ method: 'POST', path: `/finance/invoices/${r.id}/send`, label: `Send ${r.number}`, success: 'Invoice sent' }).then(() => invoices.refetch()).catch(() => {}) },
          { label: 'Send a reminder', icon: 'bell', disabled: busy || r.status === 'paid', onClick: () => run({ method: 'POST', path: `/finance/invoices/${r.id}/remind`, label: `Remind ${r.number}`, success: 'Reminder sent' }).catch(() => {}) },
          { label: 'Void', icon: 'trash', danger: true, disabled: busy || !['draft', 'open'].includes(r.status), confirm: `Invoice ${r.number} is voided.`, onClick: () => run({ method: 'PATCH', path: `/finance/invoices/${r.id}`, body: { status: 'void' }, label: `Void ${r.number}`, success: 'Invoice voided' }).then(() => invoices.refetch()).catch(() => {}) },
        ]}
        columns={[
          { key: 'number', header: 'Number', render: (r) => <span className="font-mono text-xs font-semibold">{r.number}</span> },
          { key: 'customer', header: 'Customer', render: (r) => r.customer ?? <span className="text-muted">—</span> },
          { key: 'kind', header: 'Kind', render: (r) => (r.kind === 'credit_note' ? <Badge tone="b">credit note</Badge> : <span className="text-muted text-xs">invoice</span>) },
          { key: 'amount_cents', header: 'Amount', value: (r) => r.amount_cents, render: (r) => <span className="tabular-nums font-medium">{money(r.amount_cents, r.currency)}</span> },
          { key: 'status', header: 'Status', render: (r) => <Status value={r.status} /> },
          { key: 'due_at', header: 'Due', render: (r) => <span className="text-xs">{when(r.due_at)}</span> },
          { key: 'created_at', header: 'Raised', render: (r) => <span className="text-xs text-muted">{when(r.created_at)}</span> },
        ]} />

      {/* ---- the invoice itself */}
      {open && (
        <Drawer open onClose={() => setOpen(null)} width={640} title={detail.item?.number ?? 'Invoice'}
          footer={<>
            <button type="button" className="btn" onClick={() => setOpen(null)}>Close</button>
            <a className="btn btn-primary" href={`/api/v1/finance/invoices/${open}/pdf`}>Download PDF</a>
          </>}>
          {!detail.item ? <Empty icon="file">Loading…</Empty> : (
            <div className="grid gap-4">
              <div className="grid gap-3 sm:grid-cols-3">
                <StatTile label="Total" value={money(detail.item.amount_cents, detail.item.currency)} />
                <StatTile label="Refunded" value={money(detail.item.refunded_cents, detail.item.currency)} />
                <StatTile label="Still refundable" value={money(detail.item.refundable_cents, detail.item.currency)} />
              </div>

              <Card title="Billed to" pad>
                <div className="text-sm">
                  <div className="font-medium">{detail.item.customer}</div>
                  {detail.item.billing_profile && Object.entries(detail.item.billing_profile)
                    .filter(([, v]) => v)
                    .map(([k, v]) => <div key={k} className="text-muted">{v}</div>)}
                </div>
              </Card>

              <Card title="Lines" pad={false}>
                <DataTable label="Invoice lines" rows={detail.item.line_items ?? []} rowKey={(r) => `${r.description}:${r.amount_cents}`}
                  empty="This invoice has no itemised lines."
                  columns={[
                    { key: 'description', header: 'Description' },
                    { key: 'quantity', header: 'Qty', render: (r) => <span className="tabular-nums">{r.quantity}</span> },
                    { key: 'unit_cents', header: 'Unit', render: (r) => <span className="tabular-nums">{money(r.unit_cents, detail.item!.currency)}</span> },
                    { key: 'amount_cents', header: 'Amount', render: (r) => <span className="tabular-nums">{money(r.amount_cents, detail.item!.currency)}</span> },
                  ]} />
              </Card>

              <Card title="Payments against it" pad={false}>
                <DataTable label="Payments" rows={detail.item.payments} rowKey={(r) => r.id} empty="Nothing has been paid yet."
                  columns={[
                    { key: 'paid_at', header: 'Paid', render: (r) => <span className="text-xs">{when(r.paid_at)}</span> },
                    { key: 'amount_cents', header: 'Amount', render: (r) => <span className="tabular-nums">{money(r.amount_cents, r.currency)}</span> },
                    { key: 'gateway', header: 'Gateway', render: (r) => <span className="capitalize">{r.gateway}</span> },
                    { key: 'status', header: 'Status', render: (r) => <Status value={r.status} /> },
                  ]} />
              </Card>
            </div>
          )}
        </Drawer>
      )}

      {/* ---- record a payment */}
      {pay && <RecordPayment invoice={pay} onClose={() => setPay(null)} onDone={() => { setPay(null); invoices.refetch() }} />}

      {/* ---- a manual invoice */}
      {form && (
        <Drawer open onClose={() => setForm(null)} width={620} title="New invoice"
          footer={<>
            <button type="button" className="btn" onClick={() => setForm(null)}>Cancel</button>
            <button type="submit" form="invoice-form" className="btn btn-primary" disabled={busy || !form.organization_id}>{busy ? 'Creating…' : 'Create invoice'}</button>
          </>}>
          <form id="invoice-form" className="grid gap-4" onSubmit={(e) => { e.preventDefault(); create() }}>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Customer" required>
                <Select className="input" value={form.organization_id} onChange={(e) => setForm({ ...form, organization_id: e.target.value })}>
                  <option value="">Choose a customer…</option>
                  {customers.rows.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </Select>
              </Field>
              <Field label="Currency">
                <Select className="input" value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
                  {['EUR', 'USD', 'GBP', 'LKR', 'AED', 'INR', 'AUD'].map((c) => <option key={c}>{c}</option>)}
                </Select>
              </Field>
              <Field label="Due date" hint="Left empty, the configured terms apply.">
                <input className="input" type="date" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} />
              </Field>
              <Field label="Issue as">
                <Select className="input" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value as 'draft' | 'open' })}>
                  <option value="draft">Draft — not visible to the customer</option>
                  <option value="open">Open — payable now</option>
                </Select>
              </Field>
            </div>

            <Card title="Lines" pad>
              <div className="grid gap-2">
                {form.items.map((it, i) => (
                  <div key={i} className="grid grid-cols-[1fr_5rem_7rem_2rem] items-end gap-2">
                    <Field label={i === 0 ? 'Description' : ''}>
                      <input className="input" required value={it.description} placeholder="Setup fee"
                        onChange={(e) => setForm({ ...form, items: form.items.map((x, j) => j === i ? { ...x, description: e.target.value } : x) })} />
                    </Field>
                    <Field label={i === 0 ? 'Qty' : ''}>
                      <input className="input" type="number" step="0.01" min={0.01} value={it.quantity}
                        onChange={(e) => setForm({ ...form, items: form.items.map((x, j) => j === i ? { ...x, quantity: Number(e.target.value) } : x) })} />
                    </Field>
                    <Field label={i === 0 ? 'Unit (minor)' : ''}>
                      <input className="input" type="number" value={it.unit_cents}
                        onChange={(e) => setForm({ ...form, items: form.items.map((x, j) => j === i ? { ...x, unit_cents: Number(e.target.value) } : x) })} />
                    </Field>
                    <button type="button" className="btn btn-icon" aria-label={`Remove line ${i + 1}`} disabled={form.items.length === 1}
                      onClick={() => setForm({ ...form, items: form.items.filter((_, j) => j !== i) })}>×</button>
                  </div>
                ))}
                <div className="flex items-center justify-between pt-1">
                  <button type="button" className="btn btn-sm"
                    onClick={() => setForm({ ...form, items: [...form.items, { description: '', quantity: 1, unit_cents: 0 }] })}>Add a line</button>
                  <div className="text-sm">Total before tax <span className="font-semibold tabular-nums">{money(Math.max(0, total), form.currency)}</span></div>
                </div>
              </div>
            </Card>

            <Field label="Discount (minor units)">
              <input className="input" type="number" min={0} value={form.discount_cents}
                onChange={(e) => setForm({ ...form, discount_cents: Number(e.target.value) })} />
            </Field>
          </form>
        </Drawer>
      )}
    </>
  )
}

function RecordPayment({ invoice, onClose, onDone }: { invoice: Invoice; onClose: () => void; onDone: () => void }) {
  const { run, busy } = useAction()
  const outstanding = invoice.amount_cents - invoice.refunded_cents
  const [form, setForm] = useState({ amount_cents: outstanding, method: 'bank_transfer', gateway_reference: '', paid_at: '', notes: '' })

  const submit = async () => {
    const ok = await run({
      method: 'POST', path: `/finance/invoices/${invoice.id}/payments`,
      body: { ...form, gateway: 'manual', paid_at: form.paid_at || null, gateway_reference: form.gateway_reference || null },
      label: `Record a payment on ${invoice.number}`, success: 'Payment recorded',
    }).catch(() => null)
    if (ok) onDone()
  }

  return (
    <Modal open onClose={onClose} title={`Record a payment — ${invoice.number}`}
      footer={<>
        <button type="button" className="btn" onClick={onClose}>Cancel</button>
        <button type="submit" form="pay-form" className="btn btn-primary" disabled={busy}>{busy ? 'Recording…' : 'Record payment'}</button>
      </>}>
      <form id="pay-form" className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); submit() }}>
        <Field label="Amount (minor units)" hint={`${money(outstanding, invoice.currency)} outstanding.`}>
          <input className="input" type="number" min={1} required value={form.amount_cents}
            onChange={(e) => setForm({ ...form, amount_cents: Number(e.target.value) })} />
        </Field>
        <Field label="Method">
          <Select className="input" value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })}>
            {['bank_transfer', 'cash', 'cheque', 'card', 'paypal', 'credit'].map((m) => <option key={m} value={m}>{m.replace(/_/g, ' ')}</option>)}
          </Select>
        </Field>
        <Field label="Received on"><input className="input" type="date" value={form.paid_at} onChange={(e) => setForm({ ...form, paid_at: e.target.value })} /></Field>
        <Field label="Reference" hint="Bank reference, cheque number…">
          <input className="input" value={form.gateway_reference} onChange={(e) => setForm({ ...form, gateway_reference: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <Field label="Notes"><textarea className="input" rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
        </div>
      </form>
    </Modal>
  )
}

/* ----------------------------------------------------------------- payments */

type Payment = {
  id: string; organization_id: string; customer: string | null
  invoice_id: string | null; invoice_number: string | null
  amount_cents: number; refunded_cents: number; currency: string
  gateway: string; method: string; status: string
  gateway_reference: string | null; paid_at: string | null; created_at: string | null
}

export function FinancePayments() {
  const [status, setStatus] = useState('all')
  const [gateway, setGateway] = useState('all')
  const payments = useList<Payment>('/finance/payments', { status: unlessAll(status), gateway: unlessAll(gateway) })
  const customers = useList<Customer>('/customers')
  const { run, busy } = useAction()
  const [form, setForm] = useState<{ organization_id: string; amount_cents: number; currency: string; method: string; gateway: string; gateway_reference: string; notes: string } | null>(null)

  const create = async () => {
    if (!form) return
    const ok = await run({
      method: 'POST', path: '/finance/payments',
      body: { ...form, gateway_reference: form.gateway_reference || null },
      label: 'Record a payment', success: 'Payment recorded',
    }).catch(() => null)
    if (ok) { setForm(null); payments.refetch() }
  }

  return (
    <>
      <PageHeader title="Payments" subtitle="Everything collected, through a gateway or at the bank"
        actions={<button type="button" className="btn btn-primary"
          onClick={() => setForm({ organization_id: '', amount_cents: 0, currency: 'EUR', method: 'bank_transfer', gateway: 'manual', gateway_reference: '', notes: '' })}>
          Record a payment
        </button>} />

      <Filters>
        <Field label="Status">
          <Select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
            {['all', 'succeeded', 'pending', 'failed', 'disputed', 'refunded', 'partially_refunded'].map((s) =>
              <option key={s} value={s}>{s === 'all' ? 'Any status' : s.replace(/_/g, ' ')}</option>)}
          </Select>
        </Field>
        <Field label="Gateway">
          <Select className="input w-auto" value={gateway} onChange={(e) => setGateway(e.target.value)}>
            {['all', 'stripe', 'paypal', 'manual', 'fake'].map((g) => <option key={g} value={g}>{g === 'all' ? 'Any gateway' : g}</option>)}
          </Select>
        </Field>
      </Filters>

      <DataTable label="Payments" rows={payments.rows} loading={payments.isLoading} rowKey={(r) => r.id}
        empty="No payments match."
        actions={(r) => [
          { label: 'Confirm it arrived', icon: 'check', disabled: busy || r.status === 'succeeded', onClick: () => run({ method: 'POST', path: `/finance/payments/${r.id}/reconcile`, body: { status: 'succeeded' }, label: 'Confirm payment', success: 'Payment confirmed' }).then(() => payments.refetch()).catch(() => {}) },
          { label: 'Mark it failed', icon: 'close', danger: true, disabled: busy || r.status === 'failed', confirm: 'The payment is marked failed and its invoice re-opened.', onClick: () => run({ method: 'POST', path: `/finance/payments/${r.id}/reconcile`, body: { status: 'failed' }, label: 'Fail payment', success: 'Payment marked failed' }).then(() => payments.refetch()).catch(() => {}) },
        ]}
        columns={[
          { key: 'paid_at', header: 'Paid', render: (r) => <span className="text-xs">{when(r.paid_at ?? r.created_at)}</span> },
          { key: 'customer', header: 'Customer', render: (r) => r.customer ?? <span className="text-muted">—</span> },
          { key: 'invoice_number', header: 'Invoice', render: (r) => <span className="font-mono text-xs">{r.invoice_number ?? '—'}</span> },
          { key: 'amount_cents', header: 'Amount', value: (r) => r.amount_cents, render: (r) => <span className="tabular-nums font-medium">{money(r.amount_cents, r.currency)}</span> },
          { key: 'gateway', header: 'Gateway', render: (r) => <span className="capitalize">{r.gateway}</span> },
          { key: 'method', header: 'Method', render: (r) => <span className="text-xs text-muted">{r.method.replace(/_/g, ' ')}</span> },
          { key: 'status', header: 'Status', render: (r) => <Status value={r.status} /> },
          { key: 'gateway_reference', header: 'Reference', render: (r) => <span className="font-mono text-[11px] text-muted">{r.gateway_reference ?? '—'}</span> },
        ]} />

      {form && (
        <Modal open onClose={() => setForm(null)} title="Record a payment"
          footer={<>
            <button type="button" className="btn" onClick={() => setForm(null)}>Cancel</button>
            <button type="submit" form="payment-form" className="btn btn-primary" disabled={busy || !form.organization_id || form.amount_cents < 1}>{busy ? 'Recording…' : 'Record'}</button>
          </>}>
          <form id="payment-form" className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); create() }}>
            <Field label="Customer" required>
              <Select className="input" value={form.organization_id} onChange={(e) => setForm({ ...form, organization_id: e.target.value })}>
                <option value="">Choose a customer…</option>
                {customers.rows.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </Select>
            </Field>
            <Field label="Amount (minor units)" required>
              <input className="input" type="number" min={1} required value={form.amount_cents} onChange={(e) => setForm({ ...form, amount_cents: Number(e.target.value) })} />
            </Field>
            <Field label="Currency">
              <Select className="input" value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
                {['EUR', 'USD', 'GBP', 'LKR', 'AED', 'INR', 'AUD'].map((c) => <option key={c}>{c}</option>)}
              </Select>
            </Field>
            <Field label="Method">
              <Select className="input" value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })}>
                {['bank_transfer', 'cash', 'cheque', 'card', 'paypal', 'credit'].map((m) => <option key={m} value={m}>{m.replace(/_/g, ' ')}</option>)}
              </Select>
            </Field>
            <div className="sm:col-span-2">
              <Field label="Reference"><input className="input" value={form.gateway_reference} onChange={(e) => setForm({ ...form, gateway_reference: e.target.value })} /></Field>
            </div>
            <div className="sm:col-span-2">
              <Field label="Notes"><textarea className="input" rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
            </div>
          </form>
        </Modal>
      )}
    </>
  )
}

/* ------------------------------------------------------------------ refunds */

type Refund = {
  id: string; organization_id: string; customer: string | null
  invoice_id: string | null; invoice_number: string | null; payment_id: string | null
  amount_cents: number; currency: string; status: string; reason: string
  gateway_reference: string | null; decided_at: string | null; refunded_at: string | null; created_at: string | null
}

export function FinanceRefunds() {
  const [status, setStatus] = useState('all')
  const refunds = useList<Refund>('/finance/refunds', { status: unlessAll(status) })
  const { run, busy } = useAction()
  const [decide, setDecide] = useState<{ row: Refund; action: 'approve' | 'reject' } | null>(null)
  const [notes, setNotes] = useState('')

  const submit = async () => {
    if (!decide) return
    const ok = await run({
      method: 'POST', path: `/finance/refunds/${decide.row.id}/${decide.action}`,
      body: { notes },
      label: decide.action === 'approve' ? 'Approve the refund' : 'Decline the refund',
      success: decide.action === 'approve' ? 'Refund sent' : 'Request declined',
    }).catch(() => null)
    if (ok) { setDecide(null); setNotes(''); refunds.refetch() }
  }

  const pending = refunds.rows.filter((r) => r.status === 'pending').length

  return (
    <>
      <PageHeader title="Refunds" subtitle="Requests to give money back, and what was decided"
        badge={pending ? <Badge tone="y">{pending} waiting</Badge> : undefined} />

      <Filters>
        <Field label="Status">
          <Select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
            {['all', 'pending', 'processing', 'refunded', 'rejected', 'failed'].map((s) =>
              <option key={s} value={s}>{s === 'all' ? 'Any status' : s}</option>)}
          </Select>
        </Field>
      </Filters>

      <DataTable label="Refund requests" rows={refunds.rows} loading={refunds.isLoading} rowKey={(r) => r.id}
        empty="No refund requests."
        actions={(r) => [
          { label: 'Approve and refund', icon: 'check', disabled: busy || !['pending', 'approved'].includes(r.status), onClick: () => { setNotes(''); setDecide({ row: r, action: 'approve' }) } },
          { label: 'Decline', icon: 'close', danger: true, disabled: busy || !['pending', 'approved'].includes(r.status), onClick: () => { setNotes(''); setDecide({ row: r, action: 'reject' }) } },
        ]}
        columns={[
          { key: 'created_at', header: 'Asked', render: (r) => <span className="text-xs">{when(r.created_at)}</span> },
          { key: 'customer', header: 'Customer', render: (r) => r.customer ?? <span className="text-muted">—</span> },
          { key: 'invoice_number', header: 'Invoice', render: (r) => <span className="font-mono text-xs">{r.invoice_number ?? '—'}</span> },
          { key: 'amount_cents', header: 'Amount', value: (r) => r.amount_cents, render: (r) => <span className="tabular-nums font-medium">{money(r.amount_cents, r.currency)}</span> },
          { key: 'reason', header: 'Reason', render: (r) => <span className="text-xs">{r.reason}</span> },
          { key: 'status', header: 'Status', render: (r) => <Status value={r.status} /> },
          { key: 'refunded_at', header: 'Settled', render: (r) => <span className="text-xs text-muted">{when(r.refunded_at)}</span> },
        ]} />

      {decide && (
        <Modal open onClose={() => setDecide(null)}
          title={decide.action === 'approve'
            ? `Refund ${money(decide.row.amount_cents, decide.row.currency)} to ${decide.row.customer ?? 'this customer'}?`
            : 'Decline this request'}
          footer={<>
            <button type="button" className="btn" onClick={() => setDecide(null)}>Cancel</button>
            <button type="button" className={`btn ${decide.action === 'approve' ? 'btn-primary' : 'btn-danger'}`} disabled={busy || (decide.action === 'reject' && !notes.trim())} onClick={submit}>
              {busy ? 'Working…' : decide.action === 'approve' ? 'Approve and send' : 'Decline'}
            </button>
          </>}>
          <div className="grid gap-3">
            <p className="text-sm text-muted">
              {decide.action === 'approve'
                ? 'The money goes back through the gateway that took it, and a credit note is written once the gateway agrees.'
                : 'The customer is told, and is sent the note below.'}
            </p>
            <Field label={decide.action === 'approve' ? 'Note (optional)' : 'Why it is declined'} required={decide.action === 'reject'}>
              <textarea className="input" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
            </Field>
          </div>
        </Modal>
      )}
    </>
  )
}

/* ------------------------------------------------------------ transactions */

type Txn = {
  id: string; kind: 'payment' | 'refund'; direction: 'in' | 'out'
  organization_id: string; customer: string | null
  amount_cents: number; currency: string; status: string
  gateway: string | null; method: string | null; reference: string | null
  invoice_id: string | null; at: string | null
}

export function FinanceTransactions() {
  const { item, isLoading } = useOne<{ rows: Txn[]; totals: { in_cents: number; out_cents: number }; currency: string }>('/finance/transactions')
  const ccy = item?.currency ?? 'EUR'

  return (
    <>
      <PageHeader title="Transactions" subtitle="Money in and money out, newest first" />

      <div className="grid gap-3 sm:grid-cols-3">
        <StatTile label="In" value={money(item?.totals.in_cents, ccy)} valueTone="g" icon={<Icon name="wallet" size={16} />} iconTone="g" />
        <StatTile label="Out" value={money(item?.totals.out_cents, ccy)} valueTone="r" icon={<Icon name="share" size={16} />} iconTone="r" />
        <StatTile label="Net" value={money((item?.totals.in_cents ?? 0) + (item?.totals.out_cents ?? 0), ccy)} icon={<Icon name="pulse" size={16} />} />
      </div>

      <DataTable label="Transactions" rows={item?.rows ?? []} loading={isLoading} rowKey={(r) => `${r.kind}:${r.id}`}
        empty="Nothing has moved yet."
        columns={[
          { key: 'at', header: 'When', render: (r) => <span className="text-xs">{when(r.at)}</span> },
          { key: 'kind', header: 'Kind', render: (r) => <Badge tone={r.kind === 'payment' ? 'g' : 'b'}>{r.kind}</Badge> },
          { key: 'customer', header: 'Customer', render: (r) => r.customer ?? <span className="text-muted">—</span> },
          { key: 'amount_cents', header: 'Amount', value: (r) => r.amount_cents,
            render: (r) => <span className={`tabular-nums font-medium ${r.amount_cents < 0 ? 'text-[var(--r-fg)]' : ''}`}>{money(r.amount_cents, r.currency)}</span> },
          { key: 'gateway', header: 'Gateway', render: (r) => <span className="capitalize">{r.gateway ?? '—'}</span> },
          { key: 'status', header: 'Status', render: (r) => <Status value={r.status} /> },
          { key: 'reference', header: 'Reference', render: (r) => <span className="font-mono text-[11px] text-muted">{r.reference ?? '—'}</span> },
        ]} />
    </>
  )
}

/* --------------------------------------------------------- subscriptions */

type Subscription = {
  id: string; organization_id: string; customer: string | null
  plan_id: string; plan: string | null; plan_code: string | null
  status: string; billing_cycle: string; discount_percent: number
  monthly_value_cents: number; currency: string; auto_renew: boolean; on_trial: boolean
  trial_ends_at: string | null; current_period_start: string | null; current_period_end: string | null
  cancelled_at: string | null; expires_at: string | null; created_at: string | null
}
type Quote = { proration_cents: number; credit_cents: number; charge_cents: number; days_remaining: number; effective_at: string | null; currency: string }

export function FinanceSubscriptions() {
  const [status, setStatus] = useState('all')
  const subs = useList<Subscription>('/finance/subscriptions', { status: unlessAll(status) })
  const plans = useList<Plan>('/plans')
  const customers = useList<Customer>('/customers')
  const { run, busy } = useAction()
  const [open, setOpen] = useState<Subscription | null>(null)
  const [form, setForm] = useState<{ organization_id: string; plan_id: string; billing_cycle: string; discount_percent: number; trial_days: number } | null>(null)

  const create = async () => {
    if (!form) return
    const ok = await run({ method: 'POST', path: '/finance/subscriptions', body: form, label: 'Create subscription', success: 'Subscription created' }).catch(() => null)
    if (ok) { setForm(null); subs.refetch() }
  }

  const act = (r: Subscription, action: string, body: Record<string, unknown> = {}) =>
    run({ method: 'POST', path: `/finance/subscriptions/${r.id}/transition`, body: { action, ...body }, label: `${action} subscription`, success: 'Subscription updated' })
      .then(() => subs.refetch()).catch(() => {})

  return (
    <>
      <PageHeader title="Subscriptions" subtitle="What every customer is on, and until when"
        actions={<button type="button" className="btn btn-primary"
          onClick={() => setForm({ organization_id: '', plan_id: '', billing_cycle: 'monthly', discount_percent: 0, trial_days: 0 })}>
          New subscription
        </button>} />

      <Filters>
        <Field label="Status">
          <Select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
            {['all', 'active', 'trialing', 'suspended', 'cancelled', 'expired'].map((s) =>
              <option key={s} value={s}>{s === 'all' ? 'Any status' : s}</option>)}
          </Select>
        </Field>
      </Filters>

      <DataTable label="Subscriptions" rows={subs.rows} loading={subs.isLoading} rowKey={(r) => r.id}
        onRowClick={(r) => setOpen(r)} empty="No subscriptions match."
        actions={(r) => [
          { label: 'Open', icon: 'eye', onClick: () => setOpen(r) },
          { label: 'Suspend', icon: 'pause', disabled: busy || r.status !== 'active', confirm: `${r.customer} is suspended.`, onClick: () => act(r, 'suspend') },
          { label: 'Resume', icon: 'play', disabled: busy || r.status !== 'suspended', onClick: () => act(r, 'resume') },
          { label: 'Renew now', icon: 'clock', disabled: busy, onClick: () => act(r, 'renew') },
          { label: 'Cancel at period end', icon: 'close', danger: true, disabled: busy || r.status === 'cancelled', confirm: `${r.customer} is cancelled at the end of the paid period.`, onClick: () => act(r, 'cancel', { at_period_end: true }) },
        ]}
        columns={[
          { key: 'customer', header: 'Customer', render: (r) => <span className="font-semibold">{r.customer ?? '—'}</span> },
          { key: 'plan', header: 'Package', render: (r) => r.plan ?? <span className="text-muted">—</span> },
          { key: 'billing_cycle', header: 'Cycle', render: (r) => <span className="text-xs">{r.billing_cycle}</span> },
          { key: 'monthly_value_cents', header: 'Per month', value: (r) => r.monthly_value_cents, render: (r) => <span className="tabular-nums">{money(r.monthly_value_cents, r.currency)}</span> },
          { key: 'discount_percent', header: 'Discount', render: (r) => (r.discount_percent ? <Badge tone="b">{r.discount_percent}%</Badge> : <span className="text-muted">—</span>) },
          { key: 'status', header: 'Status', render: (r) => <Status value={r.on_trial ? 'trialing' : r.status} /> },
          { key: 'current_period_end', header: 'Renews', render: (r) => <span className="text-xs">{r.auto_renew ? when(r.current_period_end) : 'does not renew'}</span> },
        ]} />

      {open && <SubscriptionDrawer sub={open} plans={plans.rows} onClose={() => setOpen(null)} onChanged={() => { subs.refetch(); setOpen(null) }} />}

      {form && (
        <Modal open onClose={() => setForm(null)} title="New subscription"
          footer={<>
            <button type="button" className="btn" onClick={() => setForm(null)}>Cancel</button>
            <button type="submit" form="sub-form" className="btn btn-primary" disabled={busy || !form.organization_id || !form.plan_id}>{busy ? 'Creating…' : 'Create'}</button>
          </>}>
          <form id="sub-form" className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); create() }}>
            <Field label="Customer" required>
              <Select className="input" value={form.organization_id} onChange={(e) => setForm({ ...form, organization_id: e.target.value })}>
                <option value="">Choose a customer…</option>
                {customers.rows.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </Select>
            </Field>
            <Field label="Package" required>
              <Select className="input" value={form.plan_id} onChange={(e) => setForm({ ...form, plan_id: e.target.value })}>
                <option value="">Choose a package…</option>
                {plans.rows.map((p) => <option key={p.id} value={p.id}>{p.name} — {money(p.price_cents, p.currency)}</option>)}
              </Select>
            </Field>
            <Field label="Billing cycle">
              <Select className="input" value={form.billing_cycle} onChange={(e) => setForm({ ...form, billing_cycle: e.target.value })}>
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly</option>
              </Select>
            </Field>
            <Field label="Discount (%)">
              <input className="input" type="number" min={0} max={100} value={form.discount_percent} onChange={(e) => setForm({ ...form, discount_percent: Number(e.target.value) })} />
            </Field>
            <Field label="Trial (days)" hint="0 starts the subscription straight away.">
              <input className="input" type="number" min={0} max={365} value={form.trial_days} onChange={(e) => setForm({ ...form, trial_days: Number(e.target.value) })} />
            </Field>
          </form>
        </Modal>
      )}
    </>
  )
}

/**
 * One subscription, with the package override.
 *
 * The quote is fetched before anything is applied, so the administrator sees
 * the prorated charge or credit -- and the date it takes effect -- before
 * committing to it, rather than discovering it on the next invoice.
 */
function SubscriptionDrawer({ sub, plans, onClose, onChanged }: { sub: Subscription; plans: Plan[]; onClose: () => void; onChanged: () => void }) {
  const detail = useOne<Subscription & { invoices: { id: string; number: string; amount_cents: number; currency: string; status: string; created_at: string | null }[]; payments: { id: string; amount_cents: number; currency: string; gateway: string; status: string; paid_at: string | null }[]; pending_plan: { id: string; name: string } | null; pending_plan_at: string | null }>(`/finance/subscriptions/${sub.id}`)
  const { run, busy } = useAction()
  const [change, setChange] = useState<{ plan_id: string; billing_cycle: string; when: 'now' | 'renewal' } | null>(null)
  const [quote, setQuote] = useState<Quote | null>(null)

  const getQuote = async (next: { plan_id: string; billing_cycle: string; when: 'now' | 'renewal' }) => {
    setChange(next)
    setQuote(null)
    if (!next.plan_id) return
    const res = await run<{ data: Quote }>({
      method: 'POST', path: `/finance/subscriptions/${sub.id}/quote-change`, body: next,
      label: 'Price the change', success: '', silent: true,
    }).catch(() => null)
    if (res?.data) setQuote(res.data)
  }

  const apply = async () => {
    if (!change?.plan_id) return
    const ok = await run({
      method: 'POST', path: `/finance/subscriptions/${sub.id}/change-plan`, body: change,
      label: 'Change the package', success: 'Package changed',
    }).catch(() => null)
    if (ok) onChanged()
  }

  const d = detail.item ?? sub

  return (
    <Drawer open onClose={onClose} width={640} title={`${sub.customer ?? 'Subscription'} — ${sub.plan ?? ''}`}
      footer={<button type="button" className="btn" onClick={onClose}>Close</button>}>
      <div className="grid gap-4">
        <div className="grid gap-3 sm:grid-cols-3">
          <StatTile label="Per month" value={money(d.monthly_value_cents, d.currency)} />
          <StatTile label="Status" value={<Status value={d.on_trial ? 'trialing' : d.status} />} />
          <StatTile label="Renews" value={d.auto_renew ? when(d.current_period_end) : 'No'} />
        </div>

        {detail.item?.pending_plan && (
          <Card title="A change is already booked">
            <p className="text-sm">Moves to <span className="font-semibold">{detail.item.pending_plan.name}</span> on {when(detail.item.pending_plan_at)}.</p>
          </Card>
        )}

        <Card title="Change package" subtitle="Price it before applying it">
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="New package">
              <Select className="input" value={change?.plan_id ?? ''}
                onChange={(e) => getQuote({ plan_id: e.target.value, billing_cycle: change?.billing_cycle ?? d.billing_cycle, when: change?.when ?? 'now' })}>
                <option value="">Choose…</option>
                {plans.filter((p) => p.id !== d.plan_id).map((p) => <option key={p.id} value={p.id}>{p.name} — {money(p.price_cents, p.currency)}</option>)}
              </Select>
            </Field>
            <Field label="Cycle">
              <Select className="input" value={change?.billing_cycle ?? d.billing_cycle}
                onChange={(e) => getQuote({ plan_id: change?.plan_id ?? '', billing_cycle: e.target.value, when: change?.when ?? 'now' })}>
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly</option>
              </Select>
            </Field>
            <Field label="When">
              <Select className="input" value={change?.when ?? 'now'}
                onChange={(e) => getQuote({ plan_id: change?.plan_id ?? '', billing_cycle: change?.billing_cycle ?? d.billing_cycle, when: e.target.value as 'now' | 'renewal' })}>
                <option value="now">Immediately</option>
                <option value="renewal">At the next renewal</option>
              </Select>
            </Field>
          </div>

          {quote && (
            <div className="mt-3 rounded-[10px] border border-line bg-head p-3.5 text-sm">
              {change?.when === 'renewal' ? (
                <p>Nothing is charged now. The new package starts on {when(quote.effective_at)}.</p>
              ) : (
                <ul className="grid gap-1">
                  <li>Unused on the current package: <span className="tabular-nums">{money(quote.credit_cents, quote.currency)}</span></li>
                  <li>The new package for the same {quote.days_remaining} days: <span className="tabular-nums">{money(quote.charge_cents, quote.currency)}</span></li>
                  <li className="font-semibold">
                    {quote.proration_cents >= 0 ? 'To charge now: ' : 'To credit: '}
                    <span className="tabular-nums">{money(Math.abs(quote.proration_cents), quote.currency)}</span>
                  </li>
                </ul>
              )}
              <button type="button" className="btn btn-primary mt-3" disabled={busy || !change?.plan_id} onClick={apply}>
                {busy ? 'Applying…' : 'Apply the change'}
              </button>
            </div>
          )}
        </Card>

        <Card title="Invoices" pad={false}>
          <DataTable label="Subscription invoices" rows={detail.item?.invoices ?? []} rowKey={(r) => r.id} empty="No invoices yet."
            columns={[
              { key: 'number', header: 'Number', render: (r) => <span className="font-mono text-xs">{r.number}</span> },
              { key: 'amount_cents', header: 'Amount', render: (r) => <span className="tabular-nums">{money(r.amount_cents, r.currency)}</span> },
              { key: 'status', header: 'Status', render: (r) => <Status value={r.status} /> },
              { key: 'created_at', header: 'Raised', render: (r) => <span className="text-xs text-muted">{when(r.created_at)}</span> },
            ]} />
        </Card>

        <Card title="Payments" pad={false}>
          <DataTable label="Subscription payments" rows={detail.item?.payments ?? []} rowKey={(r) => r.id} empty="Nothing paid yet."
            columns={[
              { key: 'paid_at', header: 'Paid', render: (r) => <span className="text-xs">{when(r.paid_at)}</span> },
              { key: 'amount_cents', header: 'Amount', render: (r) => <span className="tabular-nums">{money(r.amount_cents, r.currency)}</span> },
              { key: 'gateway', header: 'Gateway', render: (r) => <span className="capitalize">{r.gateway}</span> },
              { key: 'status', header: 'Status', render: (r) => <Status value={r.status} /> },
            ]} />
        </Card>
      </div>
    </Drawer>
  )
}
