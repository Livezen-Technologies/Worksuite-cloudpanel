import { useState, type ReactNode } from 'react'
import { NavLink, Outlet, useLocation, Link } from 'react-router'
import { Icon } from './Icon'
import { Drawer } from './Overlay'
import { CommandPalette, type PageLink } from './CommandPalette'
import { useTheme } from '../lib/theme'
import { useAuth } from '../lib/auth'
import { useEffect } from 'react'

export interface RailItem { label: string; to: string; icon: string; badge?: string | number; end?: boolean }

export interface NavItem { label: string; to: string; end?: boolean; icon?: string; group?: string; badge?: string | number }

function NavList({ items, onPick, touch }: { items: NavItem[]; onPick?: () => void; touch?: boolean }) {
  return (
    <ul className="list-none m-0 p-0 flex flex-col gap-0.5">
      {items.map((n, i) => (
        <li key={n.to}>
          {/* A heading starts each section; the first needs no separating space. */}
          {n.group && (
            <div className={`px-2.5 pb-1 text-[11px] font-semibold uppercase tracking-wider text-muted ${i === 0 ? '' : 'pt-4'}`}>
              {n.group}
            </div>
          )}
          <NavLink to={n.to} end={n.end} onClick={onPick}
            className={({ isActive }) => `flex items-center gap-2.5 ${touch ? 'min-h-[44px]' : 'min-h-9'} px-2.5 rounded-lg text-sm no-underline transition-colors ${isActive ? 'font-semibold bg-sel text-accent-fg' : 'font-medium text-muted hover:text-fg hover:bg-head'}`}>
            {({ isActive }) => (<>
              <span aria-hidden="true" className="flex-none" style={{ color: isActive ? 'var(--accent)' : 'currentColor' }}>
                <Icon name={n.icon ?? 'grid'} size={18} />
              </span>
              <span className="flex-1 truncate">{n.label}</span>
              {n.badge !== undefined && n.badge !== '' && (
                <span className="flex-none rounded-full bg-n-bg px-2 py-0.5 text-[11px] font-semibold text-n-fg tabular-nums">{n.badge}</span>
              )}
            </>)}
          </NavLink>
        </li>
      ))}
    </ul>
  )
}

export function Brand({ title, subtitle }: { title: string; subtitle?: string }) {
  return (
    <div className="flex items-center gap-2.5 px-2 py-1">
      <span className="w-[34px] h-[34px] rounded-[9px] bg-accent flex items-center justify-center text-white flex-none"><Icon name="logo" size={20} /></span>
      <div className="min-w-0"><div className="font-bold text-[15px]">{title}</div>{subtitle && <div className="text-xs text-muted truncate">{subtitle}</div>}</div>
    </div>
  )
}

/** The narrow icon rail: one entry per major area of the product. */
function Rail({ items }: { items: RailItem[] }) {
  return (
    <nav aria-label="Areas" className="hidden lg:flex w-14 flex-none flex-col items-center gap-1 border-r border-line bg-side py-4 sticky top-16 h-[calc(100vh-4rem)]">
      {items.map((r) => (
        <NavLink key={r.to} to={r.to} end={r.end} title={r.label} aria-label={r.label}
          className={({ isActive }) => `relative grid h-11 w-11 place-content-center rounded-xl no-underline transition-colors ${isActive ? 'bg-sel text-accent-fg' : 'text-muted hover:text-fg hover:bg-head'}`}>
          <Icon name={r.icon} size={20} />
          {r.badge !== undefined && r.badge !== '' && r.badge !== 0 && (
            <span className="absolute -right-0.5 -top-0.5 min-w-[18px] rounded-full bg-danger px-1 text-center text-[10px] font-bold leading-[18px] text-white">{r.badge}</span>
          )}
        </NavLink>
      ))}
    </nav>
  )
}

