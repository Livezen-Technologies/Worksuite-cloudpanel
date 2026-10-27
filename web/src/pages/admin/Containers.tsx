import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'
import { api } from '../../api/client'
import { useAction, useList, useOne } from '../../api/hooks'
import type { Container, Server } from '../../api/types'
import { Field, PageHeader } from '../../components/Card'
import { Dot, StatusBadge, type Tone } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, Drawer, Modal } from '../../components/Overlay'
import { StepWizard } from '../../components/StepWizard'
import { LogView } from '../../components/LogView'
import { TerminalDialog } from '../../components/Terminal'
import { useToast } from '../../components/Toasts'
import { mb, pct, str, unwrap } from '../../lib/format'
import { serverName, type R } from '../../lib/util'

const ports = (p: Container['ports']) => (Array.isArray(p) ? p.join(', ') : p) || '—'
const dotTone = (c: Container): Tone => (c.state === 'running' ? (c.health === 'unhealthy' ? 'r' : 'g') : c.state === 'paused' ? 'y' : 'n')

function RemoveDialog({ c, open, onClose }: { c: Container; open: boolean; onClose: () => void }) {
  const [keep, setKeep] = useState(true)
  const { run, busy } = useAction()
  return (
    <ConfirmDialog open={open} onClose={onClose} danger title={`Remove ${c.name}?`} confirmText={c.name} confirmLabel="Remove container" busy={busy}
      onConfirm={() => run({ path: `/containers/${c.id}/actions`, body: { action: 'remove', keep_volumes: keep }, label: `Remove ${c.name}` }).then(onClose).catch(() => {})}>
      <p className="m-0">The container is stopped and deleted.</p>
      <label className="flex items-center gap-2 font-semibold">
        <input type="checkbox" checked={keep} onChange={(e) => setKeep(e.target.checked)} /> Keep attached volumes
      </label>
      {!keep && <p role="alert" className="m-0 text-danger text-xs font-semibold">Attached volumes and their data will be deleted permanently.</p>}
    </ConfirmDialog>
  )
}

function DetailPanel({ id, onClose }: { id: string; onClose: () => void }) {
  const { item: c } = useOne<Container & R>(`/containers/${id}`, undefined, { refetchInterval: 10000 })
  const { run, busy } = useAction()
  const [term, setTerm] = useState(false)
  const [remove, setRemove] = useState(false)
  const [dup, setDup] = useState(false)
  const act = (action: string) => c && run({ path: `/containers/${c.id}/actions`, body: { action }, label: `${action[0].toUpperCase()}${action.slice(1)} ${c.name}` })
  return (
    <Drawer open onClose={onClose} title={c?.name ?? 'Container'} width={480}>
      {!c ? 'Loading…' : (
        <div className="flex flex-col gap-5">
          <div>
            <div className="text-xs text-muted">Container</div>
            <div className="font-mono text-xs text-muted mt-1 break-all">{c.image}</div>
            <div className="flex gap-2 mt-2"><StatusBadge status={c.state} />{c.health && <StatusBadge status={c.health} />}</div>
          </div>
          <div className="flex flex-wrap gap-2">
            {c.state === 'running' ? <button type="button" className="btn btn-sm" disabled={busy} onClick={() => act('stop')}>Stop</button> : <button type="button" className="btn btn-sm" disabled={busy} onClick={() => act(c.state === 'paused' ? 'unpause' : 'start')}>{c.state === 'paused' ? 'Unpause' : 'Start'}</button>}
            <button type="button" className="btn btn-sm" disabled={busy} onClick={() => act('restart')}>Restart</button>
            {c.state === 'running' && <button type="button" className="btn btn-sm" disabled={busy} onClick={() => act('pause')}>Pause</button>}
            <button type="button" className="btn btn-sm" disabled={c.state !== 'running'} onClick={() => setTerm(true)}>Terminal</button>
            <button type="button" className="btn btn-sm" onClick={() => setDup(true)}>Duplicate</button>
            <button type="button" className="btn btn-sm btn-danger" onClick={() => setRemove(true)}>Remove</button>
          </div>
          <dl className="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            {([['Server', serverName(c)], ['CPU / Memory', `${pct(c.cpu_pct)} · ${mb(c.mem_mb)}`], ['Limits', c.limits ? `${c.limits.cpus ?? '—'} vCPU · ${mb(c.limits.memory_mb)}` : '—'], ['User', c.user ?? '—'], ['Restart policy', c.restart_policy ?? '—'], ['Ports', ports(c.ports)]] as const).map(([k, v]) => (
              <div key={k} className="contents"><dt className="text-muted">{k}</dt><dd className="m-0 font-semibold break-all">{v}</dd></div>
            ))}
          </dl>
          <LogView path={`/containers/${c.id}/logs`} title="Live logs" height={260} />
          <TerminalDialog open={term} onClose={() => setTerm(false)} containerId={c.id} name={c.name} />
          <RemoveDialog c={c} open={remove} onClose={() => { setRemove(false) }} />
          {dup && <CreateContainer open onClose={() => setDup(false)} from={c} />}
        </div>
      )}
    </Drawer>
  )
}

function PruneDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [preview, setPreview] = useState<R | null>(null)
  const [loading, setLoading] = useState(false)
  const toast = useToast()
  const { run, busy } = useAction()
  const load = async () => {
    setLoading(true)
    try { setPreview(unwrap<R>(await api.post('/containers/prune-preview'))) } catch (e) { toast.error(e, 'Preview failed'); onClose() } finally { setLoading(false) }
  }
  useEffect(() => { if (open) load() }, [open]) // eslint-disable-line react-hooks/exhaustive-deps
  const items = ((preview?.containers ?? preview?.items ?? (Array.isArray(preview) ? preview : [])) as R[])
  const close = () => { setPreview(null); onClose() }
  return (
    <Modal open={open} onClose={close} title="Safe cleanup"
      footer={<><button type="button" className="btn" onClick={close}>Cancel</button><button type="button" className="btn btn-primary" disabled={busy || !items.length} onClick={() => run({ path: '/containers/prune', body: { ids: items.map((x) => x.id).filter(Boolean) }, label: 'Prune stopped containers' }).then(close).catch(() => {})}>Remove {items.length} containers</button></>}>
      <p className="mt-0 text-muted">Only stopped containers are removed. Volumes are kept. Review the list first:</p>
      {loading ? 'Building preview…' : items.length === 0 ? <p>Nothing to clean up.</p> : (
        <ul className="m-0 pl-5">{items.map((x, i) => <li key={x.id ?? i}><span className="font-semibold">{x.name}</span> <span className="text-muted text-xs">{x.image} · {serverName(x)}</span></li>)}</ul>
      )}
      {preview?.reclaimable && <p className="text-sm font-semibold">Reclaimable: {str(preview.reclaimable)}</p>}
    </Modal>
  )
}

interface Kv { key: string; value: string; secret?: boolean }
function KvEditor({ rows, onChange, label, secret }: { rows: Kv[]; onChange: (r: Kv[]) => void; label: string; secret?: boolean }) {
  return (
    <fieldset className="border-0 p-0 m-0 flex flex-col gap-2">
      <legend className="text-[13px] font-semibold mb-2">{label}</legend>
      {rows.map((r, i) => (
        <div key={i} className="flex gap-2">
          <input className="input font-mono" aria-label={`${label} ${i + 1} key`} placeholder="KEY" value={r.key} onChange={(e) => onChange(rows.map((x, j) => (j === i ? { ...x, key: e.target.value } : x)))} />
          <input className="input font-mono" type={secret ? 'password' : 'text'} aria-label={`${label} ${i + 1} value`} placeholder="value" value={r.value} onChange={(e) => onChange(rows.map((x, j) => (j === i ? { ...x, value: e.target.value } : x)))} />
          <button type="button" className="btn btn-sm" aria-label={`Remove ${label} ${i + 1}`} onClick={() => onChange(rows.filter((_, j) => j !== i))}>✕</button>
        </div>
      ))}
      <button type="button" className="btn btn-sm self-start" onClick={() => onChange([...rows, { key: '', value: '' }])}>+ Add</button>
    </fieldset>
  )
}

