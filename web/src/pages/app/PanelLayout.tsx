import { Link, useLocation } from 'react-router'
import { Shell, type NavItem, type RailItem } from '../../components/Shell'
import { useOne } from '../../api/hooks'
import { Meter } from '../../components/Card'
import { num, type R } from '../../lib/util'

export interface PanelSection { id: string; label: string; icon: string; desc: string; items: NavItem[] }

/** Same rail + section panel as the admin panel (design: User · Project overview). */
export const PANEL_SECTIONS: PanelSection[] = [
  { id: 'dashboard', label: 'Dashboard', icon: 'grid', desc: 'Your account at a glance', items: [
    { label: 'Overview', icon: 'grid', to: '/app', end: true },
  ] },
  { id: 'manage', label: 'Manage', icon: 'rocket', desc: 'Apps, domains, email and files', items: [
    { label: 'Apps', icon: 'rocket', to: '/app/apps' },
    { label: 'Host an app', icon: 'plus', to: '/app/host' },
    { label: 'Domains & DNS', icon: 'globe', to: '/app/domains' },
    { label: 'Email', icon: 'bell', to: '/app/email' },
    { label: 'Files', icon: 'file', to: '/app/files' },
  ] },
  { id: 'operate', label: 'Operate', icon: 'pulse', desc: 'Backups, jobs, security and moves', items: [
    { label: 'Backups', icon: 'archive', to: '/app/backups' },
    { label: 'Scheduled jobs', icon: 'clock', to: '/app/cron' },
    { label: 'Security', icon: 'shield', to: '/app/security' },
    { label: 'Firewall', icon: 'shield', to: '/app/firewall' },
    { label: 'Move a site here', icon: 'share', to: '/app/move' },
  ] },
  { id: 'account', label: 'Account', icon: 'gear', desc: 'Billing, team and settings', items: [
    { label: 'Billing', icon: 'tag', to: '/app/billing' },
    { label: 'Settings & team', icon: 'user', to: '/app/account' },
  ] },
]

/** Flat list for the command palette and the mobile drawer. */
export const PANEL_NAV: NavItem[] = PANEL_SECTIONS.flatMap((s) => s.items.map((it, i) => (i === 0 ? { ...it, group: s.label } : it)))

export function usageOf(b: R | undefined, key: 'apps' | 'ram_mb' | 'storage_gb' | 'bandwidth_gb') {
  const used = num(b?.usage?.[key])
  const limit = num(b?.limits?.[key] ?? b?.limits?.[key.replace(/_(mb|gb)$/, '')])
  return { used, limit, pct: limit ? (used / limit) * 100 : 0 }
}

function PlanBox() {
  const { item: b } = useOne<R>('/app/billing')
  const apps = usageOf(b, 'apps'), ram = usageOf(b, 'ram_mb')
  const plan = typeof b?.plan === 'string' ? b.plan : b?.plan?.name
  return (
    <div className="p-3.5 rounded-[10px] bg-head border border-line">
      <div className="text-xs text-muted">Plan · {plan ?? '—'}</div>
      <div className="text-[13px] font-semibold mt-1">{apps.used} of {apps.limit || '—'} apps · {(ram.used / 1024).toFixed(1)} / {(ram.limit / 1024).toFixed(0)} GB RAM</div>
      <div className="mt-2.5"><Meter value={Math.max(apps.pct, ram.pct)} label="Plan usage" /></div>
      <Link to="/app/billing" className="inline-flex items-center min-h-[44px] text-[13px] font-semibold">Upgrade plan</Link>
    </div>
  )
}

export function PanelLayout() {
  const { pathname } = useLocation()
  // Longest matching route wins, so /app/apps beats /app.
  const active = PANEL_SECTIONS
    .map((sec) => ({ sec, hit: Math.max(...sec.items.map((i) => (pathname === i.to || (!i.end && pathname.startsWith(i.to + '/'))) ? i.to.length : 0)) }))
    .reduce((best, c) => (c.hit > best.hit ? c : best), { sec: pathname.startsWith('/app/new-site') ? PANEL_SECTIONS[1] : PANEL_SECTIONS[0], hit: 0 }).sec
  const rail: RailItem[] = PANEL_SECTIONS.map((sec) => ({ label: sec.label, icon: sec.icon, to: sec.items[0].to, end: sec.items[0].end }))
  return <Shell label={active.label} rail={rail} nav={active.items} sectionDesc={active.desc} brand={{ title: 'My Cloud', subtitle: active.label }} touch sidebarFooter={<PlanBox />} />
}
