import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router'
import { api } from '../api/client'
import { Modal } from './Overlay'
import { Badge } from './Badge'

export interface PageLink { label: string; to: string }
interface Hit { type: string; id?: string; title: string; subtitle?: string; to: string }

function hitPath(h: Record<string, unknown>): string {
  const type = String(h.type ?? '')
  const id = String(h.id ?? '')
  if (typeof h.url === 'string' && h.url.startsWith('/')) return h.url
  const map: Record<string, string> = {
    server: `/admin/servers/${id}`, container: `/admin/containers?id=${id}`, application: `/admin/applications?id=${id}`,
    app: `/app/apps/${id}`, domain: '/admin/domains', compose_project: '/admin/compose', database: '/admin/databases',
    user: '/admin/users', volume: '/admin/volumes', network: '/admin/networks', image: '/admin/images',
  }
  return map[type] ?? '/admin'
}

/** Ctrl/⌘K command palette. Pages are matched locally; resources come from GET /search?q=. */
export function CommandPalette({ pages, open, setOpen, remote = true }: { pages: PageLink[]; open: boolean; setOpen: (v: boolean) => void; remote?: boolean }) {
  const [q, setQ] = useState('')
  const [hits, setHits] = useState<Hit[]>([])
  const [active, setActive] = useState(0)
  const nav = useNavigate()

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setOpen(true) }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [setOpen])

  useEffect(() => { if (!open) { setQ(''); setHits([]) } }, [open])

  useEffect(() => {
    if (!remote || q.trim().length < 2) { setHits([]); return }
    const ctl = new AbortController()
    const t = setTimeout(async () => {
      try {
        const res = await api.get<{ data: Record<string, unknown>[] | Record<string, Record<string, unknown>[]> }>('/search', { q }, ctl.signal)
        const raw = res?.data ?? []
        const list: Record<string, unknown>[] = Array.isArray(raw) ? raw : Object.entries(raw).flatMap(([type, xs]) => (xs ?? []).map((x) => ({ type: type.replace(/s$/, ''), ...x })))
        setHits(list.slice(0, 12).map((h) => ({ type: String(h.type ?? 'result'), id: h.id as string, title: String(h.title ?? h.name ?? h.id), subtitle: (h.subtitle ?? h.detail) as string | undefined, to: hitPath(h) })))
      } catch { setHits([]) }
    }, 200)
    return () => { clearTimeout(t); ctl.abort() }
  }, [q, remote])

  const results = useMemo(() => {
    const n = q.trim().toLowerCase()
    const p: Hit[] = pages.filter((x) => !n || x.label.toLowerCase().includes(n)).map((x) => ({ type: 'page', title: x.label, to: x.to }))
    return [...p.slice(0, n ? 6 : 8), ...hits]
  }, [q, pages, hits])
  useEffect(() => setActive(0), [results.length])

  const go = (h?: Hit) => { if (!h) return; setOpen(false); nav(h.to) }

  return (
    <Modal open={open} onClose={() => setOpen(false)} title="Search" width={600}>
      <input className="input" autoFocus data-autofocus role="combobox" aria-expanded="true" aria-controls="cmdk-list" aria-label="Search servers, containers, apps and pages"
        aria-activedescendant={results[active] ? `cmdk-${active}` : undefined}
        placeholder="Search servers, containers, apps…" value={q} onChange={(e) => setQ(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(results.length - 1, a + 1)) }
          if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(0, a - 1)) }
          if (e.key === 'Enter') { e.preventDefault(); go(results[active]) }
        }} />
      <ul id="cmdk-list" role="listbox" className="list-none m-0 mt-3 p-0 max-h-[50vh] overflow-y-auto">
        {results.length === 0 && <li className="p-3 text-muted text-sm">No matches. Try another word.</li>}
        {results.map((h, i) => (
          <li key={h.type + h.to + i} id={`cmdk-${i}`} role="option" aria-selected={i === active}
            onMouseEnter={() => setActive(i)} onClick={() => go(h)}
            className="flex items-center gap-3 px-3 min-h-[44px] rounded-lg cursor-pointer" style={{ background: i === active ? 'var(--sel)' : undefined }}>
            <Badge tone={h.type === 'page' ? 'n' : 'b'}>{h.type}</Badge>
            <span className="font-semibold truncate">{h.title}</span>
            {h.subtitle && <span className="text-muted text-xs truncate">{h.subtitle}</span>}
          </li>
        ))}
      </ul>
    </Modal>
  )
}
