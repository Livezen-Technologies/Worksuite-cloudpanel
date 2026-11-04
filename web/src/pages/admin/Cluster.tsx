import { useState } from 'react'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge, type Tone } from '../../components/Badge'
import Select from '../../components/Select'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

const AV: Record<string, Tone> = { active: 'g', pause: 'y', drain: 'n' }

/** SWARM: Docker Swarm across servers: nodes, services, replicas and draining. */
export default function Cluster() {
  const page = useOne<R>('/swarm', undefined, { refetchInterval: 10000 })
  const { run, busy } = useAction()
  const [f, setF] = useState({ name: '', manager: '' })
  const [joinTo, setJoinTo] = useState<Record<string, string>>({})
  const [svc, setSvc] = useState({ name: '', image: '', replicas: '2', published: '', target: '', constraint: '' })
  const w = page.item ?? {}
  const clusters = (w.clusters as R[]) ?? []
  const free = (w.available_servers as R[]) ?? []
  const refetch = () => page.refetch()
  const act = (path: string, label: string, body?: R) => run({ path, body, label }).then(refetch).catch(() => {})

  return (
    <>
      <PageHeader title="Cluster" subtitle="Docker Swarm across servers: replicas, rolling updates and placement rules." />
      <Card title="New cluster">
        <div className="flex flex-wrap gap-2 items-end">
          <label className="flex flex-col gap-1 text-sm flex-1 min-w-[160px]">Name<input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="production" /></label>
          <label className="flex flex-col gap-1 text-sm flex-1 min-w-[200px]">Manager server<Select className="input" value={f.manager} onChange={(e) => setF({ ...f, manager: e.target.value })}>
            <option value="">Choose a server…</option>{free.map((s) => <option key={String(s.id)} value={String(s.id)}>{String(s.name)} ({String(s.ip ?? '')})</option>)}</Select></label>
          <button type="button" className="btn btn-primary" disabled={busy || f.name.trim().length < 2 || !f.manager}
            onClick={() => run({ path: '/swarm', body: { name: f.name.trim(), manager_server_id: f.manager }, label: `Create cluster ${f.name}` }).then(() => { setF({ name: '', manager: '' }); refetch() }).catch(() => {})}>Create cluster</button>
        </div>
        <p className="m-0 mt-2 text-xs text-muted">The manager runs <code>docker swarm init</code> through its agent. Servers join over their private network address when they have one.</p>
      </Card>

      {page.item && clusters.length === 0 && <Card><p className="m-0 text-sm text-muted">No clusters yet.</p></Card>}
      {clusters.map((c) => {
        const nodes = (c.nodes as R[]) ?? []
        const services = (c.services as R[]) ?? []
        const status = String(c.status)
        return (
          <Card key={String(c.id)}>
            <div className="flex flex-wrap items-center gap-2 mb-3">
              <span className="font-semibold">{String(c.name)}</span>
              <Badge tone={status === 'active' ? 'g' : status === 'failed' ? 'r' : 'b'}>{status}</Badge>
              <span className="text-xs text-muted">manager {String(c.advertise_addr ?? '')}:2377{c.refreshed_at ? ` · updated ${ago(String(c.refreshed_at))}` : ''}</span>
              <span className="ml-auto flex gap-1">
                <button type="button" className="btn btn-sm" disabled={busy || status !== 'active'} onClick={() => act(`/swarm/${c.id}/refresh`, 'Refresh cluster')}>Refresh</button>
                <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => {
                  const typed = window.prompt(`Delete cluster ${c.name}? Remove its other nodes first. Type the cluster name to confirm.`)
                  if (typed !== null) run({ path: `/swarm/${c.id}?confirm=${encodeURIComponent(typed)}`, method: 'DELETE', label: `Delete ${c.name}` }).then(refetch).catch(() => {})
                }}>Delete</button>
              </span>
            </div>
            {c.error ? <p role="alert" className="m-0 mb-2 text-sm text-danger">{String(c.error)}</p> : null}

            {status === 'active' && <div className="flex flex-wrap gap-2 items-end mb-4">
              <label className="flex flex-col gap-1 text-sm flex-1 min-w-[200px]">Join a node<Select className="input" value={joinTo[String(c.id)] ?? ''} onChange={(e) => setJoinTo({ ...joinTo, [String(c.id)]: e.target.value })}>
                <option value="">Choose a server…</option>{free.map((s) => <option key={String(s.id)} value={String(s.id)}>{String(s.name)}</option>)}</Select></label>
              <button type="button" className="btn" disabled={busy || !joinTo[String(c.id)]} onClick={() => act(`/swarm/${c.id}/join`, 'Join node', { server_id: joinTo[String(c.id)] })}>+ Join</button>
            </div>}

            <h3 className="m-0 mb-1 text-sm font-semibold">Nodes</h3>
            {nodes.length === 0 ? <p className="m-0 mb-3 text-sm text-muted">{status === 'active' ? 'Refresh to read the nodes from the manager.' : 'Waiting for the cluster.'}</p> : (
              <div className="overflow-x-auto mb-4"><table className="w-full text-sm"><thead><tr className="text-left text-xs text-muted"><th className="py-1 pr-3">Node</th><th className="pr-3">Role</th><th className="pr-3">Availability</th><th className="pr-3">Tasks</th><th className="pr-3">Labels</th><th /></tr></thead>
                <tbody>{nodes.map((n) => {
                  const av = String(n.availability ?? '')
                  return (
                    <tr key={String(n.id)} className="border-t border-line">
                      <td className="py-1.5 pr-3"><span className="font-semibold">{String(n.hostname)}</span> <span className="text-xs text-muted">{String(n.addr ?? '')}</span></td>
                      <td className="pr-3">{String(n.role)}{n.leader ? ' (leader)' : ''}</td>
                      <td className="pr-3"><Badge tone={AV[av] ?? 'n'}>{av}</Badge></td>
                      <td className="pr-3">{Number(n.tasks ?? 0)}</td>
                      <td className="pr-3 text-xs text-muted">{Object.entries((n.labels as Record<string, string>) ?? {}).map(([k, v]) => `${k}=${v}`).join(', ') || '—'}</td>
                      <td className="text-right">{av === 'drain'
                        ? <button type="button" className="btn btn-sm" disabled={busy} onClick={() => act(`/swarm/${c.id}/nodes/${n.id}`, `Activate ${n.hostname}`, { availability: 'active' })}>Activate</button>
                        : <button type="button" className="btn btn-sm" disabled={busy} onClick={() => act(`/swarm/${c.id}/nodes/${n.id}`, `Drain ${n.hostname}`, { availability: 'drain' })}>Drain</button>}</td>
                    </tr>
                  )
                })}</tbody></table></div>
            )}

            <h3 className="m-0 mb-1 text-sm font-semibold">Services</h3>
            {services.length === 0 ? <p className="m-0 text-sm text-muted">No services yet.</p> : (
              <ul className="list-none m-0 p-0 flex flex-col">{services.map((s) => {
                const rep = s.replicas == null ? null : Number(s.replicas)
                return (
                  <li key={String(s.id)} className="flex flex-wrap items-center gap-3 py-2 border-t border-line first:border-t-0 text-sm">
                    <span className="flex-1 min-w-[180px]"><span className="font-semibold">{String(s.name)}</span> <span className="text-xs text-muted">{String(s.image ?? '')}</span>
                      {((s.constraints as string[]) ?? []).length ? <span className="block text-xs text-muted">{(s.constraints as string[]).join(', ')}</span> : null}</span>
                    <span className="flex gap-1">
                      <button type="button" className="btn btn-sm" disabled={busy} onClick={() => {
                        const image = window.prompt(`New image for ${s.name} (rolls out one replica at a time and rolls back on failure):`, String(s.image ?? ''))
                        if (image && image !== s.image) act(`/swarm/${c.id}/services/${s.id}/image`, `Update ${s.name}`, { image })
                      }}>Image</button>
                      <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => {
                        const typed = window.prompt(`Remove service ${s.name}? Type its name to confirm.`)
                        if (typed !== null) run({ path: `/swarm/${c.id}/services/${s.id}?confirm=${encodeURIComponent(typed)}`, method: 'DELETE', label: `Remove ${s.name}` }).then(refetch).catch(() => {})
                      }}>Remove</button>
                    </span>
                    {rep === null ? <Badge tone="n">global</Badge> : <span className="flex items-center gap-1">
                      <button type="button" className="btn btn-sm" aria-label={`Fewer replicas of ${s.name}`} disabled={busy || rep <= 0} onClick={() => act(`/swarm/${c.id}/services/${s.id}/scale`, `Scale ${s.name}`, { replicas: rep - 1 })}>−</button>
                      <span className="min-w-[90px] text-center">{Number(s.running ?? 0)}/{rep} running</span>
                      <button type="button" className="btn btn-sm" aria-label={`More replicas of ${s.name}`} disabled={busy || rep >= 100} onClick={() => act(`/swarm/${c.id}/services/${s.id}/scale`, `Scale ${s.name}`, { replicas: rep + 1 })}>+</button>
                    </span>}
                  </li>
                )
              })}</ul>
            )}
            {status === 'active' && <div className="mt-4 rounded-md border border-line p-3">
              <h3 className="m-0 mb-2 text-sm font-semibold">Deploy a service</h3>
              <div className="grid gap-2 [grid-template-columns:repeat(auto-fit,minmax(140px,1fr))]">
                <input className="input font-mono" aria-label="Service name" placeholder="web" value={svc.name} onChange={(e) => setSvc({ ...svc, name: e.target.value })} />
                <input className="input font-mono" aria-label="Service image" placeholder="nginx:1.27" value={svc.image} onChange={(e) => setSvc({ ...svc, image: e.target.value })} />
                <input className="input" aria-label="Service replicas" type="number" min={0} max={100} value={svc.replicas} onChange={(e) => setSvc({ ...svc, replicas: e.target.value })} />
                <input className="input" aria-label="Published port" type="number" placeholder="Published port" value={svc.published} onChange={(e) => setSvc({ ...svc, published: e.target.value })} />
                <input className="input" aria-label="Container port" type="number" placeholder="Container port" value={svc.target} onChange={(e) => setSvc({ ...svc, target: e.target.value })} />
                <input className="input font-mono" aria-label="Placement constraint" placeholder="node.role==worker" value={svc.constraint} onChange={(e) => setSvc({ ...svc, constraint: e.target.value })} />
              </div>
              <button type="button" className="btn btn-primary mt-2" disabled={busy || !svc.name.trim() || !svc.image.trim() || (!!svc.published !== !!svc.target)}
                onClick={() => run({ path: `/swarm/${c.id}/services`, label: `Deploy ${svc.name}`, body: {
                  name: svc.name.trim(), image: svc.image.trim(), replicas: Number(svc.replicas || 1),
                  ...(svc.published && svc.target ? { ports: [{ published: Number(svc.published), target: Number(svc.target) }] } : {}),
                  ...(svc.constraint.trim() ? { constraints: [svc.constraint.trim()] } : {}),
                } }).then(() => { setSvc({ name: '', image: '', replicas: '2', published: '', target: '', constraint: '' }); refetch() }).catch(() => {})}>Deploy</button>
            </div>}
            <p className="m-0 mt-3 text-xs text-muted">Updates roll one replica at a time and stop if the health check fails. Volumes pin a service to its node unless shared storage is used.</p>
          </Card>
        )
      })}
    </>
  )
}