export function Shell({ nav, rail, brand, status, sidebarFooter, headerExtra, touch = false, label }: { nav: NavItem[]; rail?: RailItem[]; brand: { title: string; subtitle?: string }; status?: { label: string; tone: 'g' | 'y' | 'r' }; sidebarFooter?: ReactNode; headerExtra?: ReactNode; touch?: boolean; label: string }) {
  const [menu, setMenu] = useState(false)
  const [palette, setPalette] = useState(false)
  const { theme, toggle } = useTheme()
  const { me, logout } = useAuth()
  const loc = useLocation()
  useEffect(() => setMenu(false), [loc.pathname])
  const initials = (me?.user?.name ?? '?').split(/\s+/).map((x) => x[0]).join('').slice(0, 2).toUpperCase()
  const pages: PageLink[] = nav.map((n) => ({ label: n.label, to: n.to }))
  const side = (
    <div className="flex flex-col gap-6 h-full">
      <Brand title={brand.title} subtitle={brand.subtitle ?? me?.organization?.name} />
      <nav aria-label={label}><NavList items={nav} touch={touch} onPick={() => setMenu(false)} /></nav>
      {sidebarFooter && <div className="mt-auto">{sidebarFooter}</div>}
    </div>
  )
  return (
    <div className={`min-h-screen bg-bg text-fg flex flex-col ${touch ? 'panel-touch' : ''}`}>
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-[80] focus:p-3 focus:bg-card">Skip to content</a>
        <header className="h-16 box-border px-4 sm:px-6 flex items-center gap-3 border-b border-line bg-side sticky top-0 z-30">
          <button type="button" className="btn btn-sm lg:hidden border-0 !min-h-[44px] !min-w-[44px]" aria-label="Open menu" aria-expanded={menu} onClick={() => setMenu(true)}><Icon name="menu" size={22} /></button>

          {/* Brand lockup, on wide screens where the sidebar heading is not the first thing read. */}
          <div className="hidden lg:flex items-center gap-2 flex-none">
            {/* Four quadrants, per the canvas mark. */}
            <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" className="flex-none">
              <rect x="2" y="2" width="9" height="9" rx="2" fill="var(--accent)" />
              <rect x="13" y="2" width="9" height="9" rx="2" fill="var(--accent)" opacity=".7" />
              <rect x="2" y="13" width="9" height="9" rx="2" fill="var(--accent)" opacity=".7" />
              <rect x="13" y="13" width="9" height="9" rx="2" fill="var(--accent)" />
            </svg>
            <span className="text-[19px] font-semibold tracking-tight">{brand.title}</span>
            {brand.subtitle && (
              <span className="rounded-md bg-n-bg px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-n-fg">{brand.subtitle}</span>
            )}
          </div>

          {/* Which tenant the session is acting as - the canvas puts this next
              to the brand, and the panel spans tenants for staff. */}
          {me?.organization?.name && (
            <Link to="/admin/settings" title="Organisation settings"
              className="hidden xl:flex flex-none items-center gap-2 rounded-lg bg-head px-2 py-1.5 text-sm font-semibold no-underline text-fg hover:bg-sel">
              <span className="grid h-[26px] w-[26px] place-content-center rounded-md bg-accent text-[13px] text-white" aria-hidden="true">
                {me.organization.name.slice(0, 1).toUpperCase()}
              </span>
              <span className="max-w-[14rem] truncate">{me.organization.name}</span>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="text-muted" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
            </Link>
          )}

          {/* Search is the primary way into anything, so it sits centre. */}
          <button type="button" onClick={() => setPalette(true)}
            className="input !rounded-full mx-auto flex max-w-xl flex-1 items-center gap-2.5 text-left text-muted lg:flex-none lg:w-[min(36rem,40vw)]"
            aria-label="Search (Ctrl K)">
            <Icon name="search" size={16} /><span className="flex-1 truncate text-sm">Search servers, containers, apps…</span>
            <kbd className="hidden sm:inline rounded border border-line px-1.5 py-0.5 font-sans text-[11px]">⌘K</kbd>
          </button>

          <div className="ml-auto flex flex-none items-center gap-1">
            {status && (
              <span className="hidden xl:flex items-center gap-1.5 pr-2 text-xs font-semibold text-muted" title="Agent heartbeat">
                <span className={`h-2 w-2 rounded-full ${status.tone === 'g' ? 'bg-g-fg' : status.tone === 'y' ? 'bg-y-fg' : 'bg-r-fg'}`} aria-hidden="true" />
                {status.label}
              </span>
            )}
            <Link to="/admin/catalog" title="Install an application" aria-label="Install an application"
              className="btn btn-sm border-0 !min-h-11 !min-w-11 no-underline text-muted hover:text-fg">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </Link>
            {headerExtra}
            <button type="button" className="btn btn-sm border-0 !min-h-11 !min-w-11" onClick={toggle}
              aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}>
              <Icon name={theme === 'dark' ? 'sun' : 'moon'} size={20} />
            </button>
            <div className="mx-1 hidden h-6 w-px bg-line sm:block" aria-hidden="true" />
            <Link to="/admin/account" className="flex items-center gap-2 rounded-lg px-1 py-1 no-underline text-fg hover:bg-head" title={me?.user?.email}>
              <span className="relative flex-none">
                <span className="grid h-8 w-8 place-content-center rounded-full bg-accent text-[13px] font-semibold text-white" aria-hidden="true">{initials}</span>
                {/* Presence: the session is live, which is what the dot in the reference conveys. */}
                <span className="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-side bg-g-fg" aria-hidden="true" />
              </span>
              <span className="hidden max-w-[12rem] truncate text-sm font-semibold sm:inline">{me?.user?.name}</span>
              <svg className="hidden sm:block flex-none text-muted" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </Link>
            <button type="button" className="btn btn-sm border-0 !min-h-11 !min-w-11" aria-label="Sign out" onClick={() => logout()}>
              <Icon name="logout" size={20} />
            </button>
          </div>
        </header>
      <div className="flex flex-1 min-h-0">
      {rail && <Rail items={rail} />}
      <aside className="hidden lg:block w-[272px] flex-none box-border px-4 py-5 bg-side border-r border-line sticky top-16 h-[calc(100vh-4rem)] overflow-y-auto">{side}</aside>
      <Drawer open={menu} onClose={() => setMenu(false)} title="Menu" side="left" width={288}>{side}</Drawer>
      <div className="flex-1 min-w-0 flex flex-col">
        <main id="main" className="p-4 sm:p-8 flex flex-col gap-6 w-full box-border">
          <Outlet />
        </main>
      </div>
      </div>
      <CommandPalette pages={pages} open={palette} setOpen={setPalette} remote={!touch} />
    </div>
  )
}
