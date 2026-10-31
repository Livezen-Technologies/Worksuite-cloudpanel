import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Icon } from './Icon'

export interface Column<T> {
  key: string
  header: string
  render?: (row: T) => ReactNode
  /** Value used for sorting and default rendering. */
  value?: (row: T) => string | number | null | undefined
  sortable?: boolean
  className?: string
}
export interface FilterDef<T> { id: string; label: string; test: (row: T) => boolean }
export interface BulkAction { label: string; onClick: (ids: string[]) => void; danger?: boolean }

export interface DataTableProps<T> {
  rows: T[]
  columns: Column<T>[]
  rowKey: (row: T) => string
  label: string
  searchKeys?: (row: T) => string
  searchPlaceholder?: string
  filters?: FilterDef<T>[]
  pageSize?: number
  selectable?: boolean
  bulkActions?: BulkAction[]
  onRowClick?: (row: T) => void
  selectedRowId?: string | null
  toolbar?: ReactNode
  loading?: boolean
  empty?: ReactNode
  minWidth?: number
  rowLabel?: (row: T) => string
}

function cellValue<T>(c: Column<T>, r: T): string | number | null | undefined {
  if (c.value) return c.value(r)
  const v = (r as Record<string, unknown>)[c.key]
  return typeof v === 'number' || typeof v === 'string' ? v : v == null ? '' : String(v)
}

/** Columns of free text wrap; everything else (names, hosts, states, numbers) stays on one line
 *  so identifiers like "prod-web-01" never break at a hyphen. The table scrolls in its card. */
const PROSE = /^(detail|details|message|description|reason|notes?|error|summary|comment|event|what)$/i
const cellWrap = (c: { header?: unknown; className?: string }) =>
  /whitespace-/.test(c.className ?? '') ? '' : typeof c.header === 'string' && PROSE.test(c.header) ? 'min-w-[16rem]' : 'whitespace-nowrap'

