import { useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { api } from '../api/client'
import { Card, Field } from './Card'
import { StatusBadge } from './Badge'
import { CopyButton } from './CopyButton'
import { DataTable, type Column } from './DataTable'
import { Modal } from './Overlay'
import Select from './Select'
import { ago } from '../lib/format'
import { type R } from '../lib/util'

type Kind = 'databases' | 'volumes'

/**
 * PWS-C: a project's databases or volumes, with provision and attach. `base` is '' for the admin
 * API and '/app' for the customer portal; both expose the same endpoints. Passwords stay masked
 * until an owner asks for them (that read is audited).
 */
export function ProjectResources({ projectId, kind, canManage, base = '' }: { projectId: string; kind: Kind; canManage: boolean; base?: string }) {
  const path = `${base}/projects/${projectId}/${kind}`
  const q = useOne<R>(path)
  const { run, busy } = useAction()
  const [form, setForm] = useState<R | null>(null)
  const [attach, setAttach] = useState('')
  const [secret, setSecret] = useState<R | null>(null)
  const data = q.item ?? {}
  const rows = (data[kind] ?? []) as R[]
  const nodes = (data.nodes ?? []) as R[]
  const attachable = (data.attachable ?? []) as R[]
  const isDb = kind === 'databases'
  const refresh = () => q.refetch()

  const cols: Column<R>[] = isDb ? [
    { key: 'name', header: 'Database', value: (d) => String(d.name), sortable: true },
    { key: 'engine', header: 'Engine', value: (d) => `${String(d.engine)} ${String(d.version ?? '')}` },
    { key: 'status', header: 'Status', value: (d) => String(d.status), render: (d) => <StatusBadge status={String(d.status)} /> },
    { key: 'conn', header: 'Connection', value: (d) => String((d.connection as R)?.url ?? ''), render: (d) => <code className="font-mono text-xs break-all">{String((d.connection as R)?.url ?? '')}</code> },
    { key: 'backup', header: 'Last backup', value: (d) => String(d.last_backup_at ?? ''), render: (d) => d.last_backup_at ? ago(String(d.last_backup_at)) : 'never' },
  ] : [
    { key: 'name', header: 'Volume', value: (v) => String(v.name), sortable: true },
    { key: 'status', header: 'Status', value: (v) => String(v.status), render: (v) => <StatusBadge status={String(v.status)} /> },
    { key: 'size', header: 'Used', value: (v) => Number(v.size_gb ?? 0), kind: 'number', align: 'right', render: (v) => v.size_gb != null ? `${Number(v.size_gb).toFixed(1)} GB` : '—' },
    { key: 'quota', header: 'Quota', value: (v) => Number(v.quota_gb ?? 0), kind: 'number', align: 'right', render: (v) => v.quota_gb ? `${String(v.quota_gb)} GB` : '—' },
    { key: 'mounts', header: 'Mounts', value: (v) => Number(v.mounts ?? 0), kind: 'number', align: 'right' },
  ]

  const actions = canManage ? (r: R) => [
    ...(isDb ? [{ label: 'Show credentials', icon: 'eye', onClick: () => { api.get<{ data: R }>(`${path}/${String(r.id)}/credentials`).then((res) => setSecret(res.data)).catch(() => {}) } }] : []),
    { label: 'Detach from project', icon: 'close', onClick: () => { run({ method: 'DELETE', path: `${path}/${String(r.id)}`, label: 'Detach', success: 'Detached' }).then(refresh).catch(() => {}) } },
  ] : undefined

  return (
    <div className="flex flex-col gap-4">
      <Card pad={false}>
        <DataTable label={isDb ? 'Databases' : 'Volumes'} tableId={`project-${kind}`} layouts={false} rows={rows} loading={q.isLoading} rowKey={(r) => String(r.id)} columns={cols} actions={actions}
          toolbar={canManage ? <button type="button" className="btn btn-primary" onClick={() => setForm({ server_id: nodes[0]?.id ?? '', engine: 'postgres' })}>{isDb ? 'New database' : 'New volume'}</button> : undefined}
          empty={isDb ? 'No databases in this project yet. Provision one on a node the project runs on, or attach an existing database.' : 'No volumes in this project yet. Create one or attach an existing volume.'} />
      </Card>
      {canManage && attachable.length > 0 && <Card title={isDb ? 'Attach an existing database' : 'Attach an existing volume'} subtitle="Only your organisation's resources that belong to no project are listed.">
        <div className="flex flex-wrap items-end gap-3">
          <Field label={isDb ? 'Database' : 'Volume'}><Select className="input" value={attach} onChange={(e) => setAttach(e.target.value)}><option value="">Choose…</option>{attachable.map((a) => <option key={String(a.id)} value={String(a.id)}>{String(a.name)}</option>)}</Select></Field>
          <button type="button" className="btn" disabled={busy || !attach} onClick={() => run({ method: 'POST', path: `${path}/${attach}/attach`, label: 'Attach', success: 'Attached' }).then(() => { setAttach(''); refresh() }).catch(() => {})}>Attach</button>
        </div>
      </Card>}
      <Modal open={!!form} onClose={() => setForm(null)} title={isDb ? 'New database' : 'New volume'}
        footer={<button type="button" className="btn btn-primary" disabled={busy || !form?.name || !form?.server_id}
          onClick={() => run({ method: 'POST', path, body: isDb ? { name: form?.name, engine: form?.engine, server_id: form?.server_id } : { name: form?.name, server_id: form?.server_id, quota_gb: form?.quota_gb ? Number(form.quota_gb) : null }, label: isDb ? 'Provision database' : 'Create volume', success: 'Started' }).then(() => { setForm(null); refresh() }).catch(() => {})}>Create</button>}>
        <div className="flex flex-col gap-3">
          <Field label="Name"><input className="input" value={String(form?.name ?? '')} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
          {isDb && <Field label="Engine"><Select className="input" value={String(form?.engine ?? 'postgres')} onChange={(e) => setForm({ ...form, engine: e.target.value })}>{((data.engines ?? []) as string[]).map((en) => <option key={en} value={en}>{en}</option>)}</Select></Field>}
          {!isDb && <Field label="Quota (GB)"><input className="input" type="number" min={1} value={String(form?.quota_gb ?? '')} onChange={(e) => setForm({ ...form, quota_gb: e.target.value })} /></Field>}
          <Field label="Node"><Select className="input" value={String(form?.server_id ?? '')} onChange={(e) => setForm({ ...form, server_id: e.target.value })}>{nodes.map((n) => <option key={String(n.id)} value={String(n.id)}>{String(n.name)}</option>)}</Select></Field>
        </div>
      </Modal>
      <Modal open={!!secret} onClose={() => setSecret(null)} title="Connection details" subtitle="This read is recorded in the project's activity.">
        {secret && <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm m-0">
          {(['host', 'port', 'username', 'password', 'url'] as const).map((k) => <div key={k} className="contents"><dt className="text-muted">{k}</dt><dd className="m-0 flex items-center gap-2"><code className="font-mono break-all">{String(secret[k] ?? '—')}</code>{secret[k] != null && <CopyButton value={String(secret[k])} />}</dd></div>)}
        </dl>}
      </Modal>
    </div>
  )
}
