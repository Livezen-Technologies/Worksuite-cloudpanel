import { Link, useLocation } from 'react-router'
import { Shell, type NavItem, type RailItem } from '../../components/Shell'
import { Icon } from '../../components/Icon'
import { useList } from '../../api/hooks'
import type { R } from '../../lib/util'

/**
 * Six primary categories. The rail switches between them; the sidebar shows
 * only the items of the one in view, so a category's contents stay together
 * instead of competing with every other area for attention.
 */
export interface AdminSection { id: string; label: string; icon: string; desc: string; items: NavItem[] }

export const ADMIN_SECTIONS: AdminSection[] = [
  { id: 'dashboard', label: 'Dashboard', icon: 'grid', desc: 'Fleet health, alerts and quick actions', items: [
    { label: 'Overview', to: '/admin', end: true, icon: 'grid' },
    { label: 'Monitoring', to: '/admin/monitoring', icon: 'pulse' },
    { label: 'Uptime', to: '/admin/uptime', icon: 'clock' },
  ] },
  { id: 'deploy', label: 'Deploy', icon: 'rocket', desc: 'Applications, stacks, containers and images', items: [
    { label: 'Applications', to: '/admin/applications', icon: 'rocket' },
    { label: 'Projects', to: '/admin/projects', icon: 'layers' },
    { label: 'App Catalogue', to: '/admin/catalog', icon: 'store' },
    { label: 'Compose Stacks', to: '/admin/compose', icon: 'layers' },
    { label: 'Containers', to: '/admin/containers', icon: 'box' },
    { label: 'Images & Registry', to: '/admin/images', icon: 'image' },
  ] },
  { id: 'resources', label: 'Resources', icon: 'server', desc: 'Servers, files, databases, storage and networks', items: [
    { label: 'Servers', to: '/admin/servers', icon: 'server' },
    { label: 'System services', to: '/admin/system-services', icon: 'gear' },
    { label: 'Databases', to: '/admin/databases', icon: 'db' },
    { label: 'File manager', to: '/admin/files', icon: 'file' },
    { label: 'Volumes & Storage', to: '/admin/volumes', icon: 'disk' },
    { label: 'Networks', to: '/admin/networks', icon: 'share' },
    { label: 'Private network', to: '/admin/private-network', icon: 'shield' },
  ] },
  { id: 'hosting', label: 'Hosting', icon: 'globe', desc: 'Domains, DNS, mail, packages and hosting customers', items: [
    { label: 'Domains & SSL', to: '/admin/domains', icon: 'globe' },
    { label: 'DNS zones', to: '/admin/dns', icon: 'globe' },
    { label: 'WordPress', to: '/admin/wordpress', icon: 'rocket' },
    { label: 'Migrations', to: '/admin/migrations', icon: 'archive' },
    { label: 'Mail', to: '/admin/mail', icon: 'bell' },
    { label: 'Public pages', to: '/admin/public-pages', icon: 'file' },
    { label: 'Packages', to: '/admin/packages', icon: 'tag' },
    { label: 'Hosting Customers', to: '/admin/customers', icon: 'users' },
  ] },
  { id: 'security', label: 'Security', icon: 'shield', desc: 'Firewall and antivirus', items: [
    { label: 'Firewall', to: '/admin/firewall', icon: 'shield' },
    { label: 'Antivirus', to: '/admin/antivirus', icon: 'shield' },
  ] },
  { id: 'operations', label: 'Operations', icon: 'pulse', desc: 'Backups, logs and scheduled work', items: [
    { label: 'Backups', to: '/admin/backups', icon: 'archive' },
    { label: 'Logs & Terminal', to: '/admin/logs', icon: 'file' },
    { label: 'Automation', to: '/admin/automation', icon: 'clock' },
    { label: 'Cron & scheduler', to: '/admin/cron', icon: 'clock' },
  ] },
  { id: 'settings', label: 'Settings', icon: 'gear', desc: 'Users, permissions and configuration', items: [
    { label: 'Users & Teams', to: '/admin/users', icon: 'users' },
    { label: 'Settings', to: '/admin/settings', icon: 'gear' },
    { label: 'Account', to: '/admin/account', icon: 'user' },
  ] },
]

/** Flat list, still used by the command palette and the mobile drawer. */
export const ADMIN_NAV: NavItem[] = ADMIN_SECTIONS.flatMap((s) =>
  s.items.map((it, i) => (i === 0 ? { ...it, group: s.label } : it)))

function AlertsBell() {
  const { rows } = useList<R>('/alerts', { status: 'active' }, { refetchInterval: 30000 })
  const n = rows.filter((a) => !a.acknowledged_at && a.status !== 'resolved').length
  return (
    <Link to="/admin/monitoring" aria-label={n ? `Alerts, ${n} active` : 'Alerts'} className="relative grid h-11 w-11 place-content-center rounded-lg no-underline text-white/70 hover:text-white hover:bg-white/10">
      <Icon name="bell" size={22} />
      {n > 0 && (
          <span aria-hidden="true" className="absolute right-1 top-1 min-w-[18px] rounded-full bg-[var(--r-fg)] px-1 text-center text-[10px] font-bold leading-[16px] text-white">
            {n > 9 ? '9+' : n}
          </span>
        )}
    </Link>
  )
}

export function AdminLayout() {
  const { pathname } = useLocation()
  // A status light that cannot show a problem is worse than none, so derive it
  // from the newest heartbeat rather than hard-coding green.
  const { rows: servers } = useList<R>('/servers', undefined, { refetchInterval: 30000 })
  const newest = servers.reduce<number>((max, s) => {
    const t = s.last_heartbeat_at ? Date.parse(String(s.last_heartbeat_at)) : 0
    return t > max ? t : max
  }, 0)
  const ageSec = newest ? (Date.now() - newest) / 1000 : Infinity
  const status = servers.length === 0
    ? { label: 'No agents', tone: 'y' as const }
    : ageSec < 90 ? { label: 'Live', tone: 'g' as const }
    : ageSec < 600 ? { label: 'Delayed', tone: 'y' as const }
    : { label: 'Stale', tone: 'r' as const }
  // Longest matching route wins, so /admin/applications beats /admin.
  const active = ADMIN_SECTIONS
    .map((sec) => ({ sec, hit: Math.max(...sec.items.map((i) => (pathname === i.to || pathname.startsWith(i.to + '/')) ? i.to.length : 0)) }))
    .reduce((best, c) => (c.hit > best.hit ? c : best), { sec: ADMIN_SECTIONS[0], hit: 0 }).sec

  const rail: RailItem[] = ADMIN_SECTIONS.map((sec) => ({
    label: sec.label, icon: sec.icon, to: sec.items[0].to, end: sec.items[0].end,
  }))

  return (
    <Shell sectionDesc={active.desc} status={status} rail={rail} label={active.label} nav={active.items} brand={{ title: 'WorkSuite', subtitle: active.label }} headerExtra={<AlertsBell />}
      sidebarFooter={
        <div className="p-3.5 rounded-[10px] bg-head border border-line">
          <div className="text-xs text-muted">Control plane</div>
          <div className="flex items-center gap-2 mt-1.5 text-[13px] font-semibold"><span aria-hidden="true" className="w-2 h-2 rounded-full bg-[var(--g-fg)]" />All systems operational</div>
        </div>
      } />
  )
}
