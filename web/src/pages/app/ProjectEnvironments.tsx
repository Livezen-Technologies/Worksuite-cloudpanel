import { useState } from 'react'
import { Link } from 'react-router'
import { useAction, useOne } from '../../api/hooks'
import { Card } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { Modal } from '../../components/Overlay'
import type { R } from '../../lib/util'

const KIND: Record<string, 'g' | 'y' | 'b'> = { production: 'g', staging: 'y', development: 'b' }
const KINDS = [['development', 'Development'], ['staging', 'Staging'], ['production', 'Production']]

/** UPRJ-ENV: a project's environments, copied from one another and promoted between. */
export function ProjectEnvironments({ projectId }: { projectId: string }) {
  const q = useOne<R[]>(`/app/projects/${projectId}/environments`)
  const { run, busy } = useAction()
  const [adding, setAdding] = useState(false)
  const [f, setF] = useState({ name: '', kind: 'staging', branch: '', copy_from: '' })
  const [promote, setPromote] = useState<Record<string, string>>({})
  const envs = q.item ?? []
  const refetch = () => q.refetch()
  const add = () => run({ path: `/app/projects/${projectId}/environments`, body: { name: f.name.trim(), kind: f.kind, branch: f.branch.trim() || null, copy_from: f.copy_from || null }, label: `Add ${f.name}`, success: f.copy_from ? 'Environment copied. Deploy its apps when you are ready.' : 'Environment added' })
    .then(() => { setAdding(false); setF({ name: '', kind: 'staging', branch: '', copy_from: '' }); refetch() }).catch(() => {})

  return (
    <>
      <div className="flex justify-end"><button type="button" className="btn btn-primary" disabled={envs.length >= 5} onClick={() => setAdding(true)}>+ Add environment</button></div>
      {envs.map((e) => {
        const others = envs.filter((o) => o.id !== e.id)
        const apps = (e.apps as R[]) ?? []
        const to = promote[String(e.id)] ?? ''
        return (
          <Card key={String(e.id)} title={<span className="flex items-center gap-2">{String(e.name)} <Badge tone={KIND[String(e.kind)] ?? 'n'}>{String(e.kind)}</Badge></span>}
            subtitle={[e.branch ? `deploys branch ${e.branch}` : null, e.duplicated_from_id ? `copied from ${envs.find((o) => o.id === e.duplicated_from_id)?.name ?? 'another environment'}` : null].filter(Boolean).join(' · ') || undefined}
            actions={apps.length === 0 && envs.length > 1 ? <button type="button" className="btn btn-sm btn-danger" aria-label={`Delete ${e.name} environment`} disabled={busy} onClick={() => { if (window.confirm(`Delete the ${e.name} environment?`)) run({ path: `/app/environments/${e.id}`, method: 'DELETE', label: `Delete ${e.name}`, success: 'Environment deleted' }).then(refetch).catch(() => {}) }}>Delete</button> : undefined}>
            {apps.length === 0 ? <p className="m-0 text-sm text-muted">No apps here yet.</p> : (
              <ul className="list-none m-0 p-0 flex flex-col">{apps.map((a) => (
                <li key={String(a.id)} className="flex items-center gap-3 py-2 border-t border-line first:border-t-0 text-sm">
                  <Link className="flex-1 font-semibold" to={`/app/apps/${a.id}`}>{String(a.name)}</Link>
                  {a.url ? <a className="text-xs text-muted" href={String(a.url)} target="_blank" rel="noreferrer">{String(a.url).replace('https://', '')}</a> : null}
                  <Badge tone={a.status === 'running' ? 'g' : 'n'}>{String(a.status ?? '—')}</Badge>
                </li>
              ))}</ul>
            )}
            {apps.length > 0 && others.length > 0 && <div className="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-line">
              <span className="text-sm">Promote to</span>
              <select className="input w-auto" aria-label={`Promote ${e.name} to`} value={to} onChange={(x) => setPromote({ ...promote, [String(e.id)]: x.target.value })}>
                <option value="">Choose…</option>{others.map((o) => <option key={String(o.id)} value={String(o.id)}>{String(o.name)}</option>)}
              </select>
              <button type="button" className="btn btn-sm" disabled={busy || !to} onClick={() => {
                const target = envs.find((o) => o.id === to)
                if (!window.confirm(`Deploy what runs in ${e.name} to the same apps in ${target?.name}? Nothing is rebuilt; ${target?.name} keeps its own variables.`)) return
                run<{ data: { results: R[] } }>({ path: `/app/environments/${e.id}/promote`, body: { target_environment_id: to }, label: `Promote ${e.name}` })
                  .then((r) => {
                    const skipped = (r?.data?.results ?? []).filter((x) => x.skipped)
                    if (skipped.length) window.alert(`Skipped: ${skipped.map((x) => `${x.service} (${x.skipped})`).join(', ')}`)
                    refetch()
                  }).catch(() => {})
              }}>Promote</button>
            </div>}
          </Card>
        )
      })}
      <Modal open={adding} onClose={() => setAdding(false)} title="Add environment" subtitle="Start empty, or copy another environment’s apps and variables. Copies are not deployed until you deploy them."
        footer={<><button type="button" className="btn" onClick={() => setAdding(false)}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy || f.name.trim().length < 2} onClick={add}>Add environment</button></>}>
        <div className="flex flex-col gap-3">
          <label className="text-sm font-medium flex flex-col gap-1">Name<input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="Staging" /></label>
          <label className="text-sm font-medium flex flex-col gap-1">Type<select className="input" value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value })}>{KINDS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></label>
          <label className="text-sm font-medium flex flex-col gap-1">Copy apps from<select className="input" value={f.copy_from} onChange={(e) => setF({ ...f, copy_from: e.target.value })}><option value="">Nothing (start empty)</option>{envs.map((o) => <option key={String(o.id)} value={String(o.id)}>{String(o.name)}</option>)}</select></label>
          <label className="text-sm font-medium flex flex-col gap-1">Git branch (optional)<input className="input font-mono" value={f.branch} onChange={(e) => setF({ ...f, branch: e.target.value })} placeholder="develop" /></label>
          {f.copy_from && <p className="m-0 text-xs text-muted">Each copied app counts toward your plan once you deploy it.</p>}
        </div>
      </Modal>
    </>
  )
}
