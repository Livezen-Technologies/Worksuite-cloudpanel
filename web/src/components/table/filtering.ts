/** TBL-3: condition engine shared by every table. Pure functions, unit-tested. */
export type Op = 'contains' | 'equals' | 'not_equals' | 'starts' | 'gt' | 'lt' | 'empty' | 'not_empty' | 'before' | 'after' | 'in'
export interface Condition { col: string; op: Op; value?: string | string[] | null }
export interface SortKey { key: string; dir: 1 | -1 }

export const OPS: { id: Op; label: string; needs: 'text' | 'number' | 'date' | 'multi' | 'none' }[] = [
  { id: 'contains', label: 'contains', needs: 'text' },
  { id: 'equals', label: 'is', needs: 'text' },
  { id: 'not_equals', label: 'is not', needs: 'text' },
  { id: 'starts', label: 'starts with', needs: 'text' },
  { id: 'in', label: 'is any of', needs: 'multi' },
  { id: 'gt', label: 'greater than', needs: 'number' },
  { id: 'lt', label: 'less than', needs: 'number' },
  { id: 'after', label: 'after', needs: 'date' },
  { id: 'before', label: 'before', needs: 'date' },
  { id: 'empty', label: 'is empty', needs: 'none' },
  { id: 'not_empty', label: 'is not empty', needs: 'none' },
]
export const opLabel = (op: Op) => OPS.find((o) => o.id === op)?.label ?? op

const num = (v: unknown) => {
  if (typeof v === 'number') return v
  const n = parseFloat(String(v ?? '').replace(/[^0-9.+-eE]/g, ''))
  return Number.isFinite(n) ? n : NaN
}
const time = (v: unknown) => { const t = Date.parse(String(v ?? '')); return Number.isFinite(t) ? t : NaN }

export function matches(raw: unknown, c: Condition): boolean {
  const s = raw == null ? '' : String(raw)
  const lo = s.toLowerCase()
  const val = Array.isArray(c.value) ? c.value : String(c.value ?? '')
  switch (c.op) {
    case 'contains': return lo.includes(String(val).toLowerCase())
    case 'equals': return lo === String(val).toLowerCase()
    case 'not_equals': return lo !== String(val).toLowerCase()
    case 'starts': return lo.startsWith(String(val).toLowerCase())
    case 'in': return (Array.isArray(val) ? val : String(val).split(',')).map((x) => x.trim().toLowerCase()).filter(Boolean).includes(lo)
    case 'gt': return num(raw) > num(val)
    case 'lt': return num(raw) < num(val)
    case 'after': return time(raw) > time(val)
    case 'before': return time(raw) < time(val)
    case 'empty': return s.trim() === '' || s === '—'
    case 'not_empty': return !(s.trim() === '' || s === '—')
  }
}

export function applyConditions<T>(rows: T[], conds: Condition[], get: (row: T, col: string) => unknown): T[] {
  const live = conds.filter((c) => c.col && (OPS.find((o) => o.id === c.op)?.needs === 'none' || (Array.isArray(c.value) ? c.value.length : String(c.value ?? '') !== '')))
  return live.length ? rows.filter((r) => live.every((c) => matches(get(r, c.col), c))) : rows
}

export function multiSort<T>(rows: T[], keys: SortKey[], get: (row: T, col: string) => unknown): T[] {
  if (!keys.length) return rows
  return [...rows].sort((a, b) => {
    for (const k of keys) {
      const x = get(a, k.key) ?? '', y = get(b, k.key) ?? ''
      const d = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true })
      if (d) return d * k.dir
    }
    return 0
  })
}

/** Click: sort by this column only (asc → desc → off). Shift-click: add it as a further key. */
export function nextSort(cur: SortKey[], key: string, add: boolean): SortKey[] {
  const i = cur.findIndex((s) => s.key === key)
  if (!add) return i === 0 && cur.length === 1 ? (cur[0].dir === 1 ? [{ key, dir: -1 as const }] : []) : [{ key, dir: 1 as const }]
  if (i < 0) return [...cur, { key, dir: 1 as const }].slice(0, 5)
  return cur[i].dir === 1 ? cur.map((s, j): SortKey => (j === i ? { key, dir: -1 } : s)) : cur.filter((_, j) => j !== i)
}
