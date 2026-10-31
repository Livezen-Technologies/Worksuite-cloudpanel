import { Empty, splitEmpty } from './Card'
import { Fragment, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Menu, MenuButton, MenuItem, MenuItems, MenuSeparator } from '@headlessui/react'
import { api } from '../api/client'
import { Icon } from './Icon'
import { ConfirmDialog, Drawer } from './Overlay'
import Select from './Select'
import { applyConditions, multiSort, nextSort, OPS, opLabel, type Condition, type Op, type SortKey } from './table/filtering'
import { exportTable, rowsFor } from './table/exporting'

/**
 * TBL-1..7: the one table/list component every module uses.
 *
 * Search, quick filter chips, an advanced condition builder, multi-column sorting,
 * density, table / list / grid layouts, column visibility, order and widths, sticky
 * header, pagination with page size, row selection with a contextual bulk bar,
 * row actions (icons + a "more" menu, with confirmation for destructive ones),
 * CSV / Excel / PDF export and saved views (personal or shared, synced via the API).
 * Every existing prop keeps its meaning, so older call sites work unchanged.
 */

/** Table empty text: a bold first sentence and a muted explanation. */
function EmptyText({ children }: { children: ReactNode }) {
  if (typeof children !== 'string') return <div className="text-sm text-muted">{children}</div>
  const [t, b] = splitEmpty(children)
  return <><div className="text-sm font-semibold">{t}</div>{b && <div className="text-sm text-muted mt-0.5 max-w-md mx-auto">{b}</div>}</>
}

export interface Column<T> {
  key: string
  header: string
  render?: (row: T) => ReactNode
  /** Value used for sorting, filtering, export and default rendering. */
  value?: (row: T) => string | number | null | undefined
  sortable?: boolean
  className?: string
  /** Hidden until the user turns it on in Columns. */
  hidden?: boolean
  /** Offer these values in the filter builder ("is any of"). */
  options?: string[]
  /** Treat as a date or a number in the filter builder. */
  kind?: 'text' | 'number' | 'date'
  align?: 'left' | 'right'
}
export interface FilterDef<T> { id: string; label: string; test: (row: T) => boolean }
export interface BulkAction { label: string; onClick: (ids: string[]) => void; danger?: boolean; icon?: string; confirm?: string }
export interface RowAction {
  label: string
  onClick: () => void
  icon?: string
  danger?: boolean
  /** Ask first; the text is the question. Defaults on for danger actions. */
  confirm?: string | boolean
  disabled?: boolean
  /** Keep it in the "more" menu even when there is room for an icon. */
  menuOnly?: boolean
}

export interface DataTableProps<T> {
  rows: T[]
  columns: Column<T>[]
  rowKey: (row: T) => string
  label: string
  /** Stable id for saved views and preferences; defaults to the label. */
  tableId?: string
  searchKeys?: (row: T) => string
  searchPlaceholder?: string
  filters?: FilterDef<T>[]
  pageSize?: number
  selectable?: boolean
  bulkActions?: BulkAction[]
  actions?: (row: T) => RowAction[]
  onRowClick?: (row: T) => void
  selectedRowId?: string | null
  toolbar?: ReactNode
  loading?: boolean
  error?: string | null
  onRetry?: () => void
  empty?: ReactNode
  minWidth?: number
  rowLabel?: (row: T) => string
  /** Offer list and grid layouts (default true). */
  layouts?: boolean
  /** Offer export (default true). */
  exportable?: boolean
  /** Sync saved views with the server (default true). */
  savedViews?: boolean
}

type Density = 'compact' | 'comfortable' | 'spacious'
type Layout = 'table' | 'list' | 'grid'
interface ViewConfig { columns?: string[]; hidden?: string[]; widths?: Record<string, number>; sort?: SortKey[]; conditions?: Condition[]; filter?: string | null; q?: string; density?: Density; pageSize?: number; layout?: Layout }
interface SavedView { id: string; name: string; config: ViewConfig; shared: boolean; is_default: boolean; mine: boolean; can_edit: boolean }

const DENSITY: Record<Density, { cell: string; label: string }> = {
  compact: { cell: 'py-1.5 px-3', label: 'Compact' },
  comfortable: { cell: 'py-3 px-3', label: 'Comfortable' },
  spacious: { cell: 'py-4 px-3.5', label: 'Spacious' },
}
const PAGE_SIZES = [10, 25, 50, 100]

function cellValue<T>(c: Column<T>, r: T): string | number | null | undefined {
  if (c.value) return c.value(r)
  const v = (r as Record<string, unknown>)[c.key]
  return typeof v === 'number' || typeof v === 'string' ? v : v == null ? '' : typeof v === 'boolean' ? (v ? 'yes' : 'no') : Array.isArray(v) ? v.join(', ') : ''
}

/** Free-text columns wrap; names, hosts, states and numbers stay on one line. */
const PROSE = /^(detail|details|message|description|reason|notes?|error|summary|comment|event|what)$/i
const cellWrap = (c: { header?: unknown; className?: string }) =>
  /whitespace-/.test(c.className ?? '') ? '' : typeof c.header === 'string' && PROSE.test(c.header) ? 'min-w-[16rem]' : 'whitespace-nowrap'

const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80) || 'table'
const store = {
  get: (k: string): ViewConfig | null => { try { return JSON.parse(localStorage.getItem(k) ?? 'null') } catch { return null } },
  set: (k: string, v: ViewConfig) => { try { localStorage.setItem(k, JSON.stringify(v)) } catch { /* private mode */ } },
}

