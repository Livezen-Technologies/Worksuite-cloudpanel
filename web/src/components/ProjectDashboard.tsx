import { useState } from 'react'
import { Link } from 'react-router'
import { useOne } from '../api/hooks'
import { Card, Empty, Meter, StatTile } from './Card'
import { Badge, StatusBadge } from './Badge'
import { ProjectBackups } from './ProjectBackups'
import { ago } from '../lib/format'
import { num, type R } from '../lib/util'

const gb = (mb: number) => mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${Math.round(mb)} MB`
const HEALTH: Record<string, ['g' | 'y' | 'r' | 'n', string]> = { healthy: ['g', 'Healthy'], degraded: ['y', 'Degraded'], down: ['r', 'Down'], empty: ['n', 'No apps yet'] }
type Tab = 'applications' | 'containers' | 'resources' | 'backups'

/**
 * Project overview: health, counts and the Applications / Containers / Resources / Backups tabs.
 * `base` is '' for the admin API and '/app' for the customer portal; `appLink` builds an app's URL.
 */
export function ProjectDashboard({ projectId, base = '', appLink }: { projectId: string; base?: string; appLink: (id: string) => string }) {
  const d = useOne<R>(`${base}/projects/${projectId}/dashboard`, undefined, { refetchInterval: 20000 })
  const [tab, setTab] = useState<Tab>('applications')
  const x = d.item ?? {}
  const c = (x.counts ?? {}) as R
  const res = (x.resources ?? {}) as R
  const apps = (x.applications ?? []) as R[]
  const containers = (x.containers ?? []) as R[]
  const nodes = (x.nodes ?? []) as R[]
  const [tone, label] = HEALTH[String(x.health ?? 'empty')] ?? HEALTH.empty
  const last = x.last_backup as R | null

  if (d.isLoading) return <p className="m-0 text-sm text-muted">Loading…</p>
  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center gap-2"><Badge tone={tone}>{label}</Badge>{last ? <span className="text-xs text-muted">Last backup {ago(last.created_at)} · {String(last.status)}</span> : <span className="text-xs text-muted">No project backup yet</span>}</div>
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
        <StatTile label="Applications" value={num(c.applications)} />
        <StatTile label="Containers" value={num(c.containers)} delta={num(c.containers) ? `${num(c.running)} running` : undefined} deltaTone={num(c.running) < num(c.containers) ? 'r' : undefined} />
        <StatTile label="Nodes" value={num(c.nodes)} />
        <StatTile label="Backups" value={num(c.backups)} />
      </div>
      <div role="tablist" aria-label="Project overview" className="flex gap-1 border-b border-line">
        {(['applications', 'containers', 'resources', 'backups'] as const).map((t) => <button key={t} role="tab" type="button" aria-selected={tab === t} onClick={() => setTab(t)}
          className={`h-10 px-3.5 border-0 bg-transparent cursor-pointer text-sm capitalize ${tab === t ? 'font-semibold' : 'text-muted'}`}
          style={tab === t ? { boxShadow: 'inset 0 -2px 0 var(--accent)' } : undefined}>{t}</button>)}
      </div>

      {tab === 'applications' && <Card pad={false}>{!apps.length ? <div className="p-5"><Empty>No applications in this project.</Empty></div> :
        <ul className="list-none m-0 p-0">{apps.map((a) => (
          <li key={a.id} className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3 border-t border-line first:border-t-0 text-[13.5px]">
            <span className="flex-[1_1_200px] min-w-0"><Link className="block font-semibold truncate" to={appLink(String(a.id))}>{a.name}</Link>
              <span className="block text-xs text-muted truncate">{a.environment} · {a.server?.name ?? 'not placed'}{a.last_deployed_at ? ` · deployed ${ago(a.last_deployed_at)}` : ''}</span></span>
            <span className="text-muted min-w-[110px]">{a.running}/{a.containers} running{num(a.unhealthy) ? ` · ${a.unhealthy} unhealthy` : ''}</span>
            <span className="tabular-nums min-w-[120px]">{a.cpu_limit} vCPU · {gb(num(a.memory_limit_mb))}</span>
            <StatusBadge status={a.status} />
          </li>))}</ul>}</Card>}

      {tab === 'containers' && <Card pad={false}>{!containers.length ? <div className="p-5"><Empty>No containers yet.</Empty></div> :
        <ul className="list-none m-0 p-0">{containers.map((k) => (
          <li key={k.id} className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3 border-t border-line first:border-t-0 text-[13.5px]">
            <span className="flex-[1_1_220px] min-w-0"><span className="block font-mono text-xs truncate">{k.name}</span><span className="block text-xs text-muted truncate">{k.application} · {k.server ?? '—'} · {k.image}</span></span>
            <span className="tabular-nums min-w-[140px] text-muted">{k.cpu_pct != null ? `${num(k.cpu_pct).toFixed(1)}% CPU` : '—'} · {k.mem_mb != null ? gb(num(k.mem_mb)) : '—'}</span>
            {k.health === 'unhealthy' ? <Badge tone="r">unhealthy</Badge> : null}
            <StatusBadge status={k.state} />
          </li>))}</ul>}</Card>}

      {tab === 'resources' && <div className="flex flex-col gap-3">
        <Card>
          <div className="grid gap-4 sm:grid-cols-2 text-[13px]">
            <div><div className="text-muted">Reserved by this project</div><div className="text-lg font-semibold">{res.cpu_reserved} vCPU · {gb(num(res.memory_reserved_mb))}</div><div className="text-xs text-muted">the limits Docker enforces, summed over replicas</div></div>
            <div><div className="text-muted">In use now</div><div className="text-lg font-semibold">{num(res.cpu_pct).toFixed(1)}% CPU · {gb(num(res.mem_mb))}</div><div className="text-xs text-muted">from the agents’ latest container stats</div></div>
          </div>
        </Card>
        <Card title="Nodes">{!nodes.length ? <Empty>Not running on any node yet.</Empty> :
          <ul className="list-none m-0 p-0 flex flex-col gap-3">{nodes.map((n) => (
            <li key={n.id} className="text-[13px]"><div className="flex flex-wrap items-center gap-2 mb-1"><span className="font-semibold flex-1">{n.name}</span><span className="text-muted">{n.applications} app{num(n.applications) === 1 ? '' : 's'}</span><StatusBadge status={n.status} /></div>
              <div className="grid gap-2 sm:grid-cols-2"><Meter value={num(n.cpu_pct)} label={`${n.name} CPU`} /><Meter value={num(n.ram_pct)} label={`${n.name} RAM`} /></div></li>))}</ul>}</Card>
      </div>}

      {tab === 'backups' && <ProjectBackups projectId={projectId} base={base} />}
    </div>
  )
}
