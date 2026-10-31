import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { useAction, useList, useOne } from '../../api/hooks'
import type { Container, Server } from '../../api/types'
import { Card, Field, Meter, StatTile, Toggle, PageHeader } from '../../components/Card'
import { Badge, StatusBadge, toneFor } from '../../components/Badge'
import { Tabs } from '../../components/Tabs'
import { DataTable, type Column } from '../../components/DataTable'
import { ConfirmDialog } from '../../components/Overlay'
import { LineChart } from '../../components/Charts'
import { ago, mb, pct, str } from '../../lib/format'
import { num, type R } from '../../lib/util'
import { CleanupPanel } from '../../components/CleanupPanel'
import { TunnelPanel } from '../../components/TunnelPanel'
import { AdoptRoutesPanel } from '../../components/AdoptRoutesPanel'

const TABS = ['Overview', 'Containers', 'Applications', 'Networks', 'Volumes', 'Storage', 'Monitoring', 'Logs', 'Security', 'Backups', 'Cleanup', 'Tunnel', 'Routes', 'Agent', 'Settings'] as const
type Tab = (typeof TABS)[number]

function SimpleList({ path, query, columns, label }: { path: string; query?: R; columns: Column<R>[]; label: string }) {
  const { rows, isLoading } = useList<R>(path, query)
  return <DataTable label={label} rows={rows} columns={columns} rowKey={(r) => String(r.id ?? r.name)} loading={isLoading} />
}

