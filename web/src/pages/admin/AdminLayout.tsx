import { Link } from 'react-router'
import { Shell, type NavItem } from '../../components/Shell'
import { Icon } from '../../components/Icon'
import { useList } from '../../api/hooks'
import type { R } from '../../lib/util'

export const ADMIN_NAV: NavItem[] = [
  { label: 'Overview', to: '/admin', end: true },
  { label: 'Servers', to: '/admin/servers' },
  { label: 'Containers', to: '/admin/containers' },
  { label: 'Applications', to: '/admin/applications' },
  { label: 'App Catalogue', to: '/admin/catalog' },
  { label: 'Compose Stacks', to: '/admin/compose' },
  { label: 'Images & Registry', to: '/admin/images' },
  { label: 'Networks', to: '/admin/networks' },
  { label: 'Volumes & Storage', to: '/admin/volumes' },
  { label: 'Domains & SSL', to: '/admin/domains' },
  { label: 'Databases', to: '/admin/databases' },
  { label: 'Backups', to: '/admin/backups' },
  { label: 'Monitoring', to: '/admin/monitoring' },
  { label: 'Logs & Terminal', to: '/admin/logs' },
  { label: 'Automation', to: '/admin/automation' },
  { label: 'Packages', to: '/admin/packages' },
  { label: 'Hosting Customers', to: '/admin/customers' },
  { label: 'Users & Teams', to: '/admin/users' },
  { label: 'Settings', to: '/admin/settings' },
  { label: 'Account', to: '/admin/account' },
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
    <Shell label="Main" nav={ADMIN_NAV} brand={{ title: 'Docker Manager' }} headerExtra={<AlertsBell />}
      sidebarFooter={
        <div className="p-3.5 rounded-[10px] bg-head border border-line">
          <div className="text-xs text-muted">Control plane</div>
          <div className="flex items-center gap-2 mt-1.5 text-[13px] font-semibold"><span aria-hidden="true" className="w-2 h-2 rounded-full bg-[#16A34A]" />All systems operational</div>
        </div>
      } />
  )
}