/** Data table with search, filter chips, sorting, pagination and multi-select bulk actions. Scrolls inside its card. */
export function DataTable<T>(p: DataTableProps<T>) {
  const { rows, columns, rowKey, pageSize = 10 } = p
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState<string>('all')
  const [sort, setSort] = useState<{ key: string; dir: 1 | -1 } | null>(null)
  const [page, setPage] = useState(1)
  const [sel, setSel] = useState<Set<string>>(new Set())

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase()
    const f = p.filters?.find((x) => x.id === filter)
    let out = rows.filter((r) => (!f || f.test(r)) && (!needle || (p.searchKeys ? p.searchKeys(r) : columns.map((c) => String(cellValue(c, r) ?? '')).join(' ')).toLowerCase().includes(needle)))
    if (sort) {
      const col = columns.find((c) => c.key === sort.key)
      if (col) out = [...out].sort((a, b) => {
        const x = cellValue(col, a) ?? '', y = cellValue(col, b) ?? ''
        return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true })) * sort.dir
      })
    }
    return out
  }, [rows, q, filter, sort, columns, p.filters, p.searchKeys])

  const pages = Math.max(1, Math.ceil(filtered.length / pageSize))
  useEffect(() => { if (page > pages) setPage(pages) }, [page, pages])
  const shown = filtered.slice((page - 1) * pageSize, page * pageSize)
  const ids = new Set(rows.map(rowKey))
  const selected = [...sel].filter((id) => ids.has(id))
  const allOnPage = shown.length > 0 && shown.every((r) => sel.has(rowKey(r)))

  const toggleSort = (k: string) => setSort((s) => (!s || s.key !== k ? { key: k, dir: 1 } : s.dir === 1 ? { key: k, dir: -1 } : null))
  const toggle = (id: string) => setSel((s) => { const n = new Set(s); if (n.has(id)) n.delete(id); else n.add(id); return n })

  return (
    <div className="card overflow-hidden min-w-0">
      {p.filters && p.filters.length > 0 && (
        <div className="flex flex-wrap gap-2 px-4 pt-4" role="group" aria-label={`${p.label} filters`}>
          {[{ id: 'all', label: 'All' }, ...p.filters].map((f) => {
            const n = f.id === 'all' ? rows.length : rows.filter(p.filters!.find((x) => x.id === f.id)!.test).length
            return <button key={f.id} type="button" className="chip" aria-pressed={filter === f.id} onClick={() => { setFilter(f.id); setPage(1) }}>{f.label} <span className="opacity-75 ml-1">{n}</span></button>
          })}
        </div>
      )}
      <div className="flex flex-wrap items-center gap-2.5 p-4 border-b border-line">
        <label className="flex items-center gap-2 flex-1 min-w-[180px] h-10 px-3 rounded-lg border border-line bg-bg text-muted">
          <Icon name="search" size={16} />
          <input aria-label={`Search ${p.label}`} placeholder={p.searchPlaceholder ?? 'Search…'} value={q}
            onChange={(e) => { setQ(e.target.value); setPage(1) }}
            className="flex-1 min-w-0 border-0 outline-0 bg-transparent text-fg text-sm" />
        </label>
        {p.toolbar}
        {p.selectable && selected.length > 0 && (
          <>
            <span className="text-[13px] text-muted" aria-live="polite">{selected.length} selected</span>
            {p.bulkActions?.map((a) => (
              <button key={a.label} type="button" className={`btn btn-sm ${a.danger ? 'btn-danger' : ''}`} onClick={() => { a.onClick(selected); setSel(new Set()) }}>{a.label}</button>
            ))}
          </>
        )}
      </div>
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-[13px]" style={{ minWidth: p.minWidth ?? 640 }} aria-label={p.label}>
          <thead>
            <tr className="text-left text-fg text-[13px] bg-head">
              {p.selectable && (
                <th className="py-3 pl-4 pr-1 w-8">
                  <input type="checkbox" className="check" aria-label="Select all on this page" checked={allOnPage}
                    onChange={() => setSel((s) => { const n = new Set(s); shown.forEach((r) => (allOnPage ? n.delete(rowKey(r)) : n.add(rowKey(r)))); return n })} />
                </th>
              )}
              {columns.map((c) => {
                const active = sort?.key === c.key
                return (
                  <th key={c.key} scope="col" className={`py-3 px-3 font-semibold whitespace-nowrap ${c.className ?? ''}`}
                    aria-sort={active ? (sort!.dir === 1 ? 'ascending' : 'descending') : undefined}>
                    {c.sortable !== false && c.header ? (
                      <button type="button" className="group -mx-1.5 inline-flex min-h-7 items-center gap-1 rounded-md border-0 bg-transparent px-1.5 font-semibold text-fg cursor-pointer hover:bg-[var(--ctl-hover)]" onClick={() => toggleSort(c.key)}>
                        {c.header}
                        <svg aria-hidden="true" width="14" height="14" viewBox="0 0 20 20" fill="currentColor" className={active ? 'text-fg' : 'text-muted opacity-0 group-hover:opacity-100'}>
                          {active && sort!.dir === -1 ? <path fillRule="evenodd" d="M14.77 12.79a.75.75 0 0 1-1.06-.02L10 8.83l-3.71 3.94a.75.75 0 1 1-1.08-1.04l4.25-4.5a.75.75 0 0 1 1.08 0l4.25 4.5a.75.75 0 0 1-.02 1.06Z" clipRule="evenodd" /> : <path fillRule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clipRule="evenodd" />}
                        </svg>
                      </button>
                    ) : c.header ? c.header : <span className="sr-only">Actions</span>}
                  </th>
                )
              })}
            </tr>
          </thead>
          <tbody>
            {p.loading && <tr><td colSpan={columns.length + 1} className="p-6 text-center text-muted">Loading…</td></tr>}
            {!p.loading && shown.length === 0 && <tr><td colSpan={columns.length + 1} className="p-6 text-center text-muted">{p.empty ?? 'Nothing to show.'}</td></tr>}
            {shown.map((r) => {
              const id = rowKey(r)
              const isSel = p.selectedRowId === id
              return (
                <tr key={id} className={`border-t border-line transition-colors ${p.onRowClick ? 'cursor-pointer hover:bg-head' : ''}`} style={{ background: isSel ? 'var(--sel)' : undefined }}
                  onClick={p.onRowClick ? () => p.onRowClick!(r) : undefined}>
                  {p.selectable && (
                    <td className="py-3.5 pl-4 pr-1" onClick={(e) => e.stopPropagation()}>
                      <input type="checkbox" className="check" aria-label={`Select ${p.rowLabel ? p.rowLabel(r) : id}`} checked={sel.has(id)} onChange={() => toggle(id)} />
                    </td>
                  )}
                  {columns.map((c) => <td key={c.key} className={`py-3 px-3 ${cellWrap(c)} ${c.className ?? ''}`}>{c.render ? c.render(r) : String(cellValue(c, r) ?? '—')}</td>)}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-t border-line text-xs text-muted">
        <span>{filtered.length === 0 ? '0 results' : `${(page - 1) * pageSize + 1}–${Math.min(page * pageSize, filtered.length)} of ${filtered.length}`}</span>
        <div className="flex gap-2 items-center">
          <button type="button" className="btn btn-sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</button>
          <span>Page {page} of {pages}</span>
          <button type="button" className="btn btn-sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>Next</button>
        </div>
      </div>
    </div>
  )
}