export default function ServerDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const [tab, setTab] = useState<Tab>('Overview')
  const { item: s, isLoading } = useOne<Server>(`/servers/${id}`, undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const [confirmDel, setConfirmDel] = useState(false)
  const [edit, setEdit] = useState<R | null>(null)
  const containers = useList<Container>(`/servers/${id}/containers`)
  const metrics = useOne<R>(tab === 'Monitoring' || tab === 'Overview' ? '/metrics' : null, { server_id: id, range: '6h' })
  const audit = useList<R>(tab === 'Logs' || tab === 'Security' ? '/audit-logs' : null, { server_id: id })
  if (isLoading || !s) return <Card>{isLoading ? 'Loading server…' : 'Server not found.'}</Card>
  const series = (metrics.item?.series ?? metrics.item ?? {}) as R
  const f = edit ?? { name: s.name, environment: s.environment, region: s.region, tags: (s.tags ?? []).join(', ') }

  return (
    <>
      <PageHeader title={s.name} back={<Link to="/admin/servers" className="link-action">← Servers</Link>}
        badge={<><Badge tone={toneFor(s.status)}>{s.status}</Badge><span className="font-mono text-sm text-muted">{s.ip}</span></>}
        actions={<>
          <button type="button" className="btn" disabled={busy} onClick={() => run({ path: `/servers/${s.id}/maintenance`, body: { enabled: s.status !== 'maintenance' }, label: s.status === 'maintenance' ? 'Leave maintenance' : 'Enter maintenance' })}>
            {s.status === 'maintenance' ? 'End maintenance' : 'Maintenance mode'}
          </button>
          <button type="button" className="btn" disabled={busy} onClick={() => run({ path: `/servers/${s.id}/agent/restart`, label: `Restart agent on ${s.name}` })}>Restart agent</button>
        </>} />
      {s.status === 'maintenance' && <div className="card p-3 text-sm" style={{ background: 'var(--b-bg)', color: 'var(--b-fg)' }}>Maintenance mode is on: new deployments to this server are blocked.</div>}
      <Tabs tabs={TABS.map((t) => ({ id: t, label: t }))} value={tab} onChange={setTab} label="Server sections" />

      {tab === 'Overview' && (
        <>
          <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
            <StatTile label="CPU" value={pct(s.cpu_pct)} delta={`${s.cpu_cores ?? '—'} cores`} pct={s.cpu_pct} />
            <StatTile label="Memory" value={pct(s.ram_pct)} delta={mb(s.ram_mb)} pct={s.ram_pct} />
            <StatTile label="Disk" value={pct(s.disk_pct)} delta={s.disk_gb ? `${s.disk_gb} GB` : undefined} pct={s.disk_pct} barColor={num(s.disk_pct) >= 85 ? 'var(--r-fg)' : undefined} />
            <StatTile label="Containers" value={s.container_count ?? containers.rows.length} />
          </div>
          <Card title="System">
            <dl className="grid gap-x-6 gap-y-3 m-0 text-sm" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
              {([['Hostname', s.hostname], ['OS', s.os], ['Kernel', s.kernel], ['Docker', s.docker_version], ['Agent', s.agent_version], ['Last heartbeat', ago(s.last_heartbeat_at)], ['Environment', s.environment], ['Region', s.region], ['Tags', str(s.tags)], ['Roles', (s.roles ?? ['app']).join(', ')]] as const).map(([k, v]) => (
                <div key={k}><dt className="text-xs text-muted">{k}</dt><dd className="m-0 font-semibold">{v ?? '—'}</dd></div>
              ))}
            </dl>
          </Card>
        </>
      )}
      {tab === 'Containers' && <DataTable label="Containers on this server" rows={containers.rows} loading={containers.isLoading} rowKey={(r) => r.id} onRowClick={(r) => nav(`/admin/containers?id=${r.id}`)}
        columns={[{ key: 'name', header: 'Name' }, { key: 'image', header: 'Image', render: (r) => <span className="font-mono text-xs">{r.image}</span> }, { key: 'state', header: 'State', render: (r) => <StatusBadge status={r.state} /> }, { key: 'health', header: 'Health', render: (r) => <StatusBadge status={r.health ?? '—'} /> }, { key: 'cpu_pct', header: 'CPU', render: (r) => pct(r.cpu_pct) }, { key: 'mem_mb', header: 'Memory', render: (r) => mb(r.mem_mb) }]} />}
      {tab === 'Applications' && <SimpleList label="Applications" path="/applications" query={{ server_id: id }} columns={[{ key: 'name', header: 'Name' }, { key: 'source_type', header: 'Source' }, { key: 'environment', header: 'Environment' }, { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> }]} />}
      {tab === 'Networks' && <SimpleList label="Networks" path="/networks" query={{ server_id: id }} columns={[{ key: 'name', header: 'Name' }, { key: 'driver', header: 'Driver' }, { key: 'subnet', header: 'Subnet', render: (r) => <span className="font-mono text-xs">{r.subnet ?? '—'}</span> }, { key: 'exposure', header: 'Exposure' }]} />}
      {tab === 'Volumes' && <SimpleList label="Volumes" path="/volumes" query={{ server_id: id }} columns={[{ key: 'name', header: 'Name' }, { key: 'type', header: 'Type' }, { key: 'size', header: 'Size', render: (r) => r.size ?? mb(r.size_mb) }, { key: 'backup_policy', header: 'Backup', render: (r) => r.backup_policy ? str(r.backup_policy?.name ?? r.backup_policy) : <Badge tone="y">no policy</Badge> }]} />}
      {tab === 'Storage' && <SimpleList label="Storage pools" path="/storage-pools" query={{ server_id: id }} columns={[{ key: 'name', header: 'Pool' }, { key: 'type', header: 'Type' }, { key: 'used', header: 'Used', render: (r) => <div className="w-40"><Meter value={num(r.used_pct ?? (r.capacity_gb ? (num(r.used_gb) / num(r.capacity_gb)) * 100 : 0))} label={`${r.name} used`} /></div> }]} />}
      {tab === 'Monitoring' && (
        <Card title="Resources — last 6 h">
          <LineChart label="Server CPU, RAM and disk" series={[{ label: 'CPU', color: 'var(--accent)', data: ((series.cpu ?? []) as unknown[]).map(num) }, { label: 'RAM', color: 'var(--b-fg)', data: ((series.ram ?? []) as unknown[]).map(num) }, { label: 'Disk', color: 'var(--y-fg)', data: ((series.disk ?? []) as unknown[]).map(num) }]} />
        </Card>
      )}
      {(tab === 'Logs' || tab === 'Security') && <DataTable label="Audit events" rows={audit.rows} loading={audit.isLoading} rowKey={(r) => String(r.id)}
        columns={[{ key: 'created_at', header: 'Time', render: (r) => ago(r.created_at) }, { key: 'user', header: 'User', value: (r) => r.user_name ?? r.user?.name ?? r.user }, { key: 'action', header: 'Action' }, { key: 'resource', header: 'Resource', value: (r) => r.resource ?? r.resource_type }, { key: 'ip', header: 'Source IP', render: (r) => <span className="font-mono text-xs">{r.ip ?? r.source_ip}</span> }]} />}
      {tab === 'Security' && <Card title="Agent transport"><p className="m-0 text-sm text-muted">The agent connects outbound over HTTPS with a per-agent secret; every command is HMAC-signed and checked against local policy. No Docker socket is exposed.</p></Card>}
      {tab === 'Backups' && <SimpleList label="Backup jobs" path="/backup-jobs" query={{ server_id: id }} columns={[{ key: 'policy', header: 'Policy', value: (r) => r.policy_name ?? r.policy?.name ?? r.policy_id }, { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> }, { key: 'created_at', header: 'When', render: (r) => ago(r.created_at) }]} />}
      {tab === 'Cleanup' && id && <CleanupPanel serverId={id} />}
      {tab === 'Tunnel' && id && <TunnelPanel serverId={id} />}
      {tab === 'Routes' && id && <AdoptRoutesPanel serverId={id} />}
      {tab === 'Agent' && (
        <Card title="Agent">
          <dl className="grid gap-3 m-0 text-sm mb-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
            <div><dt className="text-xs text-muted">Version</dt><dd className="m-0 font-semibold font-mono">{s.agent_version ?? '—'}</dd></div>
            <div><dt className="text-xs text-muted">Last heartbeat</dt><dd className="m-0 font-semibold">{ago(s.last_heartbeat_at)}</dd></div>
          </dl>
          <p className="text-sm text-muted mt-0">Restarting or updating the agent does not stop running containers.</p>
          <div className="flex flex-wrap gap-2">
            <button type="button" className="btn" disabled={busy} onClick={() => run({ path: `/servers/${s.id}/agent/restart`, label: `Restart agent on ${s.name}` })}>Restart agent</button>
            <button type="button" className="btn btn-primary" disabled={busy} onClick={() => run({ path: `/servers/${s.id}/agent/update`, label: `Update agent on ${s.name}` })}>Update agent</button>
          </div>
          <div className="flex items-center gap-3 mt-5">
            <Toggle checked={s.status === 'maintenance'} label="Maintenance mode" onChange={(v) => run({ path: `/servers/${s.id}/maintenance`, body: { enabled: v }, label: v ? 'Enter maintenance' : 'Leave maintenance' })} />
            <span className="text-sm"><span className="font-semibold">Maintenance mode</span> <span className="text-muted">— blocks new deployments to this server</span></span>
          </div>
        </Card>
      )}
      {tab === 'Settings' && (
        <>
          <Card title="Settings">
            <form className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}
              onSubmit={(e) => { e.preventDefault(); run({ method: 'PATCH', path: `/servers/${s.id}`, body: { ...f, tags: String(f.tags).split(',').map((x: string) => x.trim()).filter(Boolean) }, label: 'Save server', success: 'Server saved' }).then(() => setEdit(null)).catch(() => {}) }}>
              <Field label="Name"><input className="input" value={f.name} onChange={(e) => setEdit({ ...f, name: e.target.value })} /></Field>
              <Field label="Environment"><input className="input" value={f.environment} onChange={(e) => setEdit({ ...f, environment: e.target.value })} /></Field>
              <Field label="Region"><input className="input" value={f.region} onChange={(e) => setEdit({ ...f, region: e.target.value })} /></Field>
              <Field label="Tags"><input className="input" value={f.tags} onChange={(e) => setEdit({ ...f, tags: e.target.value })} /></Field>
              <div className="flex items-end"><button className="btn btn-primary" disabled={busy}>Save changes</button></div>
            </form>
          </Card>
          <Card title="Roles">
            <div className="flex items-center gap-3">
              <Toggle checked={(s.roles ?? ['app']).includes('builder')} label="Builder"
                onChange={(v) => { const roles = new Set(s.roles ?? ['app']); if (v) roles.add('builder'); else { roles.delete('builder'); roles.add('app') } run({ method: 'PATCH', path: `/servers/${s.id}`, body: { roles: [...roles] }, label: v ? 'Make builder' : 'Remove builder role', success: 'Roles saved' }).catch(() => {}) }} />
              <span className="text-sm"><span className="font-semibold">Builder</span> <span className="text-muted">— runs image builds from Git/Dockerfile sources. Keep builders separate from production hosts.</span></span>
            </div>
            <div className="flex items-center gap-3 mt-4">
              <Toggle checked={(s.roles ?? ['app']).includes('app')} label="Runs applications"
                onChange={(v) => { const roles = new Set(s.roles ?? ['app']); if (v) roles.add('app'); else { roles.delete('app'); roles.add('builder') } run({ method: 'PATCH', path: `/servers/${s.id}`, body: { roles: [...roles] }, label: 'Save roles', success: 'Roles saved' }).catch(() => {}) }} />
              <span className="text-sm"><span className="font-semibold">Runs applications</span> <span className="text-muted">— the scheduler may place workloads here.</span></span>
            </div>
          </Card>
          <Card title="Remove server">
            <div className="flex flex-wrap gap-3 items-center justify-between rounded-lg p-4 border border-danger-line">
              <div className="text-sm">Removing a server disconnects the agent. Containers keep running on the host.</div>
              <button type="button" className="btn btn-danger" onClick={() => setConfirmDel(true)}>Remove server</button>
            </div>
          </Card>
        </>
      )}
      <ConfirmDialog open={confirmDel} onClose={() => setConfirmDel(false)} danger confirmText={s.name} confirmLabel="Remove server" title={`Remove ${s.name}?`} busy={busy}
        onConfirm={() => run({ method: 'DELETE', path: `/servers/${s.id}`, label: `Remove ${s.name}`, success: 'Server removed' }).then(() => nav('/admin/servers')).catch(() => {})}>
        <p className="m-0">The agent is disconnected and the server leaves the fleet. This cannot be undone.</p>
      </ConfirmDialog>
    </>
  )
}
