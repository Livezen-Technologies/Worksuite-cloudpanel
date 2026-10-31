import { useState, type ReactNode } from 'react'
import { NavLink, Outlet, useLocation, Link } from 'react-router'
import { Icon } from './Icon'
import { PageErrorBoundary } from './ErrorStates'
import { Drawer } from './Overlay'
import { CommandPalette, type PageLink } from './CommandPalette'
import { useTheme } from '../lib/theme'
import { useAuth } from '../lib/auth'
import { useEffect } from 'react'
import { api, errorMessage } from '../api/client'

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
    <nav aria-label="Areas" className="hidden lg:flex w-[92px] flex-none flex-col items-center gap-1.5 px-2 border-r border-line bg-side py-3 sticky top-16 h-[calc(100vh-4rem)]">
      {items.map((r) => (
        <NavLink key={r.to} to={r.to} end={r.end} aria-label={r.label}
          className={({ isActive }) => `group relative flex w-[76px] flex-col items-center gap-1.5 rounded-lg px-1 py-2.5 no-underline transition-colors ${isActive ? 'bg-sel text-accent-fg ring-1 ring-inset ring-accent/30' : 'text-muted hover:text-fg hover:bg-head'}`}>
          {({ isActive }) => (<>
            <Icon name={r.icon} size={20} />
            <span className={`w-full truncate text-center text-[11px] leading-tight ${isActive ? 'font-semibold' : 'font-medium'}`}>{r.label}</span>
          {r.badge !== undefined && r.badge !== '' && r.badge !== 0 && (
            <span className="absolute -right-0.5 -top-0.5 min-w-[18px] rounded-full bg-danger px-1 text-center text-[10px] font-bold leading-[18px] text-white">{r.badge}</span>
          )}
          </>)}
        </NavLink>
      ))}
    </nav>
  )
}

