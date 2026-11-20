import { useState } from 'react'
import { useNavigate } from 'react-router'
import { useAction, useList } from '../../api/hooks'
import { Card, Field, PageHeader } from '../../components/Card'
import { StatusBadge } from '../../components/Badge'
import { DataTable, type Column } from '../../components/DataTable'
import { Modal } from '../../components/Overlay'
import { ago } from '../../lib/format'
import { type R } from '../../lib/util'
import { HandOver, summarise, type Project } from './projectParts'

/** PRJ-LIST: one row per project; the details page holds environments, canvas and settings. */
export default function Projects() {
  const { rows, isLoading, error, refetch } = useList<Project>('/projects')
  const nav = useNavigate()
  const { run, busy } = useAction()
  const [open, setOpen] = useState(false)
  const [name, setName] = useState('')
  const [secret, setSecret] = useState<string | null>(null)
  const [hand, setHand] = useState<Project | null>(null)
  const create = () => run<R>({ path: '/projects', body: { name }, label: `Create ${name}`, success: 'Project created' })
    .then((r) => { setOpen(false); setName(''); setSecret((r as R)?.data?.webhook_secret ?? null) }).catch(() => {})
  const columns: Column<Project>[] = [
    { key: 'name', header: 'Project', value: (p) => p.name, sortable: true, render: (p) => <span><span className="font-semibold">{p.name}</span>{p.tags?.includes('enterprise') && <span className="ml-2 rounded bg-purple-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-purple-800 dark:bg-purple-900/40 dark:text-purple-200">Enterprise</span>}{p.description && <span className="block text-xs text-muted">{p.description}</span>}</span> },
    { key: 'status', header: 'Status', value: (p) => summarise(p).health, options: ['running', 'degraded', 'stopped', 'empty'], render: (p) => { const s = summarise(p); return s.apps === 0 ? <span className="text-xs text-muted">no apps</span> : <span className="flex items-center gap-2"><StatusBadge status={s.health} /><span className="text-xs text-muted tabular-nums">{s.running}/{s.apps} running{s.asleep ? ` · ${s.asleep} asleep` : ''}</span></span> } },
    { key: 'envs', header: 'Environments', value: (p) => p.environments.length, kind: 'number', sortable: true, align: 'right' },
    { key: 'apps', header: 'Apps', value: (p) => summarise(p).apps, kind: 'number', sortable: true, align: 'right' },
    { key: 'last', header: 'Last deploy', value: (p) => summarise(p).last ?? '', kind: 'date', sortable: true, render: (p) => { const l = summarise(p).last; return <span className="text-sm text-muted">{l ? ago(l) : 'never'}</span> } },
    { key: 'owner', header: 'Owner', value: (p) => p.organization?.name ?? '', sortable: true },
  ]
  const newBtn = <button type="button" className="btn btn-primary" onClick={() => setOpen(true)}>New project</button>
  return (
    <>
      <PageHeader title="Projects" subtitle="Environments per project, shared variables, branch deploys, promotion and sleep" actions={newBtn} />
      {secret && <div role="status" className="card p-4 mb-4 text-sm">Webhook secret (shown once): <code className="font-mono break-all">{secret}</code></div>}
      <Card pad={false}>
        <DataTable label="Projects" tableId="admin-projects" rows={rows} columns={columns} rowKey={(p) => p.id} loading={isLoading}
          error={error ? 'Projects could not be loaded.' : null} onRetry={() => { void refetch() }}
          searchKeys={(p) => [p.name, p.description ?? '', ...(p.tags ?? []), p.organization?.name ?? '', ...p.environments.flatMap((e) => [e.name, ...e.apps.map((a) => a.service)])].join(' ')}
          searchPlaceholder="Search projects, environments or apps"
          filters={[
            { id: 'running', label: 'All running', test: (p) => summarise(p).health === 'running' },
            { id: 'attention', label: 'Needs attention', test: (p) => ['degraded', 'stopped'].includes(summarise(p).health) },
            { id: 'empty', label: 'No apps', test: (p) => summarise(p).apps === 0 },
          ]}
          onRowClick={(p) => nav(`/admin/projects/${p.id}`)}
          actions={(p) => [
            { label: 'Open', icon: 'eye', onClick: () => nav(`/admin/projects/${p.id}`) },
            { label: 'Show canvas', icon: 'grid', onClick: () => nav(`/admin/projects/${p.id}?tab=canvas`), menuOnly: true },
            { label: 'Settings', onClick: () => nav(`/admin/projects/${p.id}?tab=settings`), menuOnly: true },
            { label: 'Hand over to customer', icon: 'users', onClick: () => setHand(p), menuOnly: true },
          ]}
          empty="No projects yet. A project groups production, staging and preview environments of one product." />
      </Card>
      <Modal open={open} onClose={() => setOpen(false)} title="New project"
        footer={<><button type="button" className="btn" onClick={() => setOpen(false)}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy || !name} onClick={create}>Create</button></>}>
        <Field label="Name" hint="Starts with production (branch main) and staging environments"><input className="input" value={name} onChange={(e) => setName(e.target.value)} /></Field>
      </Modal>
      {hand && <HandOver project={hand} onClose={() => setHand(null)} />}
    </>
  )
}
