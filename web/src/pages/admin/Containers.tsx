import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'
import { api } from '../../api/client'
import { useAction, useList, useOne, useServerName } from '../../api/hooks'
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
import { type R } from '../../lib/util'
import Select from '../../components/Select'
import { Icon } from '../../components/Icon'
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react'

const ports = (p: Container['ports']) => (Array.isArray(p) ? p.join(', ') : p) || '—'

/** "sha256:39d0…" and "repo@sha256:4c9b…" read as a short digest; tags stay as they are. */
export function shortImage(image: string): string {
  const m = image.match(/^(.*?)@?sha256:([0-9a-f]{12})[0-9a-f]*$/i)
  if (!m) return image
  return m[1] ? `${m[1]}@${m[2]}` : `sha256:${m[2]}`
}

/** "0.0.0.0:18081->18081/tcp" → "18081→18081", "127.0.0.1:8080->80/tcp" → "local 8080→80", "3000/tcp" → "3000". */
export function shortPort(p: string): string {
  const m = p.trim().match(/^(?:\[?([\d.:a-f]*)\]?:)?(\d+)(?:->(\d+))?(?:\/(tcp|udp))?$/i)
  if (!m) return p.trim()
  const [, ip, a, b, proto] = m
  const local = ip === '127.0.0.1' || ip === '::1'
  const core = b ? `${a}→${b}` : a
  return `${local ? 'local ' : ''}${core}${proto?.toLowerCase() === 'udp' ? '/udp' : ''}`
}

/** IPv4 and IPv6 publish the same port twice; show it once. */
export function portList(p: Container['ports']): string[] {
  const all = (Array.isArray(p) ? p : String(p ?? '').split(',')).map((x) => x.trim()).filter(Boolean)
  return [...new Set(all.map(shortPort))]
}

function Meter({ value, max, label }: { value: number; max: number; label: string }) {
  const pctv = max > 0 ? Math.min(100, (value / max) * 100) : 0
  const tone = pctv >= 90 ? 'var(--r-fg)' : pctv >= 70 ? 'var(--y-fg)' : 'var(--accent)'
  return (
    <span className="inline-flex flex-col items-end gap-1">
      <span className="tabular-nums">{label}</span>
      <span aria-hidden="true" className="block h-1 w-14 rounded-full bg-head overflow-hidden"><span className="block h-full rounded-full" style={{ width: `${Math.max(pctv, value > 0 ? 3 : 0)}%`, background: tone }} /></span>
    </span>
  )
}
const dotTone = (c: Container): Tone => (c.state === 'running' ? (c.health === 'unhealthy' ? 'r' : 'g') : c.state === 'paused' ? 'y' : 'n')

/**
 * Infrastructure the panel itself depends on. Removing or stopping the ingress
 * proxy takes every site offline - including this panel - so those actions are
 * withheld rather than merely confirmed.
 */
function isProtected(c: { name?: string; image?: string }): boolean {
  const n = (c.name ?? '').toLowerCase()
  const i = (c.image ?? '').toLowerCase()
  return n.includes('traefik') || i.startsWith('traefik:') || n.includes('dockermgr-agent')
}

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

const menuItems = 'z-80 min-w-44 rounded-xl border border-line bg-card py-1.5 shadow-[0_12px_32px_-8px_rgba(15,23,42,.22),0_2px_6px_rgba(15,23,42,.06)] outline-none [--anchor-gap:6px]'
const menuItem = 'flex w-full items-center gap-2.5 px-3.5 py-2 text-sm text-left border-0 bg-transparent cursor-pointer text-fg data-focus:bg-head'

/** "4d 3h", "12m" from seconds. */
const since = (s?: number | null) => {
  if (!s || s < 0) return null
  const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60)
  return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : `${Math.max(1, m)}m`
}