function IconButton({ icon, label, onClick, danger, disabled, active }: { icon: string; label: string; onClick?: () => void; danger?: boolean; disabled?: boolean; active?: boolean }) {
  return (
    <button type="button" aria-label={label} title={label} disabled={disabled} aria-pressed={active}
      onClick={(e) => { e.stopPropagation(); onClick?.() }}
      className={`inline-grid place-items-center w-8 h-8 rounded-lg border-0 bg-transparent cursor-pointer text-muted transition-colors hover:bg-head hover:text-fg aria-pressed:bg-sel aria-pressed:text-accent disabled:opacity-40 disabled:cursor-not-allowed ${danger ? 'hover:!text-[var(--r-fg)]' : ''}`}>
      <Icon name={icon} size={17} />
    </button>
  )
}

const menuItems = 'z-80 min-w-48 rounded-xl border border-line bg-card py-1.5 shadow-[0_12px_32px_-8px_rgba(15,23,42,.22),0_2px_6px_rgba(15,23,42,.06)] outline-none [--anchor-gap:6px] origin-top transition duration-100 ease-out data-closed:opacity-0 data-closed:-translate-y-1'
const menuItem = 'flex w-full items-center gap-2.5 px-3.5 py-2 text-sm text-left border-0 bg-transparent cursor-pointer text-fg data-focus:bg-head data-disabled:opacity-40 data-disabled:cursor-not-allowed'

function ToolMenu({ icon, label, children, text }: { icon: string; label: string; children: ReactNode; text?: string }) {
  return (
    <Menu>
      <MenuButton className="btn btn-sm gap-1.5" aria-label={label} title={label}><Icon name={icon} size={16} />{text && <span className="hidden sm:inline">{text}</span>}</MenuButton>
      <MenuItems anchor="bottom end" transition className={menuItems}>{children}</MenuItems>
    </Menu>
  )
}

