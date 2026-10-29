import { Link } from 'react-router'
import { Shell, type NavItem, type RailItem } from '../../components/Shell'
import { Icon } from '../../components/Icon'
import { useList } from '../../api/hooks'
import type { R } from '../../lib/util'

/** The areas of the product, shown in the icon rail beside the sidebar. */
export const ADMIN_RAIL: RailItem[] = [
  { label: 'Infrastructure', to: '/admin', icon: 'grid', end: true },
  { label: 'Applications', to: '/admin/applications', icon: 'rocket' },
  { label: 'Monitoring', to: '/admin/monitoring', icon: 'pulse' },
  { label: 'Hosting customers', to: '/admin/customers', icon: 'users' },
  { label: 'Customer panel', to: '/app', icon: 'store' },
  { label: 'Settings', to: '/admin/settings', icon: 'gear' },
]

export const ADMIN_NAV: NavItem[] = [
  { label: 'Overview', to: '/admin', end: true, icon: 'grid', group: 'Infrastructure' },
  { label: 'Servers', to: '/admin/servers', icon: 'server' },
  { label: 'Containers', to: '/admin/containers', icon: 'box' },
  { label: 'Monitoring', to: '/admin/monitoring', icon: 'pulse' },

  { label: 'Applications', to: '/admin/applications', icon: 'rocket', group: 'Deploy' },
  { label: 'App Catalogue', to: '/admin/catalog', icon: 'store' },
  { label: 'Compose Stacks', to: '/admin/compose', icon: 'layers' },
  { label: 'Images & Registry', to: '/admin/images', icon: 'image' },

  { label: 'Domains & SSL', to: '/admin/domains', icon: 'globe', group: 'Resources' },
  { label: 'Databases', to: '/admin/databases', icon: 'db' },
  { label: 'Volumes & Storage', to: '/admin/volumes', icon: 'disk' },
  { label: 'Networks', to: '/admin/networks', icon: 'share' },
  { label: 'Backups', to: '/admin/backups', icon: 'archive' },

  { label: 'Packages', to: '/admin/packages', icon: 'tag', group: 'Hosting' },
  { label: 'Hosting Customers', to: '/admin/customers', icon: 'users' },

  { label: 'Logs & Terminal', to: '/admin/logs', icon: 'file', group: 'Operations' },
  { label: 'Automation', to: '/admin/automation', icon: 'clock' },

  { label: 'Users & Teams', to: '/admin/users', icon: 'users', group: 'Settings' },
  { label: 'Settings', to: '/admin/settings', icon: 'gear' },
  { label: 'Account', to: '/admin/account', icon: 'user' },
]

function AlertsBell() {
  const { rows } = useList<R>('/alerts', { status: 'active' }, { refetchInterval: 30000 })
  const n = rows.filter((a) => !a.acknowledged_at && a.status !== 'resolved').length
  return (
    <Link to="/admin/monitoring" aria-label={n ? `Alerts, ${n} active` : 'Alerts'} className="relative w-10 h-10 flex items-center justify-center rounded-lg text-muted">
      <Icon name="bell" size={22} />
      {n > 0 && <span aria-hidden="true" className="absolute top-2 right-2 w-2 h-2 rounded-full bg-[#DC2626]" />}
    </Link>
  )
}

export function AdminLayout() {
  return (
    <Shell rail={ADMIN_RAIL} label="Main" nav={ADMIN_NAV} brand={{ title: 'Docker Manager' }} headerExtra={<AlertsBell />}
      sidebarFooter={
        <div className="p-3.5 rounded-[10px] bg-head border border-line">
          <div className="text-xs text-muted">Control plane</div>
          <div className="flex items-center gap-2 mt-1.5 text-[13px] font-semibold"><span aria-hidden="true" className="w-2 h-2 rounded-full bg-[#16A34A]" />All systems operational</div>
        </div>
      } />
  )
}
