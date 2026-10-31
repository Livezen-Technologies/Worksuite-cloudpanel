import { Link } from 'react-router'
import { Shell, type NavItem } from '../../components/Shell'
import { useOne } from '../../api/hooks'
import { Meter } from '../../components/Card'
import { num, type R } from '../../lib/util'

export const PANEL_NAV: NavItem[] = [
  { label: 'My apps', icon: 'grid', to: '/app', end: true },
  { label: 'Host an app', icon: 'rocket', to: '/app/host' },
  { label: 'Files', icon: 'file', to: '/app/files' },
  { label: 'Domains', icon: 'globe', to: '/app/domains' },
  { label: 'Backups', icon: 'archive', to: '/app/backups' },
  { label: 'Email', icon: 'bell', to: '/app/email' },
  { label: 'Security', icon: 'shield', to: '/app/security' },
  { label: 'Firewall', icon: 'shield', to: '/app/firewall' },
  { label: 'Scheduled jobs', icon: 'clock', to: '/app/cron' },
  { label: 'Move a site here', icon: 'share', to: '/app/move' },
  { label: 'Billing', icon: 'tag', to: '/app/billing' },
  { label: 'Account', icon: 'user', to: '/app/account' },
]

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
  return <Shell label="Account" nav={PANEL_NAV} brand={{ title: 'My Cloud' }} touch sidebarFooter={<PlanBox />} />
}