export function DataTable<T>(p: DataTableProps<T>) {
  const { rows, rowKey } = p
  const tableId = p.tableId ?? slug(p.label)
  const prefKey = `dt:${tableId}`
  const base = useMemo(() => store.get(prefKey) ?? {}, [prefKey])
  const allKeys = p.columns.map((c) => c.key)

  const [q, setQ] = useState(base.q ?? '')
  const [filter, setFilter] = useState<string>(base.filter ?? 'all')
  const [sort, setSort] = useState<SortKey[]>(base.sort ?? [])
  const [conds, setConds] = useState<Condition[]>(base.conditions ?? [])
  const [density, setDensity] = useState<Density>(base.density ?? 'comfortable')
  const [layout, setLayout] = useState<Layout>(base.layout ?? 'table')
  const [pageSize, setPageSize] = useState<number>(base.pageSize ?? p.pageSize ?? 10)
  const [order, setOrder] = useState<string[]>(base.columns ?? allKeys)
  const [hidden, setHidden] = useState<Set<string>>(new Set(base.hidden ?? p.columns.filter((c) => c.hidden).map((c) => c.key)))
  const [widths, setWidths] = useState<Record<string, number>>(base.widths ?? {})
  const [page, setPage] = useState(1)
  const [sel, setSel] = useState<Set<string>>(new Set())
  const [panel, setPanel] = useState<'filters' | 'columns' | null>(null)
  const [confirm, setConfirm] = useState<{ title: string; text: string; run: () => void } | null>(null)
  const [views, setViews] = useState<SavedView[]>([])
  const [viewId, setViewId] = useState<string | null>(null)
  const [saveAs, setSaveAs] = useState<{ name: string; shared: boolean; is_default: boolean; id?: string } | null>(null)

  const config: ViewConfig = useMemo(() => ({ columns: order, hidden: [...hidden], widths, sort, conditions: conds, filter, q, density, pageSize, layout }),
    [order, hidden, widths, sort, conds, filter, q, density, pageSize, layout])
  useEffect(() => { store.set(prefKey, config) }, [prefKey, config])

  const apply = useCallback((c: ViewConfig) => {
    setOrder(c.columns?.length ? c.columns : allKeys); setHidden(new Set(c.hidden ?? [])); setWidths(c.widths ?? {})
    setSort(c.sort ?? []); setConds(c.conditions ?? []); setFilter(c.filter ?? 'all'); setQ(c.q ?? '')
    setDensity(c.density ?? 'comfortable'); setPageSize(c.pageSize ?? p.pageSize ?? 10); setLayout(c.layout ?? 'table'); setPage(1)
  }, [allKeys.join('|'), p.pageSize]) // eslint-disable-line react-hooks/exhaustive-deps

  const loadViews = useCallback(async (pickDefault = false) => {
    if (p.savedViews === false) return
    try {
      const r = await api.get<{ data: SavedView[] }>('/table-views', { table: tableId })
      const list = Array.isArray(r?.data) ? r.data : []
      setViews(list)
      if (pickDefault && !store.get(prefKey)) {
        const d = list.find((v) => v.is_default && v.mine) ?? list.find((v) => v.is_default)
        if (d) { apply(d.config); setViewId(d.id) }
      }
    } catch { /* not signed in, or offline: views just stay empty */ }
  }, [tableId, prefKey, apply, p.savedViews])
  useEffect(() => { loadViews(true) }, [loadViews])

  // Columns in the user's order; new columns (added in code later) are appended.
  const cols = useMemo(() => {
    const known = order.filter((k) => allKeys.includes(k))
    return [...known, ...allKeys.filter((k) => !known.includes(k))].map((k) => p.columns.find((c) => c.key === k)!)
  }, [order, p.columns]) // eslint-disable-line react-hooks/exhaustive-deps
  const visible = cols.filter((c) => !hidden.has(c.key))
  const get = useCallback((r: T, k: string) => { const c = p.columns.find((x) => x.key === k); return c ? cellValue(c, r) : undefined }, [p.columns])

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase()
    const f = p.filters?.find((x) => x.id === filter)
    let out = rows.filter((r) => (!f || f.test(r)) && (!needle || (p.searchKeys ? p.searchKeys(r) : p.columns.map((c) => String(cellValue(c, r) ?? '')).join(' ')).toLowerCase().includes(needle)))
    out = applyConditions(out, conds, get)
    return multiSort(out, sort, get)
  }, [rows, q, filter, sort, conds, get, p.columns, p.filters, p.searchKeys])

  const pages = Math.max(1, Math.ceil(filtered.length / pageSize))
  useEffect(() => { if (page > pages) setPage(pages) }, [page, pages])
  const shown = filtered.slice((page - 1) * pageSize, page * pageSize)
  const ids = useMemo(() => new Set(rows.map(rowKey)), [rows, rowKey])
  const selected = [...sel].filter((id) => ids.has(id))
  const allOnPage = shown.length > 0 && shown.every((r) => sel.has(rowKey(r)))
  const someOnPage = shown.some((r) => sel.has(rowKey(r)))
  const toggle = (id: string) => setSel((s) => { const n = new Set(s); if (n.has(id)) n.delete(id); else n.add(id); return n })
  const cell = DENSITY[density].cell
  const activeView = views.find((v) => v.id === viewId) ?? null
  const dirty = !!activeView && JSON.stringify(activeView.config) !== JSON.stringify(config)

  const doExport = (kind: 'csv' | 'xls' | 'pdf', only?: string[]) => {
    const src = only ? filtered.filter((r) => only.includes(rowKey(r))) : filtered
    const { headers, data } = rowsFor(src, visible.filter((c) => c.header).map((c) => ({ header: c.header, get: (r: T) => cellValue(c, r) })))
    exportTable(kind, p.label, headers, data)
  }
  const runAction = (a: { label: string; danger?: boolean; confirm?: string | boolean }, go: () => void) => {
    const ask = a.confirm ?? a.danger
    if (ask) setConfirm({ title: `${a.label}?`, text: typeof ask === 'string' ? ask : 'This can’t be undone.', run: go })
    else go()
  }

  // Column resize: drag the handle on a header's right edge.
  const drag = useRef<{ key: string; x: number; w: number } | null>(null)
  const startResize = (e: React.PointerEvent, key: string) => {
    e.preventDefault(); e.stopPropagation()
    const th = (e.currentTarget as HTMLElement).parentElement as HTMLElement
    drag.current = { key, x: e.clientX, w: th.getBoundingClientRect().width }
    const move = (ev: PointerEvent) => { const d = drag.current; if (d) setWidths((w) => ({ ...w, [d.key]: Math.max(60, Math.min(800, Math.round(d.w + ev.clientX - d.x))) })) }
    const up = () => { drag.current = null; window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up) }
    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up)
  }

  const saveView = async () => {
    if (!saveAs) return
    const body = { name: saveAs.name.trim(), config, shared: saveAs.shared, is_default: saveAs.is_default }
    try {
      const r = saveAs.id ? await api.patch<{ data: SavedView }>(`/table-views/${saveAs.id}`, body) : await api.post<{ data: SavedView }>('/table-views', { table: tableId, ...body })
      setViewId(r?.data?.id ?? null); setSaveAs(null); loadViews()
    } catch (e) { alert(e instanceof Error ? e.message : 'Could not save the view.') }
  }
  const delView = async (v: SavedView) => { try { await api.del(`/table-views/${v.id}`); if (viewId === v.id) setViewId(null); loadViews() } catch { /* toast-free: the list just stays */ } }
  const resetAll = () => { apply({}); setViewId(null) }

  const actionsFor = (r: T) => (p.actions?.(r) ?? [])
  const renderActions = (r: T) => {
    const list = actionsFor(r)
    if (!list.length) return null
    const inline = list.filter((a) => a.icon && !a.menuOnly && !a.danger).slice(0, 2)
    const more = list.filter((a) => !inline.includes(a))
    return (
      <span className="inline-flex items-center gap-0.5 justify-end" onClick={(e) => e.stopPropagation()}>
        {inline.map((a) => <IconButton key={a.label} icon={a.icon!} label={a.label} disabled={a.disabled} onClick={() => runAction(a, a.onClick)} />)}
        {more.length > 0 && (
          <Menu>
            <MenuButton aria-label="More actions" title="More actions" className="inline-grid place-items-center w-8 h-8 rounded-lg border-0 bg-transparent cursor-pointer text-muted hover:bg-head hover:text-fg data-open:bg-head data-open:text-fg"><Icon name="dots" size={18} /></MenuButton>
            <MenuItems anchor="bottom end" transition className={menuItems}>
              {more.map((a, i) => (
                <Fragment key={a.label}>
                  {a.danger && i > 0 && !more[i - 1].danger && <MenuSeparator className="my-1 h-px bg-line" />}
                  <MenuItem disabled={a.disabled}>
                    <button type="button" className={`${menuItem} ${a.danger ? '!text-[var(--r-fg)]' : ''}`} onClick={() => runAction(a, a.onClick)}>
                      {a.icon ? <Icon name={a.icon} size={16} /> : <span className="w-4" />}{a.label}
                    </button>
                  </MenuItem>
                </Fragment>
              ))}
            </MenuItems>
          </Menu>
        )}
      </span>
    )
  }

  const condChips = conds.filter((c) => OPS.find((o) => o.id === c.op)?.needs === 'none' || (Array.isArray(c.value) ? c.value.length : String(c.value ?? '') !== ''))
  const colName = (k: string) => p.columns.find((c) => c.key === k)?.header || k
  const hasActions = !!p.actions
  const span = visible.length + (p.selectable ? 1 : 0) + (hasActions ? 1 : 0)

  return (
    <div className="card overflow-hidden min-w-0">
      {p.filters && p.filters.length > 0 && (
        <div className="flex flex-wrap gap-2 px-4 pt-4" role="group" aria-label={`${p.label} filters`}>
          {[{ id: 'all', label: 'All' }, ...p.filters].map((f) => {
            const n = f.id === 'all' ? rows.length : rows.filter(p.filters!.find((x) => x.id === f.id)!.test).length
            return <button key={f.id} type="button" className="chip" aria-pressed={filter === f.id} onClick={() => { setFilter(f.id); setPage(1) }}>{f.label} <span className="opacity-75 ml-1 tabular-nums">{n}</span></button>
          })}
        </div>
      )}

      {/* Toolbar, or the bulk bar while rows are selected. */}
      {p.selectable && selected.length > 0 ? (
        <div className="flex flex-wrap items-center gap-2.5 px-4 py-3 border-b border-line bg-sel" role="region" aria-label="Bulk actions">
          <span className="text-sm font-semibold text-accent-fg" aria-live="polite">{selected.length} selected</span>
          <button type="button" className="btn btn-sm btn-ghost" onClick={() => setSel(new Set())}>Clear</button>
          {filtered.length > selected.length && <button type="button" className="btn btn-sm btn-ghost" onClick={() => setSel(new Set(filtered.map(rowKey)))}>Select all {filtered.length}</button>}
          <span className="flex-1" />
          {p.bulkActions?.map((a) => (
            <button key={a.label} type="button" className={`btn btn-sm gap-1.5 ${a.danger ? 'btn-danger' : ''}`}
              onClick={() => runAction(a, () => { a.onClick(selected); setSel(new Set()) })}>{a.icon && <Icon name={a.icon} size={15} />}{a.label}</button>
          ))}
          {p.exportable !== false && (
            <ToolMenu icon="download" label="Export selected" text="Export">
              <MenuItem><button type="button" className={menuItem} onClick={() => doExport('csv', selected)}>CSV</button></MenuItem>
              <MenuItem><button type="button" className={menuItem} onClick={() => doExport('xls', selected)}>Excel</button></MenuItem>
              <MenuItem><button type="button" className={menuItem} onClick={() => doExport('pdf', selected)}>PDF (print)</button></MenuItem>
            </ToolMenu>
          )}
        </div>
      ) : (
        <div className="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-line">
          <label className="flex items-center gap-2 flex-1 min-w-[180px] h-9 px-3 rounded-lg border border-line bg-bg text-muted focus-within:border-accent">
            <Icon name="search" size={16} />
            <input aria-label={`Search ${p.label}`} placeholder={p.searchPlaceholder ?? 'Search…'} value={q}
              onChange={(e) => { setQ(e.target.value); setPage(1) }} className="flex-1 min-w-0 border-0 outline-0 bg-transparent text-fg text-sm" />
            {q && <button type="button" aria-label="Clear search" className="border-0 bg-transparent cursor-pointer text-muted hover:text-fg p-0 grid" onClick={() => setQ('')}><Icon name="close" size={14} /></button>}
          </label>
          {p.toolbar}
          <button type="button" className="btn btn-sm gap-1.5" onClick={() => setPanel('filters')} aria-label="Filters">
            <Icon name="funnel" size={16} /><span className="hidden sm:inline">Filters</span>{condChips.length > 0 && <span className="grid place-items-center min-w-5 h-5 px-1 rounded-full bg-accent text-white text-[11px] font-bold">{condChips.length}</span>}
          </button>
          {p.savedViews !== false && (
            <ToolMenu icon="bookmark" label="Saved views" text={activeView ? `${activeView.name}${dirty ? ' •' : ''}` : 'Views'}>
              <MenuItem><button type="button" className={menuItem} onClick={resetAll}><Icon name="refresh" size={16} />Default view</button></MenuItem>
              {views.length > 0 && <MenuSeparator className="my-1 h-px bg-line" />}
              {views.map((v) => (
                <MenuItem key={v.id}>
                  <button type="button" className={`${menuItem} ${v.id === viewId ? 'font-semibold' : ''}`} onClick={() => { apply(v.config); setViewId(v.id) }}>
                    <Icon name={v.shared ? 'users' : 'bookmark'} size={16} /><span className="flex-1">{v.name}</span>{v.is_default && <span className="text-[11px] text-muted">default</span>}
                  </button>
                </MenuItem>
              ))}
              <MenuSeparator className="my-1 h-px bg-line" />
              {activeView?.can_edit && <MenuItem><button type="button" className={menuItem} onClick={() => setSaveAs({ id: activeView.id, name: activeView.name, shared: activeView.shared, is_default: activeView.is_default })}><Icon name="pencil" size={16} />Update “{activeView.name}”</button></MenuItem>}
              <MenuItem><button type="button" className={menuItem} onClick={() => setSaveAs({ name: activeView ? `${activeView.name} (copy)` : '', shared: false, is_default: false })}><Icon name="plus" size={16} />{activeView ? 'Save as new view' : 'Save view'}</button></MenuItem>
              {activeView?.can_edit && <MenuItem><button type="button" className={`${menuItem} !text-[var(--r-fg)]`} onClick={() => runAction({ label: `Delete view “${activeView.name}”`, danger: true }, () => delView(activeView))}><Icon name="trash" size={16} />Delete view</button></MenuItem>}
            </ToolMenu>
          )}
          <span className="inline-flex items-center rounded-lg border border-line p-0.5" role="group" aria-label="Layout and columns">
            {p.layouts !== false && (['table', 'list', 'grid'] as Layout[]).map((l) => <IconButton key={l} icon={l === 'grid' ? 'grid' : l} label={`${l[0].toUpperCase()}${l.slice(1)} view`} active={layout === l} onClick={() => setLayout(l)} />)}
            <IconButton icon="columns" label="Columns and density" onClick={() => setPanel('columns')} />
          </span>
          {p.exportable !== false && (
            <ToolMenu icon="download" label="Export">
              <MenuItem><button type="button" className={menuItem} onClick={() => doExport('csv')}>Export CSV</button></MenuItem>
              <MenuItem><button type="button" className={menuItem} onClick={() => doExport('xls')}>Export Excel</button></MenuItem>
              <MenuItem><button type="button" className={menuItem} onClick={() => doExport('pdf')}>Export PDF (print)</button></MenuItem>
            </ToolMenu>
          )}
        </div>
      )}

      {/* Active conditions as removable chips. */}
      {(condChips.length > 0 || sort.length > 1) && (
        <div className="flex flex-wrap items-center gap-2 px-4 py-2.5 border-b border-line text-xs">
          {condChips.map((c, i) => (
            <span key={i} className="inline-flex items-center gap-1.5 h-7 pl-2.5 pr-1 rounded-full bg-sel text-accent-fg">
              <b className="font-semibold">{colName(c.col)}</b> {opLabel(c.op)} {Array.isArray(c.value) ? c.value.join(', ') : c.value}
              <button type="button" aria-label={`Remove filter ${colName(c.col)}`} className="grid place-items-center w-5 h-5 rounded-full border-0 bg-transparent cursor-pointer text-current hover:bg-white/50" onClick={() => setConds(conds.filter((x) => x !== c))}><Icon name="close" size={12} /></button>
            </span>
          ))}
          {sort.length > 1 && <span className="text-muted">Sorted by {sort.map((s) => `${colName(s.key)} ${s.dir === 1 ? '↑' : '↓'}`).join(', ')}</span>}
          <button type="button" className="btn btn-sm btn-ghost !min-h-7" onClick={() => { setConds([]); setSort([]) }}>Clear all</button>
        </div>
      )}

      {p.error ? (
        <div role="alert" className="flex flex-col items-center gap-3 p-10 text-center">
          <span className="grid place-items-center w-11 h-11 rounded-full bg-r-bg text-r-fg"><Icon name="warn" size={22} /></span>
          <div className="font-semibold">Couldn’t load {p.label.toLowerCase()}</div>
          <div className="text-sm text-muted max-w-md">{p.error}</div>
          {p.onRetry && <button type="button" className="btn btn-sm" onClick={p.onRetry}><Icon name="refresh" size={15} />Try again</button>}
        </div>
      ) : layout === 'table' ? (
        <div className="overflow-auto max-h-[70vh]">
          <table className="w-full border-collapse text-[13px]" style={{ minWidth: p.minWidth ?? 640 }} aria-label={p.label} aria-rowcount={filtered.length}>
            <thead>
              <tr className="text-left text-fg text-[13px]">
                {p.selectable && (
                  <th className="sticky top-0 z-10 bg-head py-2.5 pl-4 pr-1 w-8 border-b border-line">
                    <input type="checkbox" className="check" aria-label="Select all on this page" checked={allOnPage}
                      ref={(el) => { if (el) el.indeterminate = !allOnPage && someOnPage }}
                      onChange={() => setSel((s) => { const n = new Set(s); shown.forEach((r) => (allOnPage ? n.delete(rowKey(r)) : n.add(rowKey(r)))); return n })} />
                  </th>
                )}
                {visible.map((c) => {
                  const si = sort.findIndex((s) => s.key === c.key)
                  const active = si >= 0
                  return (
                    <th key={c.key} scope="col" style={widths[c.key] ? { width: widths[c.key], minWidth: widths[c.key] } : undefined}
                      className={`group/th sticky top-0 z-10 bg-head border-b border-line ${cell.replace(/py-\S+/, 'py-2.5')} font-semibold whitespace-nowrap relative ${c.align === 'right' ? 'text-right' : ''} ${c.className ?? ''}`}
                      aria-sort={active && sort.length === 1 ? (sort[si].dir === 1 ? 'ascending' : 'descending') : undefined}>
                      {c.sortable !== false && c.header ? (
                        <button type="button" title="Sort (shift-click to add)" className="group -mx-1.5 inline-flex min-h-7 items-center gap-1 rounded-md border-0 bg-transparent px-1.5 font-semibold text-fg cursor-pointer hover:bg-[var(--ctl-hover)]"
                          onClick={(e) => { setSort(nextSort(sort, c.key, e.shiftKey)); setPage(1) }}>
                          {c.header}
                          <span className={`inline-flex items-center ${active ? 'text-accent' : 'text-muted opacity-0 group-hover:opacity-100'}`}>
                            <Icon name={active && sort[si].dir === -1 ? 'down' : 'up'} size={13} />
                            {active && sort.length > 1 && <span className="text-[10px] font-bold">{si + 1}</span>}
                          </span>
                        </button>
                      ) : c.header ? c.header : <span className="sr-only">Actions</span>}
                      {c.header && <span aria-hidden="true" onPointerDown={(e) => startResize(e, c.key)} onDoubleClick={() => setWidths((w) => { const n = { ...w }; delete n[c.key]; return n })}
                        className="absolute right-0 top-2 bottom-2 w-1.5 cursor-col-resize rounded opacity-0 group-hover/th:opacity-100 hover:bg-accent/40" />}
                    </th>
                  )
                })}
                {hasActions && <th className="sticky top-0 z-10 bg-head border-b border-line py-2.5 pr-4 text-right w-px"><span className="sr-only">Actions</span></th>}
              </tr>
            </thead>
            <tbody>
              {p.loading && Array.from({ length: 5 }, (_, i) => (
                <tr key={`sk${i}`} className="border-t border-line first:border-t-0" aria-hidden="true">
                  {Array.from({ length: span }, (_, j) => <td key={j} className={cell}><span className="block h-3.5 rounded bg-head animate-pulse" style={{ width: `${50 + ((i * 7 + j * 13) % 45)}%` }} /></td>)}
                </tr>
              ))}
              {!p.loading && shown.length === 0 && (
                <tr><td colSpan={span} className="p-10 text-center">
                  {(typeof p.empty === 'string' || p.empty == null || rows.length > 0) && <span className="inline-grid place-items-center w-11 h-11 rounded-full bg-head text-muted mb-2"><Icon name="inbox" size={22} /></span>}
                  {rows.length && (q || condChips.length || filter !== 'all') ? <div className="text-sm text-muted">No rows match these filters.</div> : <EmptyText>{p.empty ?? 'Nothing to show.'}</EmptyText>}
                  {rows.length > 0 && (q || condChips.length > 0 || filter !== 'all') && <button type="button" className="btn btn-sm mt-3" onClick={() => { setQ(''); setConds([]); setFilter('all') }}>Clear filters</button>}
                </td></tr>
              )}
              {!p.loading && shown.map((r) => {
                const id = rowKey(r)
                const isSel = p.selectedRowId === id || sel.has(id)
                return (
                  <tr key={id} className={`group/row border-t border-line first:border-t-0 transition-colors hover:bg-head/60 ${p.onRowClick ? 'cursor-pointer' : ''}`} style={{ background: isSel ? 'var(--sel)' : undefined }}
                    onClick={p.onRowClick ? () => p.onRowClick!(r) : undefined}>
                    {p.selectable && (
                      <td className={`${cell} pl-4 pr-1`} onClick={(e) => e.stopPropagation()}>
                        <input type="checkbox" className="check" aria-label={`Select ${p.rowLabel ? p.rowLabel(r) : id}`} checked={sel.has(id)} onChange={() => toggle(id)} />
                      </td>
                    )}
                    {visible.map((c) => <td key={c.key} style={widths[c.key] ? { maxWidth: widths[c.key] } : undefined} className={`${cell} ${cellWrap(c)} ${widths[c.key] ? 'overflow-hidden text-ellipsis' : ''} ${c.align === 'right' ? 'text-right tabular-nums' : ''} ${c.className ?? ''}`}>{c.render ? c.render(r) : String(cellValue(c, r) ?? '—') || '—'}</td>)}
                    {hasActions && <td className={`${cell} pr-4 text-right whitespace-nowrap`}>{renderActions(r)}</td>}
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      ) : (
        <div className={layout === 'grid' ? 'grid gap-3 p-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]' : 'flex flex-col'}>
          {p.loading && Array.from({ length: 4 }, (_, i) => <div key={i} className="m-3 h-20 rounded-xl bg-head animate-pulse" aria-hidden="true" />)}
          {!p.loading && shown.length === 0 && <Empty>{p.empty ?? 'Nothing to show.'}</Empty>}
          {!p.loading && shown.map((r) => {
            const id = rowKey(r)
            const [head, ...rest] = visible.filter((c) => c.header)
            const body = (
              <>
                <div className="flex items-start gap-2.5">
                  {p.selectable && <input type="checkbox" className="check mt-0.5" aria-label={`Select ${p.rowLabel ? p.rowLabel(r) : id}`} checked={sel.has(id)} onChange={() => toggle(id)} onClick={(e) => e.stopPropagation()} />}
                  <div className="flex-1 min-w-0 font-semibold text-sm truncate">{head ? (head.render ? head.render(r) : String(cellValue(head, r) ?? '')) : id}</div>
                  {renderActions(r)}
                </div>
                <dl className={`m-0 mt-2 grid gap-x-4 gap-y-1 text-xs ${layout === 'grid' ? 'grid-cols-[max-content_1fr]' : 'grid-cols-[repeat(auto-fill,minmax(160px,1fr))]'}`}>
                  {rest.map((c) => layout === 'grid'
                    ? <Fragment key={c.key}><dt className="text-muted">{c.header}</dt><dd className="m-0 min-w-0 truncate">{c.render ? c.render(r) : String(cellValue(c, r) ?? '—')}</dd></Fragment>
                    : <div key={c.key} className="min-w-0"><dt className="text-muted">{c.header}</dt><dd className="m-0 truncate">{c.render ? c.render(r) : String(cellValue(c, r) ?? '—')}</dd></div>)}
                </dl>
              </>
            )
            return (
              <div key={id} onClick={p.onRowClick ? () => p.onRowClick!(r) : undefined} style={{ background: sel.has(id) || p.selectedRowId === id ? 'var(--sel)' : undefined }}
                className={layout === 'grid' ? `rounded-xl border border-line p-4 transition-colors hover:border-accent/50 ${p.onRowClick ? 'cursor-pointer' : ''}` : `px-4 py-3 border-t border-line first:border-t-0 hover:bg-head/60 ${p.onRowClick ? 'cursor-pointer' : ''}`}>
                {body}
              </div>
            )
          })}
        </div>
      )}

      <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5 border-t border-line text-xs text-muted">
        <span className="tabular-nums">{filtered.length === 0 ? '0 results' : `${(page - 1) * pageSize + 1}–${Math.min(page * pageSize, filtered.length)} of ${filtered.length}`}{filtered.length !== rows.length ? ` (filtered from ${rows.length})` : ''}</span>
        <div className="flex flex-wrap gap-1.5 items-center">
          <span className="flex items-center gap-1.5">Rows
            <Select className="input !w-auto !min-h-8 !py-0 text-xs" aria-label="Rows per page" value={String(pageSize)} onChange={(e) => { setPageSize(Number(e.target.value)); setPage(1) }}>
              {PAGE_SIZES.map((n) => <option key={n} value={String(n)}>{n}</option>)}
            </Select>
          </span>
          <IconButton icon="left" label="Previous page" disabled={page <= 1} onClick={() => setPage(page - 1)} />
          {pageList(page, pages).map((n, i) => n === 0 ? <span key={`e${i}`} className="px-1">…</span> : (
            <button key={n} type="button" aria-current={n === page ? 'page' : undefined} onClick={() => setPage(n)}
              className={`min-w-8 h-8 px-2 rounded-lg border-0 cursor-pointer tabular-nums text-xs ${n === page ? 'bg-accent text-white font-semibold' : 'bg-transparent text-fg hover:bg-head'}`}>{n}</button>
          ))}
          <IconButton icon="chevron" label="Next page" disabled={page >= pages} onClick={() => setPage(page + 1)} />
        </div>
      </div>

      <FilterDrawer open={panel === 'filters'} onClose={() => setPanel(null)} columns={p.columns.filter((c) => c.header)} conds={conds} rows={rows} get={get}
        onApply={(c) => { setConds(c); setPage(1); setPanel(null) }} />
      <Drawer open={panel === 'columns'} onClose={() => setPanel(null)} title="Columns and density" width={380}
        footer={<><button type="button" className="btn" onClick={() => { setOrder(allKeys); setHidden(new Set(p.columns.filter((c) => c.hidden).map((c) => c.key))); setWidths({}); setDensity('comfortable') }}>Reset</button><button type="button" className="btn btn-primary" onClick={() => setPanel(null)}>Done</button></>}>
        <fieldset className="border-0 p-0 m-0 mb-6"><legend className="text-sm font-semibold mb-2">Density</legend>
          <div className="flex flex-col gap-2">
            {(Object.keys(DENSITY) as Density[]).map((d) => <label key={d} className="choice !py-2.5 !items-center"><input type="radio" name={`${tableId}-density`} checked={density === d} onChange={() => setDensity(d)} /><span className="choice-title !text-sm">{DENSITY[d].label}</span></label>)}
          </div>
        </fieldset>
        <div className="text-sm font-semibold mb-2">Columns</div>
        <p className="m-0 mb-3 text-xs text-muted">Show, hide and reorder. Drag a header’s edge in the table to resize it; double-click the edge to reset.</p>
        <ul className="m-0 p-0 list-none flex flex-col gap-1">
          {cols.filter((c) => c.header).map((c, i, arr) => (
            <li key={c.key} className="flex items-center gap-2 rounded-lg border border-line px-3 py-1.5">
              <label className="flex flex-1 items-center gap-2.5 text-sm cursor-pointer"><input type="checkbox" checked={!hidden.has(c.key)} onChange={(e) => setHidden((h) => { const n = new Set(h); if (e.target.checked) n.delete(c.key); else n.add(c.key); return n })} />{c.header}</label>
              <IconButton icon="up" label={`Move ${c.header} up`} disabled={i === 0} onClick={() => setOrder(move(cols.map((x) => x.key), c.key, arr[i - 1]?.key))} />
              <IconButton icon="down" label={`Move ${c.header} down`} disabled={i === arr.length - 1} onClick={() => setOrder(move(cols.map((x) => x.key), arr[i + 1]?.key, c.key))} />
            </li>
          ))}
        </ul>
      </Drawer>

      <Drawer open={!!saveAs} onClose={() => setSaveAs(null)} title={saveAs?.id ? 'Update view' : 'Save view'} width={400}
        footer={<><button type="button" className="btn" onClick={() => setSaveAs(null)}>Cancel</button><button type="button" className="btn btn-primary" disabled={!saveAs?.name.trim()} onClick={saveView}>Save view</button></>}>
        {saveAs && (
          <div className="flex flex-col gap-4">
            <label className="label"><span>Name</span><input className="input" maxLength={60} autoFocus value={saveAs.name} onChange={(e) => setSaveAs({ ...saveAs, name: e.target.value })} placeholder="e.g. Unhealthy on prod" /></label>
            <p className="m-0 text-xs text-muted">Saves the columns, their order and widths, filters, sorting, density, layout and rows per page.</p>
            <label className="choice"><input type="checkbox" checked={saveAs.is_default} onChange={(e) => setSaveAs({ ...saveAs, is_default: e.target.checked })} /><span><span className="choice-title">Open by default</span><span className="choice-hint">Use this view when the table opens.</span></span></label>
            <label className="choice"><input type="checkbox" checked={saveAs.shared} onChange={(e) => setSaveAs({ ...saveAs, shared: e.target.checked })} /><span><span className="choice-title">Share with the team</span><span className="choice-hint">Everyone in the organisation sees it. Needs the users permission.</span></span></label>
          </div>
        )}
      </Drawer>

      <ConfirmDialog open={!!confirm} onClose={() => setConfirm(null)} danger title={confirm?.title ?? ''} confirmLabel="Confirm"
        onConfirm={() => { confirm?.run(); setConfirm(null) }}>
        <p className="m-0">{confirm?.text}</p>
      </ConfirmDialog>
    </div>
  )
}

function move(keys: string[], a?: string, b?: string): string[] {
  if (!a || !b) return keys
  const out = keys.filter((k) => k !== a)
  out.splice(out.indexOf(b), 0, a)
  return out
}

function pageList(cur: number, total: number): number[] {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const s = new Set([1, total, cur - 1, cur, cur + 1].filter((n) => n >= 1 && n <= total))
  const sorted = [...s].sort((a, b) => a - b)
  return sorted.flatMap((n, i) => (i && n - sorted[i - 1] > 1 ? [0, n] : [n]))
}

function FilterDrawer<T>({ open, onClose, columns, conds, onApply, rows, get }: { open: boolean; onClose: () => void; columns: Column<T>[]; conds: Condition[]; onApply: (c: Condition[]) => void; rows: T[]; get: (r: T, k: string) => unknown }) {
  const [draft, setDraft] = useState<Condition[]>(conds)
  useEffect(() => { if (open) setDraft(conds.length ? conds : [{ col: columns[0]?.key ?? '', op: 'contains', value: '' }]) }, [open]) // eslint-disable-line react-hooks/exhaustive-deps
  const kindOf = (k: string) => columns.find((c) => c.key === k)?.kind
  const choices = (k: string) => {
    const col = columns.find((c) => c.key === k)
    if (col?.options) return col.options
    const vals = [...new Set(rows.map((r) => String(get(r, k) ?? '')).filter(Boolean))]
    return vals.length && vals.length <= 30 ? vals.sort() : null
  }
  const set = (i: number, patch: Partial<Condition>) => setDraft((d) => d.map((c, j) => (j === i ? { ...c, ...patch } : c)))
  return (
    <Drawer open={open} onClose={onClose} title="Filters" width={460}
      footer={<><button type="button" className="btn btn-ghost mr-auto" onClick={() => setDraft([])}>Clear all</button><button type="button" className="btn" onClick={onClose}>Cancel</button><button type="button" className="btn btn-primary" onClick={() => onApply(draft)}>Apply filters</button></>}>
      <p className="m-0 mb-4 text-sm text-muted">Rows must match every condition.</p>
      <div className="flex flex-col gap-3">
        {draft.map((c, i) => {
          const needs = OPS.find((o) => o.id === c.op)?.needs
          const opts = choices(c.col)
          return (
            <div key={i} className="rounded-xl border border-line p-3 flex flex-col gap-2">
              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted w-10">{i ? 'and' : 'where'}</span>
                <Select className="input flex-1 !min-h-9 text-sm" aria-label="Column" value={c.col} onChange={(e) => set(i, { col: e.target.value, value: '' })}>
                  {columns.map((col) => <option key={col.key} value={col.key}>{col.header}</option>)}
                </Select>
                <IconButton icon="trash" label="Remove condition" danger onClick={() => setDraft(draft.filter((_, j) => j !== i))} />
              </div>
              <div className="flex items-center gap-2 pl-12">
                <Select className="input !w-40 !min-h-9 text-sm" aria-label="Condition" value={c.op} onChange={(e) => set(i, { op: e.target.value as Op, value: e.target.value === 'in' ? [] : '' })}>
                  {OPS.filter((o) => (kindOf(c.col) === 'date' ? ['after', 'before', 'empty', 'not_empty', 'equals'].includes(o.id) : kindOf(c.col) === 'number' ? !['after', 'before', 'starts'].includes(o.id) : !['after', 'before', 'gt', 'lt'].includes(o.id)) && (o.id !== 'in' || opts)).map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                </Select>
                {needs === 'multi' && opts ? null : needs !== 'none' && (
                  <input className="input flex-1 !min-h-9 text-sm" aria-label="Value" type={needs === 'date' ? 'date' : needs === 'number' ? 'number' : 'text'}
                    list={opts && needs === 'text' ? `dl-${i}` : undefined} value={String(c.value ?? '')} onChange={(e) => set(i, { value: e.target.value })} />
                )}
                {opts && needs === 'text' && <datalist id={`dl-${i}`}>{opts.map((o) => <option key={o} value={o} />)}</datalist>}
              </div>
              {needs === 'multi' && opts && (
                <div className="flex flex-wrap gap-1.5 pl-12">
                  {opts.map((o) => {
                    const on = Array.isArray(c.value) && c.value.includes(o)
                    return <button key={o} type="button" className="chip !min-h-7 !text-xs" aria-pressed={on} onClick={() => set(i, { value: on ? (c.value as string[]).filter((x) => x !== o) : [...((c.value as string[]) ?? []), o] })}>{o}</button>
                  })}
                </div>
              )}
            </div>
          )
        })}
        <button type="button" className="btn btn-sm self-start gap-1.5" onClick={() => setDraft([...draft, { col: columns[0]?.key ?? '', op: 'contains', value: '' }])}><Icon name="plus" size={15} />Add condition</button>
      </div>
    </Drawer>
  )
}
