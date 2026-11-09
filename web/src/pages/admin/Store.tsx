import { useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { api } from '../../api/client'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, PageHeader, StatTile, Empty } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog } from '../../components/Overlay'
import { FormModal, type FieldDef } from '../../components/FormModal'
import { Tabs } from '../../components/Tabs'
import { useToast } from '../../components/Toasts'
import type { R } from '../../lib/util'

/** STORE-001. Product catalogue: products, categories, brands and the POS. */

type Tab = 'products' | 'categories' | 'brands' | 'pos'
const TABS: { id: Tab; label: string }[] = [
  { id: 'products', label: 'Products' },
  { id: 'categories', label: 'Categories' },
  { id: 'brands', label: 'Brands' },
  { id: 'pos', label: 'POS' },
]

const money = (cents: number, ccy = 'LKR') =>
  `${ccy} ${(Number(cents || 0) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const toCents = (v: unknown) => Math.round(Number(v || 0) * 100)

const STOCK_TONE: Record<string, 'g' | 'y' | 'r' | 'n'> = { in: 'g', low: 'y', out: 'r', untracked: 'n' }
const STOCK_LABEL: Record<string, string> = { in: 'In stock', low: 'Low', out: 'Out', untracked: 'Not tracked' }
const STATUS_TONE: Record<string, 'g' | 'y' | 'n'> = { active: 'g', draft: 'y', archived: 'n' }

/** Turns blank optional fields into null so the API clears them rather than rejecting ''. */
function clean(v: Record<string, unknown>, keys: string[]) {
  const out: Record<string, unknown> = { ...v }
  for (const k of keys) if (out[k] === '' || out[k] === undefined) out[k] = null
  return out
}

/* ------------------------------------------------------------------ products */

function Products() {
  const products = useList<R>('/store/products', { per_page: 200 })
  const cats = useList<R>('/store/categories', { per_page: 200 })
  const brands = useList<R>('/store/brands', { per_page: 200 })
  const { run, busy } = useAction()
  const [edit, setEdit] = useState<R | null>(null)
  const [del, setDel] = useState<R | null>(null)
  const [stock, setStock] = useState<R | null>(null)

  const fields: FieldDef[] = [
    { name: 'name', label: 'Product name', required: true, placeholder: 'Cream Soda 400ml' },
    { name: 'sku', label: 'SKU', required: true, mono: true, placeholder: 'CS-400' },
    { name: 'barcode', label: 'Barcode', mono: true, placeholder: '4792024012345', hint: 'EAN/UPC — scanned at the POS.' },
    { name: 'category_id', label: 'Category', type: 'select', options: cats.rows.map((c) => ({ value: c.id, label: c.parent_name ? `${c.parent_name} › ${c.name}` : c.name })) },
    { name: 'brand_id', label: 'Brand', type: 'select', options: brands.rows.map((b) => ({ value: b.id, label: b.name })) },
    { name: 'unit', label: 'Unit', type: 'select', required: true, options: ['pcs', 'kg', 'g', 'l', 'ml', 'box', 'pack'] },
    { name: 'price', label: 'Selling price', type: 'number', required: true, placeholder: '150.00' },
    { name: 'cost', label: 'Cost price', type: 'number', placeholder: '110.00' },
    { name: 'currency', label: 'Currency', type: 'select', required: true, options: ['LKR', 'USD', 'EUR', 'GBP', 'AED', 'INR'] },
    { name: 'tax_rate', label: 'Tax rate (%)', type: 'number', placeholder: '0' },
    { name: 'stock', label: 'Opening stock', type: 'number', show: (v) => !!v.track_stock && !v.id },
    { name: 'low_stock_threshold', label: 'Low-stock alert at', type: 'number', show: (v) => !!v.track_stock },
    { name: 'status', label: 'Status', type: 'select', required: true, options: ['active', 'draft', 'archived'] },
    { name: 'image_url', label: 'Image URL', placeholder: 'https://…' },
    { name: 'description', label: 'Description', type: 'textarea' },
    { name: 'track_stock', label: 'Track stock', type: 'checkbox' },
    { name: 'sell_online', label: 'Sell in online store', type: 'checkbox' },
    { name: 'sell_pos', label: 'Sell at POS', type: 'checkbox', hint: 'Shows in the POS and can be scanned by barcode or SKU.' },
  ]

  const open = (p?: R) => setEdit(p ? { ...p, price: p.price_cents / 100, cost: p.cost_cents / 100 } : {
    unit: 'pcs', currency: 'LKR', status: 'active', track_stock: true, sell_online: true, sell_pos: true, stock: 0, low_stock_threshold: 5, tax_rate: 0,
  })

  const save = async (v: Record<string, unknown>) => {
    const { price, cost, id, ...rest } = clean(v, ['barcode', 'category_id', 'brand_id', 'image_url', 'description'])
    const keep = ['name', 'sku', 'barcode', 'category_id', 'brand_id', 'unit', 'currency', 'tax_rate', 'stock', 'low_stock_threshold',
      'status', 'image_url', 'description', 'track_stock', 'sell_online', 'sell_pos']
    const body: R = Object.fromEntries(Object.entries(rest).filter(([k]) => keep.includes(k)))
    if (id) delete body.stock // existing stock changes go through adjustments
    body.price_cents = toCents(price)
    body.cost_cents = toCents(cost)
    body.tax_rate = Number(body.tax_rate || 0)
    await run({ method: id ? 'PATCH' : 'POST', path: id ? `/store/products/${id}` : '/store/products', body, label: id ? 'Update product' : 'Create product', success: 'Product saved' })
  }

  return (
    <>
      <div className="flex justify-end mb-3"><button type="button" className="btn btn-primary" onClick={() => open()}>Add product</button></div>
      {!products.isLoading && products.rows.length === 0 && <Empty>No products yet. Add categories and brands, then your first product.</Empty>}
      <DataTable label="Products" rows={products.rows} loading={products.isLoading} rowKey={(r) => r.id} pageSize={15}
        searchKeys={(r) => `${r.name} ${r.sku} ${r.barcode ?? ''} ${r.category_name ?? ''} ${r.brand_name ?? ''}`}
        searchPlaceholder="Search name, SKU, barcode…"
        filters={[
          { id: 'pos', label: 'POS', test: (r) => r.sell_pos },
          { id: 'online', label: 'Online', test: (r) => r.sell_online },
          { id: 'low', label: 'Low stock', test: (r) => r.stock_status === 'low' },
          { id: 'out', label: 'Out of stock', test: (r) => r.stock_status === 'out' },
          { id: 'draft', label: 'Draft', test: (r) => r.status === 'draft' },
        ]}
        columns={[
          { key: 'name', header: 'Product', render: (r) => (
            <span className="flex items-center gap-2.5">
              {r.image_url ? <img src={r.image_url} alt="" className="w-8 h-8 rounded object-cover flex-none" /> : <span className="w-8 h-8 rounded bg-head flex-none" />}
              <span className="min-w-0"><span className="font-semibold block truncate">{r.name}</span><span className="font-mono text-xs text-muted">{r.sku}</span></span>
            </span>) },
          { key: 'category_name', header: 'Category', value: (r) => r.category_name ?? '—' },
          { key: 'brand_name', header: 'Brand', value: (r) => r.brand_name ?? '—' },
          { key: 'price_cents', header: 'Price', value: (r) => r.price_cents, render: (r) => <span className="tabular-nums">{money(r.price_cents, r.currency)}</span> },
          { key: 'stock', header: 'Stock', value: (r) => r.stock, render: (r) => (
            <span className="flex items-center gap-2">{r.track_stock && <span className="tabular-nums">{r.stock} {r.unit}</span>}<Badge tone={STOCK_TONE[r.stock_status]}>{STOCK_LABEL[r.stock_status]}</Badge></span>) },
          { key: 'channels', header: 'Channels', sortable: false, render: (r) => (
            <span className="flex gap-1">{r.sell_online && <Badge tone="b">Online</Badge>}{r.sell_pos && <Badge tone="g">POS</Badge>}</span>) },
          { key: 'status', header: 'Status', render: (r) => <Badge tone={STATUS_TONE[r.status]}>{r.status}</Badge> },
          { key: 'actions', header: '', sortable: false, render: (r) => (
            <span className="flex gap-2">
              <button type="button" className="btn btn-sm" onClick={() => open(r)}>Edit</button>
              {r.track_stock && <button type="button" className="btn btn-sm" onClick={() => setStock(r)}>Stock</button>}
              <button type="button" className="btn btn-sm" onClick={() => setDel(r)}>Delete</button>
            </span>) },
        ]} />

      <FormModal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? `Edit ${edit.name}` : 'Add product'} fields={fields} initial={edit ?? {}} onSubmit={save} submitLabel="Save product" />
      <FormModal open={!!stock} onClose={() => setStock(null)} title={`Adjust stock — ${stock?.name ?? ''}`} submitLabel="Apply"
        fields={[
          { name: 'delta', label: 'Change', type: 'number', required: true, placeholder: '+24 or -3', hint: `Currently ${stock?.stock ?? 0} ${stock?.unit ?? ''}. Positive to receive, negative to write off.` },
          { name: 'reason', label: 'Reason', placeholder: 'Goods received' },
        ]}
        onSubmit={(v) => run({ path: `/store/products/${stock!.id}/stock`, body: { delta: Number(v.delta), reason: v.reason || null }, label: 'Adjust stock', success: 'Stock updated' })} />
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} danger busy={busy} title={`Delete ${del?.name ?? ''}?`} confirmLabel="Delete"
        onConfirm={() => run({ method: 'DELETE', path: `/store/products/${del!.id}`, label: `Delete ${del!.name}`, success: 'Product deleted' }).then(() => setDel(null)).catch(() => {})}>
        This removes the product from the catalogue and the POS. Consider archiving it instead.
      </ConfirmDialog>
    </>
  )
}

/* ------------------------------------------------- categories & brands (CRUD) */

function SimpleCrud({ path, noun, fields, defaults, nullable, columns }: {
  path: string; noun: string; fields: FieldDef[]; defaults: R; nullable: string[]
  columns: { key: string; header: string; render?: (r: R) => React.ReactNode; value?: (r: R) => string | number }[]
}) {
  const list = useList<R>(path, { per_page: 200 })
  const { run, busy } = useAction()
  const [edit, setEdit] = useState<R | null>(null)
  const [del, setDel] = useState<R | null>(null)
  const keep = fields.map((f) => f.name)
  const save = async (v: Record<string, unknown>) => {
    const c = clean(v, nullable)
    const body = Object.fromEntries(Object.entries(c).filter(([k]) => keep.includes(k)))
    await run({ method: v.id ? 'PATCH' : 'POST', path: v.id ? `${path}/${v.id}` : path, body, label: `Save ${noun}`, success: `${noun[0].toUpperCase()}${noun.slice(1)} saved` })
  }
  return (
    <>
      <div className="flex justify-end mb-3"><button type="button" className="btn btn-primary" onClick={() => setEdit({ ...defaults })}>Add {noun}</button></div>
      {!list.isLoading && list.rows.length === 0 && <Empty>No {noun === 'category' ? 'categories' : `${noun}s`} yet.</Empty>}
      <DataTable label={noun} rows={list.rows} loading={list.isLoading} rowKey={(r) => r.id}
        columns={[
          ...columns,
          { key: 'products_count', header: 'Products', value: (r) => r.products_count },
          { key: 'active', header: 'Status', render: (r) => <Badge tone={r.active ? 'g' : 'n'}>{r.active ? 'Active' : 'Hidden'}</Badge> },
          { key: 'actions', header: '', sortable: false, render: (r) => (
            <span className="flex gap-2">
              <button type="button" className="btn btn-sm" onClick={() => setEdit(r)}>Edit</button>
              <button type="button" className="btn btn-sm" onClick={() => setDel(r)}>Delete</button>
            </span>) },
        ]} />
      <FormModal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? `Edit ${edit.name}` : `Add ${noun}`} fields={fields} initial={edit ?? {}} onSubmit={save} />
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} danger busy={busy} title={`Delete ${del?.name ?? ''}?`} confirmLabel="Delete"
        onConfirm={() => run({ method: 'DELETE', path: `${path}/${del!.id}`, label: `Delete ${del!.name}`, success: 'Deleted' }).then(() => setDel(null)).catch(() => {})}>
        {del?.products_count ? `${del.products_count} product(s) will be left without a ${noun}.` : `No products use this ${noun}.`}
      </ConfirmDialog>
    </>
  )
}

function Categories() {
  const cats = useList<R>('/store/categories', { per_page: 200 })
  return (
    <SimpleCrud path="/store/categories" noun="category" defaults={{ active: true, sort: 0 }} nullable={['parent_id', 'slug', 'description', 'image_url']}
      fields={[
        { name: 'name', label: 'Name', required: true, placeholder: 'Beverages' },
        { name: 'slug', label: 'Slug', mono: true, placeholder: 'auto from name' },
        { name: 'parent_id', label: 'Parent category', type: 'select', options: cats.rows.filter((c) => !c.parent_id).map((c) => ({ value: c.id, label: c.name })) },
        { name: 'sort', label: 'Sort order', type: 'number' },
        { name: 'image_url', label: 'Image URL', placeholder: 'https://…' },
        { name: 'description', label: 'Description', type: 'textarea' },
        { name: 'active', label: 'Visible in store', type: 'checkbox' },
      ]}
      columns={[
        { key: 'name', header: 'Category', render: (r) => <span className="font-semibold">{r.parent_name ? <><span className="text-muted font-normal">{r.parent_name} › </span>{r.name}</> : r.name}</span> },
        { key: 'slug', header: 'Slug', render: (r) => <span className="font-mono text-xs text-muted">{r.slug}</span> },
        { key: 'sort', header: 'Sort', value: (r) => r.sort },
      ]} />
  )
}

function Brands() {
  return (
    <SimpleCrud path="/store/brands" noun="brand" defaults={{ active: true }} nullable={['slug', 'logo_url', 'website']}
      fields={[
        { name: 'name', label: 'Name', required: true, placeholder: 'Elephant House' },
        { name: 'slug', label: 'Slug', mono: true, placeholder: 'auto from name' },
        { name: 'logo_url', label: 'Logo URL', placeholder: 'https://…' },
        { name: 'website', label: 'Website', placeholder: 'https://…' },
        { name: 'active', label: 'Active', type: 'checkbox' },
      ]}
      columns={[
        { key: 'name', header: 'Brand', render: (r) => (
          <span className="flex items-center gap-2.5">{r.logo_url ? <img src={r.logo_url} alt="" className="w-7 h-7 rounded object-contain flex-none" /> : <span className="w-7 h-7 rounded bg-head flex-none" />}<span className="font-semibold">{r.name}</span></span>) },
        { key: 'website', header: 'Website', render: (r) => r.website ? <a href={r.website} target="_blank" rel="noreferrer" className="text-xs">{r.website}</a> : <span className="text-muted">—</span> },
      ]} />
  )
}

/* ----------------------------------------------------------------------- POS */

type Line = { p: R; qty: number }

function Pos() {
  const products = useList<R>('/store/products', { per_page: 200, sell_pos: 1, status: 'active' })
  const { run, busy } = useAction()
  const toast = useToast()
  const [cart, setCart] = useState<Line[]>([])
  const [code, setCode] = useState('')
  const [q, setQ] = useState('')
  const scan = useRef<HTMLInputElement>(null)

  const add = (p: R) => setCart((c) => {
    const i = c.findIndex((l) => l.p.id === p.id)
    if (i < 0) return [...c, { p, qty: 1 }]
    return c.map((l, j) => (j === i ? { ...l, qty: l.qty + 1 } : l))
  })
  const setQty = (id: string, qty: number) => setCart((c) => c.flatMap((l) => (l.p.id !== id ? [l] : qty > 0 ? [{ ...l, qty }] : [])))

  const lookup = async (e: React.FormEvent) => {
    e.preventDefault()
    const c = code.trim()
    if (!c) return
    try {
      const res = await api.get<{ data: R }>('/store/pos/lookup', { code: c })
      add(res.data)
    } catch (ex) { toast.error(ex, `No POS product for “${c}”`) }
    setCode('')
    scan.current?.focus()
  }

  const subtotal = cart.reduce((s, l) => s + l.p.price_cents * l.qty, 0)
  const tax = cart.reduce((s, l) => s + Math.round(l.p.price_cents * l.qty * (Number(l.p.tax_rate) || 0) / 100), 0)
  const ccy = cart[0]?.p.currency ?? 'LKR'
  const shown = useMemo(() => {
    const n = q.trim().toLowerCase()
    return products.rows.filter((p) => !n || `${p.name} ${p.sku} ${p.barcode ?? ''}`.toLowerCase().includes(n))
  }, [products.rows, q])

  const checkout = async () => {
    try {
      for (const l of cart.filter((x) => x.p.track_stock)) {
        await run({ path: `/store/products/${l.p.id}/stock`, body: { delta: -l.qty, reason: 'POS sale' }, label: 'Stock', silent: true })
      }
      toast.success(`Sale completed — ${money(subtotal + tax, ccy)}`)
      setCart([])
    } catch (ex) { toast.error(ex, 'Sale could not be completed') }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_380px]">
      <Card title="POS products" subtitle="Products marked “Sell at POS”. Click to add, or scan a barcode.">
        <form onSubmit={lookup} className="flex gap-2 mb-3">
          <input ref={scan} autoFocus className="input font-mono flex-1" placeholder="Scan barcode or type SKU, then Enter" value={code} onChange={(e) => setCode(e.target.value)} aria-label="Barcode or SKU" />
          <button className="btn">Add</button>
        </form>
        <input className="input mb-3" placeholder="Filter products…" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Filter products" />
        {!products.isLoading && shown.length === 0 && <Empty>No POS products. Edit a product and tick “Sell at POS”.</Empty>}
        <div className="grid gap-2 [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))]">
          {shown.map((p) => (
            <button key={p.id} type="button" onClick={() => add(p)} disabled={p.stock_status === 'out'}
              className="text-left rounded-lg border border-line p-3 hover:bg-head disabled:opacity-50">
              {p.image_url && <img src={p.image_url} alt="" className="w-full h-20 object-cover rounded mb-2" />}
              <div className="font-semibold text-sm truncate">{p.name}</div>
              <div className="text-xs text-muted font-mono">{p.sku}</div>
              <div className="text-sm tabular-nums mt-1">{money(p.price_cents, p.currency)}</div>
              {p.track_stock && <div className="text-[11px] text-muted">{p.stock_status === 'out' ? 'Out of stock' : `${p.stock} ${p.unit} left`}</div>}
            </button>
          ))}
        </div>
      </Card>
      <Card title="Current sale" actions={cart.length > 0 && <button type="button" className="btn btn-sm" onClick={() => setCart([])}>Clear</button>}>
        {cart.length === 0 ? <div className="text-sm text-muted">Cart is empty.</div> : (
          <ul className="list-none m-0 p-0 flex flex-col gap-2">
            {cart.map((l) => (
              <li key={l.p.id} className="flex items-center gap-2">
                <span className="flex-1 min-w-0"><span className="block text-sm font-semibold truncate">{l.p.name}</span><span className="text-xs text-muted tabular-nums">{money(l.p.price_cents, l.p.currency)}</span></span>
                <input className="input w-16 tabular-nums" type="number" min={0} value={l.qty} onChange={(e) => setQty(l.p.id, Number(e.target.value))} aria-label={`Quantity of ${l.p.name}`} />
                <span className="w-24 text-right tabular-nums text-sm">{money(l.p.price_cents * l.qty, l.p.currency)}</span>
              </li>
            ))}
          </ul>
        )}
        <div className="border-t border-line mt-4 pt-3 text-sm flex flex-col gap-1">
          <div className="flex justify-between"><span className="text-muted">Subtotal</span><span className="tabular-nums">{money(subtotal, ccy)}</span></div>
          <div className="flex justify-between"><span className="text-muted">Tax</span><span className="tabular-nums">{money(tax, ccy)}</span></div>
          <div className="flex justify-between font-bold text-base"><span>Total</span><span className="tabular-nums">{money(subtotal + tax, ccy)}</span></div>
        </div>
        <button type="button" className="btn btn-primary w-full mt-4" disabled={!cart.length || busy} onClick={checkout}>{busy ? 'Processing…' : 'Complete sale'}</button>
      </Card>
    </div>
  )
}

/* ---------------------------------------------------------------------- page */

export default function Store() {
  const { tab } = useParams()
  const nav = useNavigate()
  const current = (TABS.some((t) => t.id === tab) ? tab : 'products') as Tab
  const s = useOne<R>('/store/summary').item
  return (
    <>
      <PageHeader title="Store" subtitle="Product catalogue, categories, brands and point of sale" />
      <div className="grid gap-3 mb-4 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
        <StatTile label="Products" value={s?.products ?? '—'} />
        <StatTile label="POS products" value={s?.pos ?? '—'} />
        <StatTile label="Low stock" value={s?.low_stock ?? '—'} valueTone={s?.low_stock ? 'y' : 'n'} />
        <StatTile label="Out of stock" value={s?.out_of_stock ?? '—'} valueTone={s?.out_of_stock ? 'r' : 'n'} />
        <StatTile label="Categories / Brands" value={s ? `${s.categories} / ${s.brands}` : '—'} />
      </div>
      <Tabs tabs={TABS} value={current} onChange={(t) => nav(t === 'products' ? '/admin/store' : `/admin/store/${t}`)} label="Store sections" />
      <div className="mt-4">
        {current === 'products' && <Products />}
        {current === 'categories' && <Categories />}
        {current === 'brands' && <Brands />}
        {current === 'pos' && <Pos />}
      </div>
    </>
  )
}
