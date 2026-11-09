import { useEffect, useState, type ReactNode } from 'react'
import { Link, NavLink, Outlet, useLocation } from 'react-router'
import { homeFor, useAuth } from '../lib/auth'
import { BRAND, FOOTER, HERO, MENU } from './content'
import './site.css'

const KEY = 'lz-site-theme'
function initialTheme(): 'light' | 'dark' {
  try { const t = localStorage.getItem(KEY); if (t === 'light' || t === 'dark') return t } catch { /* storage blocked */ }
  return typeof window !== 'undefined' && window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

/** Shared marketing-site chrome: glass header, menu, theme toggle and footer. */
export function SiteLayout() {
  const { me } = useAuth()
  const [theme, setTheme] = useState(initialTheme)
  const [open, setOpen] = useState(false)
  const { pathname, hash } = useLocation()
  useEffect(() => { try { localStorage.setItem(KEY, theme) } catch { /* ignore */ } }, [theme])
  useEffect(() => {
    setOpen(false)
    if (hash) document.getElementById(hash.slice(1))?.scrollIntoView()
    else window.scrollTo?.(0, 0)
  }, [pathname, hash])
  return (
    <div className="lz-site" data-theme={theme}>
      <header className="lz-head">
        <div className="lz-wrap">
          <Link to="/" className="lz-logo"><i />{BRAND.name}</Link>
          <nav className={'lz-nav' + (open ? ' open' : '')} aria-label="Main">
            {MENU.map(([k, to]) => <NavLink key={k} to={to} end className={({ isActive }) => (isActive ? 'on' : '')}>{k}</NavLink>)}
          </nav>
          <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
            <button className="lz-btn lz-icon" aria-label={theme === 'dark' ? 'Use light theme' : 'Use dark theme'} onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}>{theme === 'dark' ? '☀' : '☾'}</button>
            {me ? <Link className="lz-btn pri" to={homeFor(me)}>Dashboard</Link> : <>
              <Link className="lz-btn lz-hide-sm" to="/login">Sign in</Link>
              <Link className="lz-btn pri" to="/register">Start deploying</Link>
            </>}
            <button className="lz-btn lz-icon lz-burger" aria-label="Menu" aria-expanded={open} onClick={() => setOpen(!open)}>☰</button>
          </div>
        </div>
      </header>
      <main><Outlet /></main>
      <footer className="lz-foot">
        <div className="lz-wrap">
          <div className="cols">
            <div><div className="lz-logo" style={{ color: 'var(--fg)' }}><i />{BRAND.name}</div><p>{BRAND.company}<br />{BRAND.place}</p></div>
            {FOOTER.map((c) => <div key={c.k}><h4>{c.k}</h4>{c.items.map(([k, to]) => <Link key={k} to={to}>{k}</Link>)}</div>)}
          </div>
          <p className="lz-muted" style={{ marginTop: 32 }}>© {new Date().getFullYear()} {BRAND.company}</p>
        </div>
      </footer>
    </div>
  )
}

export function PageHero({ page, children }: { page: keyof typeof HERO; children?: ReactNode }) {
  const [eye, title, sub] = HERO[page]
  useEffect(() => { document.title = `${title} · ${BRAND.name}` }, [title])
  return (
    <section className="lz-hero" style={{ padding: '64px 0 56px' }}>
      <div className="lz-wrap">
        <div className="lz-eyebrow">{eye}</div>
        <h1 className="lz-h1" style={{ fontSize: 'clamp(30px,5vw,48px)' }}>{title}</h1>
        <p className="lz-sub">{sub}</p>
        {children}
      </div>
    </section>
  )
}
