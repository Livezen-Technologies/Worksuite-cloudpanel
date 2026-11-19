import { useEffect, useState } from 'react'
import { useAction } from '../api/hooks'
import { Card, Field, Meter, Toggle } from './Card'
import { num, type R } from '../lib/util'

const n = (v: string) => (v === '' ? null : Number(v))

/** Server → Settings: what automatic placement may use on this node, and how full it is by reservation. */
export function ServerCapacityCard({ server }: { server: R }) {
  const { run, busy } = useAction()
  const c = (server.capacity ?? {}) as R
  const [f, setF] = useState<Record<string, string>>({})
  useEffect(() => {
    setF({
      max_containers: server.max_containers == null ? '' : String(server.max_containers),
      scheduling_weight: String(server.scheduling_weight ?? 100),
      reserved_cpu: String(server.reserved_cpu ?? 0), cpu_overcommit: String(server.cpu_overcommit ?? 1),
      reserved_ram_mb: String(server.reserved_ram_mb ?? 0), reserved_disk_gb: String(server.reserved_disk_gb ?? 0),
      max_cpu_pct: server.max_cpu_pct == null ? '' : String(server.max_cpu_pct),
      max_ram_pct: server.max_ram_pct == null ? '' : String(server.max_ram_pct),
      max_disk_pct: server.max_disk_pct == null ? '' : String(server.max_disk_pct),
    })
  }, [server.id]) // eslint-disable-line react-hooks/exhaustive-deps
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value })
  const save = (e: React.FormEvent) => {
    e.preventDefault()
    run({ method: 'PATCH', path: `/servers/${server.id}`, label: 'Save capacity', success: 'Capacity settings saved', body: {
      max_containers: n(f.max_containers), scheduling_weight: Number(f.scheduling_weight) || 0,
      reserved_cpu: Number(f.reserved_cpu) || 0, cpu_overcommit: Number(f.cpu_overcommit) || 1,
      reserved_ram_mb: Number(f.reserved_ram_mb) || 0, reserved_disk_gb: Number(f.reserved_disk_gb) || 0,
      max_cpu_pct: n(f.max_cpu_pct), max_ram_pct: n(f.max_ram_pct), max_disk_pct: n(f.max_disk_pct),
    } }).catch(() => {})
  }
  const ramPct = num(c.allocatable_ram_mb) ? Math.min(100, (num(c.allocatable_ram_mb) - num(c.free_ram_mb)) / num(c.allocatable_ram_mb) * 100) : 0
  const cpuPct = num(c.allocatable_cpu) ? Math.min(100, num(c.reserved_by_apps_cpu) / num(c.allocatable_cpu) * 100) : 0

  return (
    <Card title="Capacity & placement">
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
          <label className="flex items-center gap-3 text-sm"><Toggle checked={server.auto_deploy !== false} label="Eligible for automatic placement"
            onChange={(v) => run({ method: 'PATCH', path: `/servers/${server.id}`, body: { auto_deploy: v }, label: 'Save placement', success: v ? 'Automatic placement on' : 'Automatic placement off' }).catch(() => {})} />
            <span><span className="font-semibold">Automatic placement</span> <span className="text-muted">— new apps may land here</span></span></label>
          <label className="flex items-center gap-3 text-sm"><Toggle checked={!!server.deploy_lock} label="Lock deployments"
            onChange={(v) => run({ method: 'PATCH', path: `/servers/${server.id}`, body: { deploy_lock: v }, label: 'Save lock', success: v ? 'Deployments locked' : 'Deployments unlocked' }).catch(() => {})} />
            <span><span className="font-semibold">Deployment lock</span> <span className="text-muted">— refuse every deploy, even pinned apps</span></span></label>
        </div>
        {c.allocatable_ram_mb != null && <div className="grid gap-3 sm:grid-cols-3 text-[13px]">
          <div><div className="flex justify-between"><span className="text-muted">RAM reserved</span><span className="font-semibold">{Math.round(num(c.allocatable_ram_mb) - num(c.free_ram_mb))} / {c.allocatable_ram_mb} MB</span></div><div className="mt-1.5"><Meter value={ramPct} label="RAM reserved" /></div></div>
          <div><div className="flex justify-between"><span className="text-muted">CPU reserved</span><span className="font-semibold">{c.reserved_by_apps_cpu} / {c.allocatable_cpu} cores</span></div><div className="mt-1.5"><Meter value={cpuPct} label="CPU reserved" /></div></div>
          <div><div className="flex justify-between"><span className="text-muted">Containers</span><span className="font-semibold">{c.containers}{c.max_containers ? ` / ${c.max_containers}` : ''}</span></div>{c.max_containers ? <div className="mt-1.5"><Meter value={Math.min(100, num(c.containers) / num(c.max_containers) * 100)} label="Containers" /></div> : null}
            {c.free_disk_gb != null && <div className="text-muted mt-1">{c.free_disk_gb} GB disk free after reserve</div>}</div>
        </div>}
        <form className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))' }} onSubmit={save}>
          <Field label="Max containers" hint="Empty = no limit"><input className="input" type="number" min={1} value={f.max_containers ?? ''} onChange={set('max_containers')} /></Field>
          <Field label="Priority (weight)" hint="Higher gets new apps first"><input className="input" type="number" min={0} max={1000} value={f.scheduling_weight ?? ''} onChange={set('scheduling_weight')} /></Field>
          <Field label="Reserved CPU (cores)" hint="Kept for the system"><input className="input" type="number" min={0} step="0.5" value={f.reserved_cpu ?? ''} onChange={set('reserved_cpu')} /></Field>
          <Field label="CPU overcommit" hint="1 = none, 2 = double"><input className="input" type="number" min={0.1} max={10} step="0.1" value={f.cpu_overcommit ?? ''} onChange={set('cpu_overcommit')} /></Field>
          <Field label="Reserved RAM (MB)"><input className="input" type="number" min={0} value={f.reserved_ram_mb ?? ''} onChange={set('reserved_ram_mb')} /></Field>
          <Field label="Reserved disk (GB)"><input className="input" type="number" min={0} value={f.reserved_disk_gb ?? ''} onChange={set('reserved_disk_gb')} /></Field>
          <Field label="Stop placing above CPU %" hint="Empty = 90"><input className="input" type="number" min={10} max={100} value={f.max_cpu_pct ?? ''} onChange={set('max_cpu_pct')} /></Field>
          <Field label="Stop placing above RAM %" hint="Empty = 90"><input className="input" type="number" min={10} max={100} value={f.max_ram_pct ?? ''} onChange={set('max_ram_pct')} /></Field>
          <Field label="Stop placing above disk %" hint="Empty = 85"><input className="input" type="number" min={10} max={100} value={f.max_disk_pct ?? ''} onChange={set('max_disk_pct')} /></Field>
          <div className="flex items-end"><button className="btn btn-primary" disabled={busy}>Save capacity</button></div>
        </form>
        <p className="m-0 text-xs text-muted">Placement subtracts the CPU and RAM of every app already on this node, so it fills by reservation, not by how idle the node looks right now. Docker enforces each container’s limits at runtime.</p>
      </div>
    </Card>
  )
}
