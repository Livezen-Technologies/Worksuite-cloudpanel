import { useState } from 'react'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge } from '../../components/Badge'
import Select from '../../components/Select'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

function ClusterView({ c, onChanged }: { c: R; onChanged: () => void }) {
  const [ns, setNs] = useState('')
  const q = useOne<R>(c.status === 'connected' ? `/kubernetes/${c.id}` : null, ns ? { namespace: ns } : undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const [d, setD] = useState({ name: '', image: '', replicas: '1', port: '' })
  const w = q.item ?? {}
  const refetch = () => q.refetch()
  const deps = (w.deployments as R[]) ?? []
  const target = ns || String(c.default_namespace ?? 'default')
  const act = (dep: R, action: string, body?: R) => run({ path: `/kubernetes/${c.id}/namespaces/${dep.namespace}/deployments/${dep.name}/${action}`, body, label: `${action} ${dep.name}` }).then(refetch).catch(() => {})

  if (c.status !== 'connected') return <p role="alert" className="m-0 text-sm text-danger">{String(c.error ?? 'Not connected.')}</p>
  if (q.error) return <p role="alert" className="m-0 text-sm text-danger">{q.error instanceof Error ? q.error.message : 'The cluster could not be read.'}</p>
  return (
    <div className="flex flex-col gap-4">
      <div>
        <h3 className="m-0 mb-1 text-sm font-semibold">Nodes</h3>
        <ul className="list-none m-0 p-0 flex flex-wrap gap-2">{((w.nodes as R[]) ?? []).map((n) => (
          <li key={String(n.name)} className="rounded-md border border-line px-3 py-2 text-sm">
            <span className="font-semibold">{String(n.name)}</span> <Badge tone={n.ready === 'True' ? 'g' : 'r'}>{n.ready === 'True' ? 'Ready' : 'Not ready'}</Badge>
            <span className="block text-xs text-muted">{((n.roles as string[]) ?? []).join(', ') || 'worker'} · {String(n.cpu ?? '?')} CPU · {String(n.memory ?? '?')}{n.unschedulable ? ' · cordoned' : ''}</span>
          </li>
        ))}</ul>
      </div>
      <div className="flex flex-wrap items-end gap-2">
        <label className="flex flex-col gap-1 text-sm min-w-[180px]">Namespace<Select className="input" value={ns} onChange={(e) => setNs(e.target.value)}>
          <option value="">All namespaces</option>{((w.namespaces as R[]) ?? []).map((n) => <option key={String(n.name)} value={String(n.name)}>{String(n.name)}</option>)}</Select></label>
      </div>
      <div>
        <h3 className="m-0 mb-1 text-sm font-semibold">Deployments</h3>
        {deps.length === 0 ? <p className="m-0 text-sm text-muted">No deployments here.</p> : (
          <ul className="list-none m-0 p-0 flex flex-col">{deps.map((dep) => {
            const rep = Number(dep.replicas ?? 0)
            return (
              <li key={`${dep.namespace}/${dep.name}`} className="flex flex-wrap items-center gap-3 py-2 border-t border-line first:border-t-0 text-sm">
                <span className="flex-1 min-w-[200px]"><span className="font-semibold">{String(dep.name)}</span> <span className="text-xs text-muted">{String(dep.namespace)} · {((dep.images as string[]) ?? []).join(', ')}</span></span>
                <Badge tone={Number(dep.ready) >= rep && rep > 0 ? 'g' : rep === 0 ? 'n' : 'y'}>{Number(dep.ready ?? 0)}/{rep} ready</Badge>
                <span className="flex items-center gap-1">
                  <button type="button" className="btn btn-sm" aria-label={`Fewer replicas of ${dep.name}`} disabled={busy || rep <= 0} onClick={() => act(dep, 'scale', { replicas: rep - 1 })}>−</button>
                  <button type="button" className="btn btn-sm" aria-label={`More replicas of ${dep.name}`} disabled={busy || rep >= 50} onClick={() => act(dep, 'scale', { replicas: rep + 1 })}>+</button>
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => act(dep, 'restart')}>Restart</button>
                  <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => {
                    const typed = window.prompt(`Delete deployment ${dep.name} in ${dep.namespace}? Type its name to confirm.`)
                    if (typed !== null) run({ path: `/kubernetes/${c.id}/namespaces/${dep.namespace}/deployments/${dep.name}?confirm=${encodeURIComponent(typed)}`, method: 'DELETE', label: `Delete ${dep.name}` }).then(refetch).catch(() => {})
                  }}>Delete</button>
                </span>
              </li>
            )
          })}</ul>
        )}
      </div>
      <div className="rounded-md border border-line p-3">
        <h3 className="m-0 mb-2 text-sm font-semibold">Deploy an image to {target}</h3>
        <div className="grid gap-2 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
          <input className="input font-mono" aria-label="Deployment name" placeholder="api" value={d.name} onChange={(e) => setD({ ...d, name: e.target.value })} />
          <input className="input font-mono" aria-label="Image" placeholder="ghcr.io/acme/api:2" value={d.image} onChange={(e) => setD({ ...d, image: e.target.value })} />
          <input className="input" aria-label="Replicas" type="number" min={0} max={50} value={d.replicas} onChange={(e) => setD({ ...d, replicas: e.target.value })} />
          <input className="input" aria-label="Port (optional)" type="number" placeholder="Port" value={d.port} onChange={(e) => setD({ ...d, port: e.target.value })} />
        </div>
        <button type="button" className="btn btn-primary mt-2" disabled={busy || !/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/.test(d.name) || !d.image.trim()}
          onClick={() => run({ path: `/kubernetes/${c.id}/deployments`, body: { namespace: target, name: d.name, image: d.image.trim(), replicas: Number(d.replicas || 1), ...(d.port ? { port: Number(d.port) } : {}) }, label: `Deploy ${d.name}`, success: 'Deployed' })
            .then(() => { setD({ name: '', image: '', replicas: '1', port: '' }); refetch(); onChanged() }).catch(() => {})}>Deploy</button>
      </div>
    </div>
  )
}