function DetailPanel({ id, onClose }: { id: string; onClose: () => void }) {
  const serverName = useServerName()
  const { item: c } = useOne<Container & R>(`/containers/${id}`, undefined, { refetchInterval: 10000 })
  const { run, busy } = useAction()
  const [term, setTerm] = useState(false)
  const [remove, setRemove] = useState(false)
  const [dup, setDup] = useState(false)
  const act = (action: string) => c && run({ path: `/containers/${c.id}/actions`, body: { action }, label: `${action[0].toUpperCase()}${action.slice(1)} ${c.name}` })
  const portList = c ? (Array.isArray(c.ports) ? c.ports : String(c.ports ?? '').split(',').map((x) => x.trim())).filter(Boolean) : []
  const limits = c?.limits && (c.limits.cpus || c.limits.memory_mb)
    ? [c.limits.cpus ? `${c.limits.cpus} vCPU` : null, c.limits.memory_mb ? mb(c.limits.memory_mb) : null].filter(Boolean).join(' · ')
    : null
  const up = since(c?.uptime_seconds as number | undefined)
  const running = c?.state === 'running'
  return (
    <Drawer open onClose={onClose} title={c?.name ?? 'Container'} width={520}>
      {!c ? 'Loading…' : (
        <div className="flex flex-col gap-5">
          <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge status={c.state} />{c.health && <StatusBadge status={c.health} />}
              {running && up && <span className="text-xs text-muted">up {up}</span>}
            </div>
            <code className="font-mono text-xs text-muted break-all" title="Image">{c.image}</code>
          </div>

          {/* One row at drawer width: the state change, the two lifecycle actions,
              Terminal, and the rarely used ones (duplicate, remove) under "More". */}
          <div className="flex items-center gap-2">
            {!isProtected(c) && (running
              ? <button type="button" className="btn btn-sm gap-1.5" disabled={busy} onClick={() => act('stop')}><Icon name="stop" size={14} />Stop</button>
              : <button type="button" className="btn btn-sm btn-primary gap-1.5" disabled={busy} onClick={() => act(c.state === 'paused' ? 'unpause' : 'start')}><Icon name="play" size={14} />{c.state === 'paused' ? 'Unpause' : 'Start'}</button>)}
            <span className="btn-group">
              <button type="button" className="btn btn-sm gap-1.5" disabled={busy} onClick={() => act('restart')}><Icon name="refresh" size={14} />Restart</button>
              {!isProtected(c) && running && <button type="button" className="btn btn-sm gap-1.5" disabled={busy} onClick={() => act('pause')}><Icon name="pause" size={14} />Pause</button>}
            </span>
            <button type="button" className="btn btn-sm btn-primary" disabled={!running} title={running ? 'Open a shell in this container' : 'Start the container to open a shell'} onClick={() => setTerm(true)}>Terminal</button>
            {!isProtected(c) && (
              <Menu>
                <MenuButton className="btn btn-sm ml-auto" aria-label="More actions" title="More actions"><Icon name="dots" size={16} /></MenuButton>
                <MenuItems anchor="bottom end" className={menuItems}>
                  <MenuItem><button type="button" className={menuItem} onClick={() => setDup(true)}><Icon name="duplicate" size={16} />Duplicate</button></MenuItem>
                  <MenuItem><button type="button" className={`${menuItem} !text-danger`} onClick={() => setRemove(true)}><Icon name="trash" size={16} />Remove…</button></MenuItem>
                </MenuItems>
              </Menu>
            )}
          </div>
          {isProtected(c) && (
            <p className="m-0 rounded-lg bg-y-bg px-3 py-2 text-sm text-y-fg">
              Core infrastructure. Every site on this server, and this panel, is served through it,
              so stop and remove are disabled here. Manage it from the server if you really need to.
            </p>
          )}

          <dl className="m-0 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line text-sm">
            {([
              ['Server', serverName(c)],
              ['Usage', `${pct(c.cpu_pct)} CPU · ${mb(c.mem_mb)}`],
              ['Limits', limits ?? <span className="text-muted">No limit</span>],
              ['User', c.user ? <code className="font-mono text-[13px]">{c.user}</code> : <span className="text-muted">Image default</span>],
              ['Restart policy', c.restart_policy ?? '—'],
              ['Ports', portList.length ? <span className="flex flex-wrap gap-1">{portList.map((p) => <code key={p} className="rounded bg-head px-1.5 py-0.5 font-mono text-xs">{p}</code>)}</span> : <span className="text-muted">None</span>],
            ] as [string, React.ReactNode][]).map(([k, v]) => (
              <div key={k} className="flex flex-col gap-1 bg-card px-3 py-2.5 min-w-0">
                <dt className="text-xs text-muted">{k}</dt><dd className="m-0 font-medium break-words">{v}</dd>
              </div>
            ))}
          </dl>

          <LogView path={`/containers/${c.id}/logs`} title="Live logs" height="max(280px, calc(100dvh - 500px))" />
          <TerminalDialog open={term} onClose={() => setTerm(false)} containerId={c.id} name={c.name} />
          <RemoveDialog c={c} open={remove} onClose={() => { setRemove(false) }} />
          {dup && <CreateContainer open onClose={() => setDup(false)} from={c} />}
        </div>
      )}
    </Drawer>
  )
}

function PruneDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const serverName = useServerName()
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
        <Field label="Registry credentials"><Select className="input" value={f.registry_id} onChange={set('registry_id')}><option value="">Public / none</option>{regs.rows.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</Select></Field>
      </div>) },
    { label: 'Placement & env', validate: () => (!/^[a-zA-Z0-9][a-zA-Z0-9_.-]+$/.test(f.name) ? 'Enter a valid container name.' : !f.server_id ? 'Choose a server.' : null), content: (
      <div className="flex flex-col gap-4">
        <div className={grid}>
          <Field label="Name"><input className="input" value={f.name} onChange={set('name')} /></Field>
          <Field label="Server"><Select className="input" value={f.server_id} onChange={set('server_id')}><option value="">Choose…</option>{servers.rows.map((s) => <option key={s.id} value={s.id} disabled={s.status === 'maintenance' || s.status === 'offline'}>{s.name}{s.status !== 'online' ? ` (${s.status})` : ''}</option>)}</Select></Field>
        </div>
        <KvEditor label="Environment variables" rows={env} onChange={setEnv} />
        <KvEditor label="Secrets" rows={secrets} onChange={setSecrets} secret />
      </div>) },
    { label: 'Network & storage', content: (
      <div className={grid}>
        <Field label="Ports" hint="host:container, comma separated"><input className="input font-mono" value={f.ports} onChange={set('ports')} placeholder="8080:80" /></Field>
        <Field label="Network"><Select className="input" value={f.network} onChange={set('network')}><option value="">Default bridge</option>{networks.rows.filter((n) => !f.server_id || n.server_id === f.server_id).map((n) => <option key={n.id} value={n.id}>{n.name}</option>)}</Select></Field>
        <label className="label">Mounts <span className="text-xs font-normal text-muted">One per line: volume:/path or /host:/path</span><textarea className="input font-mono" rows={3} value={f.mounts} onChange={(e) => setF({ ...f, mounts: e.target.value })} /></label>
      </div>) },
    { label: 'Limits & health', content: (
      <div className="flex flex-col gap-4">
        <div className={grid}>
          <Field label="CPU limit (vCPU)"><input className="input" type="number" step="0.25" min="0.25" value={f.cpus} onChange={set('cpus')} /></Field>
          <Field label="Memory limit (MB)"><input className="input" type="number" min="16" value={f.memory_mb} onChange={set('memory_mb')} /></Field>
          <Field label="Restart policy"><Select className="input" value={f.restart_policy} onChange={set('restart_policy')}>{['no', 'on-failure', 'unless-stopped', 'always'].map((x) => <option key={x}>{x}</option>)}</Select></Field>
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

/** Everything a person might type to find a container. */
export function containerSearchText(r: Container, server: string): string {
  return [r.name, r.image, r.application_name, r.compose_project, r.compose_service, server, r.server_name, r.docker_id?.slice(0, 12), r.id, ports(r.ports)]
    .filter(Boolean).join(' ')
}

export default function Containers() {
  const serverName = useServerName()
  const [params, setParams] = useSearchParams()
  const [server, setServer] = useState('')
  const servers = useList<Server>('/servers')
  // The table searches and filters in the browser, so it needs every container, not the first page.
  const { rows, isLoading } = useList<Container>('/containers', { server_id: server || undefined, per_page: 200 }, { refetchInterval: 10000 })
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
        searchPlaceholder="Search name, image, app, stack, server, ID or port…" searchKeys={(r) => containerSearchText(r, serverName(r as unknown as R))}
        onRowClick={(r) => setParams({ id: r.id })}
        filters={[
          { id: 'running', label: 'Running', test: (r) => r.state === 'running' },
          { id: 'exited', label: 'Exited', test: (r) => r.state === 'exited' },
          { id: 'paused', label: 'Paused', test: (r) => r.state === 'paused' },
          { id: 'unhealthy', label: 'Unhealthy', test: (r) => r.health === 'unhealthy' },
        ]}
        toolbar={<Select className="input !w-auto" aria-label="Server" value={server} onChange={(e) => setServer(e.target.value)}><option value="">All servers</option>{servers.rows.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select>}
        bulkActions={[{ label: 'Restart', icon: 'refresh', onClick: bulk('restart') }, { label: 'Stop', icon: 'stop', onClick: bulk('stop'), confirm: 'The selected containers stop until you start them again.' }]}
        tableId="containers"
        actions={(r) => {
          const one = (action: string) => () => run({ path: `/containers/${r.id}/actions`, body: { action }, label: `${action[0].toUpperCase()}${action.slice(1)} ${r.name}` }).catch(() => {})
          return [
            { label: `Open ${r.name}`, icon: 'eye', onClick: () => setParams({ id: r.id }) },
            { label: `Restart ${r.name}`, icon: 'refresh', onClick: one('restart'), disabled: r.state !== 'running' },
            r.state === 'running'
              ? { label: 'Stop', icon: 'stop', onClick: one('stop'), confirm: `${r.name} stops until you start it again.`, menuOnly: true }
              : { label: r.state === 'paused' ? 'Unpause' : 'Start', icon: 'play', onClick: one(r.state === 'paused' ? 'unpause' : 'start'), menuOnly: true },
            { label: 'Logs and details', icon: 'file', onClick: () => setParams({ id: r.id }), menuOnly: true },
            { label: 'Remove…', icon: 'trash', danger: true, confirm: false, onClick: () => setRemoving(r) },
          ]
        }}
        minWidth={980}
        columns={[
          { key: 'name', header: 'Name', value: (r) => r.name, render: (r) => (
            <span className="flex items-center gap-2.5 min-w-0">
              <Dot tone={dotTone(r)} />
              <span className="min-w-0">
                <span className="block font-semibold truncate max-w-[16rem]" title={r.name}>{r.name}</span>
                {(r.application_name || r.compose_project) && <span className="block text-xs text-muted truncate max-w-[16rem]">{r.application_name ? `app ${r.application_name}` : `stack ${r.compose_project}${r.compose_service ? ` · ${r.compose_service}` : ''}`}</span>}
              </span>
            </span>
          ) },
          { key: 'image', header: 'Image', value: (r) => r.image, render: (r) => <span className="block max-w-[18rem] truncate font-mono text-[11.5px] text-muted" title={r.image}>{shortImage(r.image)}</span> },
          { key: 'server', header: 'Server', value: (r) => serverName(r as unknown as R) },
          { key: 'state', header: 'Status', options: ['running', 'exited', 'paused', 'created', 'restarting'], value: (r) => r.state, render: (r) => (
            <span className="inline-flex items-center gap-1.5">
              <StatusBadge status={r.state} />
              {r.health === 'unhealthy' && <StatusBadge status="unhealthy" />}
              {r.health === 'starting' && <StatusBadge status="starting" />}
              {r.health === 'healthy' && <span className="text-xs text-success" title="Health check passing">✓ healthy</span>}
            </span>
          ) },
          { key: 'health', header: 'Health', hidden: true, options: ['healthy', 'unhealthy', 'starting', 'none'], render: (r) => <StatusBadge status={r.health ?? '—'} /> },
          { key: 'cpu_pct', header: 'CPU', kind: 'number', align: 'right', value: (r) => r.cpu_pct ?? 0, render: (r) => r.state === 'running' ? <Meter value={r.cpu_pct ?? 0} max={100} label={pct(r.cpu_pct)} /> : <span className="text-muted">—</span> },
          { key: 'mem_mb', header: 'Memory', kind: 'number', align: 'right', value: (r) => r.mem_mb ?? 0, render: (r) => r.state === 'running' ? (r.limits?.memory_mb ? <Meter value={r.mem_mb ?? 0} max={r.limits.memory_mb} label={mb(r.mem_mb)} /> : <span className="tabular-nums">{mb(r.mem_mb)}</span>) : <span className="text-muted">—</span> },
          { key: 'ports', header: 'Ports', value: (r) => ports(r.ports), render: (r) => {
            const list = portList(r.ports)
            if (!list.length) return <span className="text-muted">—</span>
            return (
              <span className="flex items-center gap-1 whitespace-nowrap" title={ports(r.ports)}>
                <code className="rounded bg-head px-1.5 py-0.5 font-mono text-[11px]">{list[0]}</code>
                {list.length > 1 && <span className="text-xs text-muted">+{list.length - 1}</span>}
              </span>
            )
          } },
        ]} />
      <p className="m-0 text-xs text-muted">Removing a container never deletes attached volumes unless you untick “Keep attached volumes”.</p>
      {sel && <DetailPanel id={sel} onClose={() => setParams({})} />}
      <PruneDialog open={prune} onClose={() => setPrune(false)} />
      {create && <CreateContainer open onClose={() => setCreate(false)} />}
      {removing && <RemoveDialog c={removing} open onClose={() => setRemoving(null)} />}
    </>
  )
}
