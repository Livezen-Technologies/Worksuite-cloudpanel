import { useEffect, useRef, useState } from 'react'
import type { Me } from '../api/types'

/** Staff SSO: the shared WorkSuite Cloud launcher feed; editable data, cached 5 minutes by the server. */
export const LAUNCHER_FEED = 'https://account.worksuitecloud.com/launcher/v1/apps'

export interface LauncherApp { id: string; name: string; url: string; icon?: string }

/** Built-in fallback, used when the feed cannot be reached. */
export const FALLBACK_APPS: LauncherApp[] = [
  { id: 'admin', name: 'Cloud Admin', url: 'https://account.worksuitecloud.com/admin/' },
  { id: 'panel', name: 'CloudPanel', url: 'https://panel.livezentech.com/admin' },
  { id: 'iot', name: 'IoT', url: 'https://iot.worksuitecloud.com/' },
  { id: 'desk', name: 'Desk', url: 'https://desk.worksuitecloud.com/' },
  { id: 'aistudio', name: 'AI Studio', url: 'https://aistudio.worksuitecloud.com/' },
  { id: 'updates', name: 'Update Center', url: 'https://download.worksuitecloud.com/admin/' },
]

const CURRENT = 'panel'
const COLORS = ['#2563EB', '#0EA5E9', '#16A34A', '#9333EA', '#EA580C', '#DB2777', '#0D9488', '#CA8A04']

/** Platform staff only: the launcher is for WorkSuite Cloud admins, not customers or org members. */
export const STAFF_ROLES = ['super_admin', 'infra_admin', 'devops']
export const isStaff = (me: Me | null) => !!me && (STAFF_ROLES.includes(String(me.role)) || (me.organizations ?? []).some((o) => STAFF_ROLES.includes(o.role)))

const valid = (a: unknown): a is LauncherApp => {
  const x = a as LauncherApp
  return !!x && typeof x.id === 'string' && typeof x.name === 'string' && typeof x.url === 'string' && /^https:\/\//.test(x.url)
}

let cache: LauncherApp[] | null = null
async function loadApps(): Promise<LauncherApp[]> {
  if (cache) return cache
  try {
    const r = await fetch(LAUNCHER_FEED, { credentials: 'omit' })
    const j = r.ok ? await r.json() : null
    const apps = Array.isArray(j?.apps) ? j.apps.filter(valid) : []
    cache = apps.length ? apps : FALLBACK_APPS
  } catch { cache = FALLBACK_APPS }
  return cache ?? FALLBACK_APPS
}
/** Tests only. */
export const resetLauncherCache = () => { cache = null }

/** The 9-dot launcher in the admin header: a popover grid of the WorkSuite Cloud apps. */
export function AppLauncher() {
  const [open, setOpen] = useState(false)
  const [apps, setApps] = useState<LauncherApp[]>(FALLBACK_APPS)
  const box = useRef<HTMLDivElement>(null)
  const btn = useRef<HTMLButtonElement>(null)
  useEffect(() => { let live = true; loadApps().then((a) => live && setApps(a)); return () => { live = false } }, [])
  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => { if (!box.current?.contains(e.target as Node)) setOpen(false) }
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') { setOpen(false); btn.current?.focus() } }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    box.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus()
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey) }
  }, [open])
  return (
    <div className="relative" ref={box}>
      <button ref={btn} type="button" aria-label="Apps" aria-expanded={open} aria-haspopup="menu" onClick={() => setOpen((v) => !v)}
        className="!min-h-11 !min-w-11 grid place-content-center rounded-lg border-0 bg-transparent text-white/70 hover:text-white hover:bg-white/10 cursor-pointer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          {[5, 12, 19].flatMap((y) => [5, 12, 19].map((x) => <circle key={`${x}-${y}`} cx={x} cy={y} r="2" />))}
        </svg>
      </button>
      {open && (
        <div role="menu" aria-label="Apps" className="card absolute right-0 top-full mt-2 w-[300px] max-w-[calc(100vw-32px)] p-3 text-fg shadow-pop" style={{ zIndex: 'var(--z-popover)' }}>
          <div className="grid grid-cols-3 gap-1">
            {apps.map((a, i) => {
              const current = a.id === CURRENT
              return (
                <a key={a.id} role="menuitem" href={a.url} aria-current={current ? 'page' : undefined} onClick={() => setOpen(false)}
                  className={`flex flex-col items-center gap-1.5 rounded-lg p-2.5 no-underline text-fg hover:bg-[color-mix(in_srgb,var(--accent)_8%,transparent)] ${current ? 'bg-sel font-semibold' : ''}`}>
                  {a.icon && /^https:\/\//.test(a.icon)
                    ? <img src={a.icon} alt="" className="h-10 w-10 rounded-lg" />
                    : <span aria-hidden="true" className="grid h-10 w-10 place-content-center rounded-lg text-sm font-bold text-white" style={{ background: COLORS[i % COLORS.length] }}>{a.name.slice(0, 2)}</span>}
                  <span className="text-xs text-center leading-tight">{a.name}</span>
                </a>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}