export function CreateContainer({ open, onClose, from }: { open: boolean; onClose: () => void; from?: Container & R }) {
  const servers = useList<Server>('/servers')
  const networks = useList<R>('/networks')
  const { run, busy } = useAction()
  const [step, setStep] = useState(0)
  const [f, setF] = useState({
    image: from?.image ?? '', registry_id: '', name: from ? `${from.name}-copy` : '', server_id: from?.server_id ?? '',
    ports: from ? ports(from.ports).replace('—', '') : '', network: '', mounts: '', cpus: String(from?.limits?.cpus ?? '1'), memory_mb: String(from?.limits?.memory_mb ?? '512'),
    restart_policy: from?.restart_policy ?? 'unless-stopped', health_cmd: '', health_interval: '30',
    non_root: true, cap_drop_all: true, no_new_privileges: true, read_only: false,
  })
  const [env, setEnv] = useState<Kv[]>([])
  const [secrets, setSecrets] = useState<Kv[]>([])
  const regs = useList<R>('/registries')
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setF({ ...f, [k]: e.target.type === 'checkbox' ? (e.target as HTMLInputElement).checked : e.target.value })
  const grid = 'grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]'
  const submit = () => run({
    path: '/containers', label: `Create ${f.name}`,
    body: {
      server_id: f.server_id, name: f.name, image: f.image, registry_id: f.registry_id || null,
      env: env.filter((x) => x.key).map((x) => ({ key: x.key, value: x.value, secret: false })).concat(secrets.filter((x) => x.key).map((x) => ({ key: x.key, value: x.value, secret: true }))),
      ports: f.ports.split(',').map((x) => x.trim()).filter(Boolean), network: f.network || null,
      mounts: f.mounts.split('\n').map((x) => x.trim()).filter(Boolean),
      limits: { cpus: Number(f.cpus), memory_mb: Number(f.memory_mb) }, restart_policy: f.restart_policy,
      healthcheck: f.health_cmd ? { test: f.health_cmd, interval_s: Number(f.health_interval) } : null,
      security: { non_root: f.non_root, cap_drop_all: f.cap_drop_all, no_new_privileges: f.no_new_privileges, read_only_rootfs: f.read_only },
      duplicate_of: from?.id,
    },
  }).then(onClose).catch(() => {})
  const steps = [
    { label: 'Image', validate: () => (!f.image.trim() ? 'Enter an image, for example nginx:1.27-alpine.' : null), content: (
      <div className={grid}>
        <Field label="Image"><input className="input font-mono" value={f.image} onChange={set('image')} placeholder="nginx:1.27-alpine" /></Field>
        <Field label="Registry credentials"><select className="input" value={f.registry_id} onChange={set('registry_id')}><option value="">Public / none</option>{regs.rows.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</select></Field>
      </div>) },
    { label: 'Placement & env', validate: () => (!/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/.test(f.name) ? 'Enter a valid container name.' : !f.server_id ? 'Choose a server.' : null), content: (
      <div className="flex flex-col gap-4">
        <div className={grid}>
          <Field label="Name"><input className="input" value={f.name} onChange={set('name')} /></Field>
          <Field label="Server"><select className="input" value={f.server_id} onChange={set('server_id')}><option value="">Choose…</option>{servers.rows.map((s) => <option key={s.id} value={s.id} disabled={s.status === 'maintenance' || s.status === 'offline'}>{s.name}{s.status !== 'online' ? ` (${s.status})` : ''}</option>)}</select></Field>
        </div>
        <KvEditor label="Environment variables" rows={env} onChange={setEnv} />
        <KvEditor label="Secrets" rows={secrets} onChange={setSecrets} secret />
      </div>) },
    { label: 'Network & storage', content: (
      <div className={grid}>
        <Field label="Ports" hint="host:container, comma separated"><input className="input font-mono" value={f.ports} onChange={set('ports')} placeholder="8080:80" /></Field>
        <Field label="Network"><select className="input" value={f.network} onChange={set('network')}><option value="">Default bridge</option>{networks.rows.filter((n) => !f.server_id || n.server_id === f.server_id).map((n) => <option key={n.id} value={n.id}>{n.name}</option>)}</select></Field>
        <label className="label">Mounts <span className="text-xs font-normal text-muted">One per line: volume:/path or /host:/path</span><textarea className="input font-mono" rows={3} value={f.mounts} onChange={(e) => setF({ ...f, mounts: e.target.value })} /></label>
      </div>) },
    { label: 'Limits & health', content: (
      <div className="flex flex-col gap-4">
        <div className={grid}>
          <Field label="CPU limit (vCPU)"><input className="input" type="number" step="0.25" min="0.25" value={f.cpus} onChange={set('cpus')} /></Field>
          <Field label="Memory limit (MB)"><input className="input" type="number" min="16" value={f.memory_mb} onChange={set('memory_mb')} /></Field>
          <Field label="Restart policy"><select className="input" value={f.restart_policy} onChange={set('restart_policy')}>{['no', 'on-failure', 'unless-stopped', 'always'].map((x) => <option key={x}>{x}</option>)}</select></Field>
          <Field label="Health check command"><input className="input font-mono" value={f.health_cmd} onChange={set('health_cmd')} placeholder="curl -f http://localhost/health" /></Field>
          <Field label="Health interval (s)"><input className="input" type="number" value={f.health_interval} onChange={set('health_interval')} /></Field>
        </div>
        <fieldset className="border border-line rounded-lg p-4 m-0 flex flex-col gap-2 text-sm">
          <legend className="px-1 font-semibold text-[13px]">Secure defaults</legend>
          {([['non_root', 'Run as non-root user'], ['cap_drop_all', 'Drop all Linux capabilities'], ['no_new_privileges', 'no-new-privileges'], ['read_only', 'Read-only root filesystem (where compatible)']] as const).map(([k, l]) => (
            <label key={k} className="flex gap-2 items-center"><input type="checkbox" checked={f[k] as boolean} onChange={set(k)} />{l}</label>
          ))}
        </fieldset>
      </div>) },
    { label: 'Review', content: (
      <dl className="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
        {([['Image', f.image], ['Name', f.name], ['Server', servers.rows.find((s) => s.id === f.server_id)?.name], ['Ports', f.ports || '—'], ['Limits', `${f.cpus} vCPU · ${f.memory_mb} MB`], ['Restart', f.restart_policy], ['Env / secrets', `${env.length} / ${secrets.length}`], ['Security', [f.non_root && 'non-root', f.cap_drop_all && 'cap_drop ALL', f.no_new_privileges && 'no-new-privileges', f.read_only && 'read-only'].filter(Boolean).join(', ')]] as const).map(([k, v]) => (
          <div key={k} className="contents"><dt className="text-muted">{k}</dt><dd className="m-0 font-semibold break-all">{v || '—'}</dd></div>
        ))}
      </dl>) },
  ]
  return (
    <Modal open={open} onClose={onClose} title={from ? `Duplicate ${from.name}` : 'Create container'} width={760}>
      <StepWizard steps={steps} step={step} onStep={setStep} onFinish={submit} finishLabel="Create container" busy={busy} />
    </Modal>
  )
}

export default function Containers() {
  const [params, setParams] = useSearchParams()
  const [server, setServer] = useState('')
  const servers = useList<Server>('/servers')
  const { rows, isLoading } = useList<Container>('/containers', { server_id: server || undefined }, { refetchInterval: 10000 })
  const { run } = useAction()
  const [prune, setPrune] = useState(false)
  const [create, setCreate] = useState(false)
  const [removing, setRemoving] = useState<Container | null>(null)
  const sel = params.get('id')
  const bulk = (action: 'restart' | 'stop') => (ids: string[]) => run({ path: '/containers/bulk-actions', body: { ids, action }, label: `${action === 'restart' ? 'Restart' : 'Stop'} ${ids.length} containers` }).catch(() => {})
  return (
    <>
      <PageHeader title="Containers" subtitle="Lifecycle, logs, exec sessions and resource limits across all servers"
        actions={<><button type="button" className="btn" onClick={() => setPrune(true)}>Safe cleanup</button><button type="button" className="btn btn-primary" onClick={() => setCreate(true)}>Create container</button></>} />
      <DataTable<Container> label="Containers" rows={rows} loading={isLoading} rowKey={(r) => r.id} rowLabel={(r) => r.name} selectable selectedRowId={sel}
        searchPlaceholder="Filter by name or image…" searchKeys={(r) => `${r.name} ${r.image}`}
        onRowClick={(r) => setParams({ id: r.id })}
        filters={[
          { id: 'running', label: 'Running', test: (r) => r.state === 'running' },
          { id: 'exited', label: 'Exited', test: (r) => r.state === 'exited' },
          { id: 'paused', label: 'Paused', test: (r) => r.state === 'paused' },
          { id: 'unhealthy', label: 'Unhealthy', test: (r) => r.health === 'unhealthy' },
        ]}
        toolbar={<select className="input !w-auto" aria-label="Server" value={server} onChange={(e) => setServer(e.target.value)}><option value="">All servers</option>{servers.rows.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>}
        bulkActions={[{ label: 'Restart', onClick: bulk('restart') }, { label: 'Stop', onClick: bulk('stop') }]}
        minWidth={980}
        columns={[
          { key: 'name', header: 'Name', render: (r) => <span className="inline-flex items-center gap-2 font-semibold"><Dot tone={dotTone(r)} />{r.name}</span> },
          { key: 'image', header: 'Image', render: (r) => <span className="font-mono text-[11.5px] text-muted">{r.image}</span> },
          { key: 'server', header: 'Server', value: (r) => serverName(r as unknown as R) },
          { key: 'state', header: 'State', render: (r) => <StatusBadge status={r.state} /> },
          { key: 'health', header: 'Health', render: (r) => <StatusBadge status={r.health ?? '—'} /> },
          { key: 'cpu_pct', header: 'CPU', value: (r) => r.cpu_pct ?? 0, render: (r) => pct(r.cpu_pct) },
          { key: 'mem_mb', header: 'Memory', value: (r) => r.mem_mb ?? 0, render: (r) => mb(r.mem_mb) },
          { key: 'ports', header: 'Ports', render: (r) => <span className="font-mono text-[11.5px] text-muted">{ports(r.ports)}</span> },
          { key: 'actions', header: '', sortable: false, render: (r) => (
            <span className="flex gap-3 justify-end" onClick={(e) => e.stopPropagation()}>
              <button type="button" className="bg-transparent border-0 p-0 font-semibold text-accent-fg cursor-pointer" onClick={() => setParams({ id: r.id })}>Logs</button>
              <button type="button" className="bg-transparent border-0 p-0 font-semibold text-danger cursor-pointer" onClick={() => setRemoving(r)}>Remove</button>
            </span>) },
        ]} />
      <p className="m-0 text-xs text-muted">Removing a container never deletes attached volumes unless you untick “Keep attached volumes”.</p>
      {sel && <DetailPanel id={sel} onClose={() => setParams({})} />}
      <PruneDialog open={prune} onClose={() => setPrune(false)} />
      {create && <CreateContainer open onClose={() => setCreate(false)} />}
      {removing && <RemoveDialog c={removing} open onClose={() => setRemoving(null)} />}
    </>
  )
}