export function Shell({ nav, rail, status, sectionDesc, sidebarFooter, headerExtra, touch = false, label }: { nav: NavItem[]; rail?: RailItem[]; brand: { title: string; subtitle?: string }; status?: { label: string; tone: 'g' | 'y' | 'r' }; sectionDesc?: string; sidebarFooter?: ReactNode; headerExtra?: ReactNode; touch?: boolean; label: string }) {
  const [menu, setMenu] = useState(false)
  const [acct, setAcct] = useState(false)
  const [palette, setPalette] = useState(false)
  const { theme, toggle } = useTheme()
  const { me, logout } = useAuth()
  const loc = useLocation()
  useEffect(() => setMenu(false), [loc.pathname])
  const initials = (me?.user?.name ?? '?').split(/\s+/).map((x) => x[0]).join('').slice(0, 2).toUpperCase()
  const pages: PageLink[] = nav.map((n) => ({ label: n.label, to: n.to }))
  const side = (
    <div className="flex flex-col gap-6 h-full">
        {/* The brand moved to the top bar, so this names the section instead. */}
        <div>
          <div className="text-base font-semibold tracking-tight">{label}</div>
          {sectionDesc && <div className="mt-0.5 text-[13px] text-muted">{sectionDesc}</div>}
        </div>
      <nav aria-label={label}><NavList items={nav} touch={touch} onPick={() => setMenu(false)} /></nav>
      {sidebarFooter && <div className="mt-auto">{sidebarFooter}</div>}
    </div>
  )
  return (
    <div className={`min-h-screen bg-bg text-fg flex flex-col ${touch ? 'panel-touch' : ''}`}>
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-[80] focus:p-3 focus:bg-card">Skip to content</a>
        <header className="h-16 box-border px-4 sm:px-6 flex items-center gap-3 border-b border-[var(--header-line)] sticky top-0 z-30 bg-[var(--header-bg)] text-[var(--header-fg)]">
          <button type="button" className="lg:hidden !min-h-11 !min-w-11 grid place-content-center rounded-lg border-0 bg-transparent text-white/70 hover:text-white hover:bg-white/10 cursor-pointer" aria-label="Open menu" aria-expanded={menu} onClick={() => setMenu(true)}><Icon name="menu" size={22} /></button>

          {/* Brand lockup, on wide screens where the sidebar heading is not the first thing read. */}
          <div className="hidden lg:flex items-center gap-2 flex-none">
            {/* The header is dark in both themes, per the canvas, so the
                light-on-dark lockup is always the right one. */}
            <img src="/worksuite-logo-dark.png" alt="WorkSuite" className="h-7 w-auto flex-none" />
            {/* The product within the WorkSuite family, as WorkSuite IoT shows "IoT". */}
            <span className="rounded px-1.5 py-0.5 text-xs font-semibold text-[#93C5FD] bg-[rgb(59_130_246/.15)] ring-1 ring-inset ring-[rgb(96_165_250/.3)]">Cloud</span>
          </div>

          {/* Which tenant the session is acting as - the canvas puts this next
              to the brand, and the panel spans tenants for staff. */}
          {/* Admin-only header items: the organisation settings link and the catalogue shortcut
              point into /admin, so the customer portal (no area rail) does not show them. */}
          {rail && me?.organization?.name && (
            <Link to="/admin/settings" title="Organisation settings"
              className="hidden xl:flex flex-none items-center gap-2 rounded-lg bg-white/10 px-2 py-1.5 text-sm font-semibold no-underline text-[var(--header-fg)] hover:bg-white/15">
              <span className="grid h-[26px] w-[26px] place-content-center rounded-md bg-accent text-[13px] text-white" aria-hidden="true">
                {me.organization.name.slice(0, 1).toUpperCase()}
              </span>
              <span className="max-w-[14rem] truncate">{me.organization.name}</span>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="text-white/50" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
            </Link>
          )}

          {/* Search is the primary way into anything, so it sits centre. */}
          <button type="button" onClick={() => setPalette(true)}
            className="!rounded-full flex min-w-0 items-center justify-center gap-2.5 border text-left min-h-11 w-11 flex-none sm:mx-auto sm:min-h-10 sm:w-auto sm:max-w-xl sm:flex-1 sm:justify-start sm:px-3 lg:flex-initial lg:basis-[min(36rem,40vw)] bg-white/5 border-[var(--header-line)] text-white/60 hover:bg-white/10 hover:text-white/80"
            aria-label="Search (Ctrl K)">
            <Icon name="search" size={16} /><span className="hidden min-w-0 flex-1 truncate text-sm sm:block">Search servers, containers, apps…</span>
            <kbd className="hidden sm:inline rounded border border-white/15 px-1.5 py-0.5 font-sans text-[11px] text-white/60">⌘K</kbd>
          </button>

          <div className="ml-auto flex flex-none items-center gap-1">
            {status && (
              <span className="hidden xl:flex items-center gap-1.5 pr-2 text-xs font-semibold text-white/70">
                <span className={`h-2 w-2 rounded-full ${status.tone === 'g' ? 'bg-g-fg' : status.tone === 'y' ? 'bg-y-fg' : 'bg-r-fg'}`} aria-hidden="true" />
                {status.label}
              </span>
            )}
            {rail && (
            <Link to="/admin/catalog" title="Install an application" aria-label="Install an application"
              className="!min-h-11 !min-w-11 hidden sm:grid place-content-center rounded-lg no-underline text-white/70 hover:text-white hover:bg-white/10">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </Link>
            )}
            {headerExtra}
            <button type="button" className="!min-h-11 !min-w-11 grid place-content-center rounded-lg border-0 bg-transparent text-white/70 hover:text-white hover:bg-white/10 cursor-pointer" onClick={toggle}
              aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}>
              <Icon name={theme === 'dark' ? 'sun' : 'moon'} size={20} />
            </button>
            <div className="mx-1 hidden h-6 w-px bg-white/15 sm:block" aria-hidden="true" />
            <div className="relative">
              <button type="button" onClick={() => setAcct((v) => !v)}
                aria-haspopup="menu" aria-expanded={acct}
                className="flex items-center gap-2 rounded-lg px-1 py-1 text-[var(--header-fg)] hover:bg-white/10 cursor-pointer border-0 bg-transparent">
                <span className="relative flex-none">
                  <span className="grid h-8 w-8 place-content-center rounded-full bg-accent text-[13px] font-semibold text-white" aria-hidden="true">{initials}</span>
                  <span className="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-[var(--header-bg)] bg-g-fg" aria-hidden="true" />
                </span>
                <span className="hidden max-w-[12rem] truncate text-sm font-semibold sm:inline">{me?.user?.name}</span>
                <svg className="hidden sm:block flex-none opacity-60" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
              </button>
              {acct && (
                <>
                  <div className="fixed inset-0" onClick={() => setAcct(false)} aria-hidden="true" />
                  <div role="menu" className="card absolute right-0 top-full mt-2 w-72 overflow-hidden p-0 text-fg shadow-pop" style={{ zIndex: 'var(--z-popover)' }}>
                    <div className="border-b border-line px-4 py-3.5">
                      <div className="text-[15px] font-semibold leading-tight">{me?.user?.name}</div>
                      <div className="mt-0.5 truncate text-[13px] text-muted">{me?.user?.email}</div>
                      {(me?.role || me?.organization?.name) && (
                        <div className="mt-1 text-[13px] text-muted">
                          {[me?.role ? String(me.role).replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase()) : null, me?.organization?.name].filter(Boolean).join(" · ")}
                        </div>
                      )}
                    </div>
                    <div className="flex flex-col p-1">
                      <Link to="/admin/account" role="menuitem" onClick={() => setAcct(false)}
                        className="rounded-lg px-3 py-2 text-sm font-medium no-underline text-fg hover:bg-head">Account</Link>
                      <Link to="/admin/settings" role="menuitem" onClick={() => setAcct(false)}
                        className="rounded-lg px-3 py-2 text-sm font-medium no-underline text-fg hover:bg-head">Settings</Link>
                      <button type="button" role="menuitem" onClick={() => { setAcct(false); logout() }}
                        className="mt-0.5 rounded-lg border-0 border-t border-line bg-transparent px-3 py-2 text-left text-sm font-medium text-danger hover:bg-r-bg cursor-pointer">Sign out</button>
                    </div>
                  </div>
                </>
              )}
            </div>
          </div>
        </header>
      <div className="flex flex-1 min-h-0">
      {rail && <Rail items={rail} />}
      <aside className="hidden lg:block w-[272px] flex-none box-border px-4 py-5 bg-side border-r border-line sticky top-16 h-[calc(100vh-4rem)] overflow-y-auto">{side}</aside>
      <Drawer open={menu} onClose={() => setMenu(false)} title="Menu" side="left" width={288}>
        {/* On phones the area rail is hidden, so the drawer carries it: without it a phone could not leave the current area. */}
        {rail && (
          <nav aria-label="Areas" className="mb-5 grid grid-cols-3 gap-1.5 border-b border-line pb-5">
            {rail.map((r) => (
              <NavLink key={r.to} to={r.to} end={r.end} onClick={() => setMenu(false)}
                className={({ isActive }) => `flex min-h-16 flex-col items-center justify-center gap-1 rounded-lg px-1 text-center no-underline transition-colors ${isActive ? 'bg-sel text-accent-fg' : 'text-muted hover:bg-head hover:text-fg'}`}>
                <Icon name={r.icon} size={20} />
                <span className="w-full truncate text-[11px] font-semibold">{r.label}</span>
              </NavLink>
            ))}
          </nav>
        )}
        {side}
      </Drawer>
      <div className="flex-1 min-w-0 flex flex-col">
        <main id="main" className="p-4 sm:p-8 flex flex-col gap-6 w-full min-w-0 box-border">
          {me && !me.user.email_verified_at && <VerifyBanner email={String(me.user.email)} />}
          <PageErrorBoundary key={loc.pathname}><Outlet /></PageErrorBoundary>
        </main>
      </div>
      </div>
      <CommandPalette pages={pages} open={palette} setOpen={setPalette} remote={!touch} />
    </div>
  )
}

/** Shown until the account's email address is confirmed; "Resend" mails a new link. */
function VerifyBanner({ email }: { email: string }) {
  const [state, setState] = useState<'idle' | 'sending' | 'sent' | string>('idle')
  const resend = async () => {
    setState('sending')
    try { await api.post('/me/verify-email'); setState('sent') } catch (e) { setState(errorMessage(e)) }
  }
  return (
    <div role="status" className="flex flex-wrap items-center gap-3 px-4 py-3 rounded-xl border border-line bg-[var(--y-bg)] text-[var(--y-fg)] text-sm">
      <span className="flex-1 min-w-[200px]">Confirm your email address, <strong>{email}</strong>, with the link we sent you.</span>
      {state === 'sent' ? <span>A new link is on its way.</span>
        : <button type="button" className="btn btn-sm" disabled={state === 'sending'} onClick={resend}>{state === 'sending' ? 'Sending…' : 'Resend link'}</button>}
      {state !== 'idle' && state !== 'sending' && state !== 'sent' ? <span className="w-full text-xs">{state}</span> : null}
    </div>
  )
}
