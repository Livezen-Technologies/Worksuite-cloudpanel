import { useState, type ReactNode } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router'
import { Icon } from './Icon'
import { Drawer } from './Overlay'
import { CommandPalette, type PageLink } from './CommandPalette'
import { useTheme } from '../lib/theme'
import { useAuth } from '../lib/auth'
import { useEffect } from 'react'

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

export function Shell({ nav, brand, sidebarFooter, headerExtra, touch = false, label }: { nav: NavItem[]; brand: { title: string; subtitle?: string }; sidebarFooter?: ReactNode; headerExtra?: ReactNode; touch?: boolean; label: string }) {
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
    <div className={`min-h-screen bg-bg text-fg flex ${touch ? 'panel-touch' : ''}`}>
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-[80] focus:p-3 focus:bg-card">Skip to content</a>
      <aside className="hidden lg:block w-[272px] flex-none box-border px-4 py-5 bg-side border-r border-line sticky top-0 h-screen overflow-y-auto">{side}</aside>
      <Drawer open={menu} onClose={() => setMenu(false)} title="Menu" side="left" width={288}>{side}</Drawer>
      <div className="flex-1 min-w-0 flex flex-col">
        <header className="h-16 box-border px-4 sm:px-8 flex items-center gap-3 border-b border-line bg-bg sticky top-0 z-30">
          <button type="button" className="btn btn-sm lg:hidden border-0 !min-h-[44px] !min-w-[44px]" aria-label="Open menu" aria-expanded={menu} onClick={() => setMenu(true)}><Icon name="menu" size={22} /></button>
          <button type="button" onClick={() => setPalette(true)} className="flex-1 flex items-center gap-2.5 text-muted text-sm bg-transparent border-0 cursor-pointer text-left min-h-[44px] min-w-0" aria-label="Search (Ctrl K)">
            <Icon name="search" /><span className="flex-1 truncate">Search servers, containers, apps…</span>
            <kbd className="hidden sm:inline text-[11px] border border-line rounded px-1.5 py-0.5 font-sans">⌘K</kbd>
          </button>
          {headerExtra}
          <button type="button" className="btn btn-sm border-0 !min-h-[44px] !min-w-[44px]" aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'} onClick={toggle}><Icon name={theme === 'dark' ? 'sun' : 'moon'} size={20} /></button>
          <span className="w-[34px] h-[34px] rounded-full bg-accent text-white text-[13px] font-semibold flex items-center justify-center flex-none" title={me?.user?.name} aria-hidden="true">{initials}</span>
          <button type="button" className="btn btn-sm border-0 !min-h-[44px] !min-w-[44px]" aria-label="Sign out" onClick={() => logout()}><Icon name="logout" size={20} /></button>
        </header>
        <main id="main" className="p-4 sm:p-8 flex flex-col gap-6 w-full box-border">
          <Outlet />
        </main>
      </div>
      <CommandPalette pages={pages} open={palette} setOpen={setPalette} remote={!touch} />
    </div>
  )
}