/** K8S: existing Kubernetes clusters connected with a service-account token. */
export default function Kubernetes() {
  const page = useOne<R[]>('/kubernetes')
  const { run, busy } = useAction()
  const [f, setF] = useState({ name: '', kubeconfig: '', api_url: '', token: '', ca_cert: '' })
  const [mode, setMode] = useState<'kubeconfig' | 'token'>('kubeconfig')
  const clusters = (page.item as R[] | undefined) ?? []
  const refetch = () => page.refetch()
  const ready = f.name.trim().length >= 2 && (mode === 'kubeconfig' ? f.kubeconfig.trim() : f.api_url.trim() && f.token.trim())

  return (
    <>
      <PageHeader title="Kubernetes" subtitle="Connect existing clusters: see nodes and workloads, scale, restart and deploy." />
      <Card title="Connect a cluster">
        <div className="flex gap-1 mb-3">{(['kubeconfig', 'token'] as const).map((m) => <button key={m} type="button" aria-pressed={mode === m} className={`btn btn-sm ${mode === m ? 'btn-primary' : ''}`} onClick={() => setMode(m)}>{m === 'kubeconfig' ? 'Paste a kubeconfig' : 'API address + token'}</button>)}</div>
        <div className="flex flex-col gap-2 text-sm">
          <label className="flex flex-col gap-1">Name<input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="production" /></label>
          {mode === 'kubeconfig'
            ? <label className="flex flex-col gap-1">Kubeconfig<textarea className="input font-mono text-xs min-h-[120px]" value={f.kubeconfig} onChange={(e) => setF({ ...f, kubeconfig: e.target.value })} placeholder="apiVersion: v1&#10;kind: Config&#10;…" /></label>
            : <>
              <label className="flex flex-col gap-1">API server<input className="input font-mono" value={f.api_url} onChange={(e) => setF({ ...f, api_url: e.target.value })} placeholder="https://10.0.0.5:6443" /></label>
              <label className="flex flex-col gap-1">Service-account token<input className="input font-mono" type="password" autoComplete="off" value={f.token} onChange={(e) => setF({ ...f, token: e.target.value })} /></label>
              <label className="flex flex-col gap-1">Cluster CA certificate (PEM, optional)<textarea className="input font-mono text-xs min-h-[72px]" value={f.ca_cert} onChange={(e) => setF({ ...f, ca_cert: e.target.value })} placeholder="-----BEGIN CERTIFICATE-----" /></label>
            </>}
          <p className="m-0 text-xs text-muted">Use a service-account token whose role allows what you want to do here (list nodes and namespaces; manage deployments and services). It is stored encrypted and never shown again.</p>
          <div><button type="button" className="btn btn-primary" disabled={busy || !ready}
            onClick={() => run({ path: '/kubernetes', body: mode === 'kubeconfig' ? { name: f.name.trim(), kubeconfig: f.kubeconfig } : { name: f.name.trim(), api_url: f.api_url.trim(), token: f.token.trim(), ...(f.ca_cert.trim() ? { ca_cert: f.ca_cert } : {}) }, label: `Connect ${f.name}`, success: 'Cluster connected' })
              .then(() => { setF({ name: '', kubeconfig: '', api_url: '', token: '', ca_cert: '' }); refetch() }).catch(() => {})}>Connect</button></div>
        </div>
      </Card>
      {page.item && clusters.length === 0 && <Card><p className="m-0 text-sm text-muted">No clusters connected yet.</p></Card>}
      {clusters.map((c) => (
        <Card key={String(c.id)}>
          <div className="flex flex-wrap items-center gap-2 mb-3">
            <span className="font-semibold">{String(c.name)}</span>
            <Badge tone={c.status === 'connected' ? 'g' : 'r'}>{c.status === 'connected' ? 'Connected' : 'Failed'}</Badge>
            <span className="text-xs text-muted font-mono">{String(c.api_url)}</span>
            <span className="text-xs text-muted">{c.version ? String(c.version) : ''}{c.checked_at ? ` · checked ${ago(String(c.checked_at))}` : ''}</span>
            <span className="ml-auto flex gap-1">
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `/kubernetes/${c.id}/check`, label: 'Check cluster', success: 'Checked' }).then(refetch).catch(() => {})}>Check</button>
              <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => run({ path: `/kubernetes/${c.id}`, method: 'DELETE', label: 'Disconnect', success: 'Disconnected' }).then(refetch).catch(() => {})}>Disconnect</button>
            </span>
          </div>
          <ClusterView c={c} onChanged={refetch} />
        </Card>
      ))}
    </>
  )
}
