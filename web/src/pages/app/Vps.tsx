import { useState } from 'react'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge, type Tone } from '../../components/Badge'
import { CopyButton } from '../../components/CopyButton'
import type { R } from '../../lib/util'

const TONE: Record<string, Tone> = { running: 'g', provisioning: 'b', restarting: 'b', stopped: 'n', failed: 'r' }
export const money = (c: number) => `$${(c / 100).toFixed(c % 100 ? 2 : 0)}/mo`
const gb = (mb: number) => `${mb / 1024 >= 1 ? mb / 1024 : (mb / 1024).toFixed(1)} GB`

/** UVPS: the customer's virtual servers with root access. */
export default function UserVps() {
  const page = useOne<R>('/app/vps', undefined, { refetchInterval: 10000 })
  const { run, busy } = useAction()
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ name: '', plan: '', os: '', region: '', key: '' })
  const w = page.item ?? {}
  const servers = (w.servers as R[]) ?? []
  const plans = (w.plans as R[]) ?? []
  const images = (w.images as R[]) ?? []
  const regions = (w.regions as string[]) ?? []
  const limit = Number(w.limit ?? 0)
  const plan = f.plan || String(plans.find((p) => p.available)?.id ?? '')
  const os = f.os || String(images[0]?.id ?? '')
  const refetch = () => page.refetch()
  const act = (v: R, a: string) => run({ path: `/app/vps/${v.id}/${a}`, label: `${a[0].toUpperCase()}${a.slice(1)} ${v.name}` }).then(refetch).catch(() => {})
  const nameOk = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/.test(f.name) && f.name.length >= 2
  const keyOk = /^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com) [A-Za-z0-9+/=]{40,}/.test(f.key.trim())

  const create = () => run({ path: '/app/vps', body: { name: f.name, plan, os, region: f.region || null, ssh_keys: [f.key.trim()] }, label: `Create ${f.name}` })
    .then(() => { setOpen(false); setF({ name: '', plan: '', os: '', region: '', key: '' }); refetch() }).catch(() => {})

  return (
    <>
      <PageHeader title="My servers" subtitle={`Virtual servers with full root access · ${servers.length} of ${limit} on your plan`}
        actions={<button type="button" className="btn btn-primary" disabled={servers.length >= limit} onClick={() => setOpen(!open)}>{open ? 'Cancel' : 'Create server'}</button>} />

      {open && <Card title="New server">
        <div className="flex flex-col gap-5">
          <fieldset className="m-0 p-0 border-0"><legend className="text-sm font-semibold mb-2">Plan</legend>
            <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(180px,1fr))]">
              {plans.map((p) => {
                const on = plan === p.id
                return (
                  <button key={String(p.id)} type="button" aria-pressed={on} disabled={!p.available} onClick={() => setF({ ...f, plan: String(p.id) })}
                    className="card p-3 text-left cursor-pointer text-fg disabled:opacity-60 disabled:cursor-not-allowed flex flex-col gap-1"
                    style={on ? { borderColor: 'var(--accent)', boxShadow: '0 0 0 1px var(--accent)' } : undefined}>
                    <span className="font-semibold text-sm">{String(p.label)}</span>
                    <span className="text-xs text-muted">{Number(p.cpus)} vCPU · {gb(Number(p.memory_mb))}</span>
                    <span className="text-xs">{p.available ? money(Number(p.price_cents)) : 'Coming soon'}</span>
                    <span className="text-xs text-muted">{p.type === 'kvm' ? 'Virtual machine, own kernel' : 'Container VPS, Linux'}</span>
                  </button>
                )
              })}
            </div>
          </fieldset>
          <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
            <label className="flex flex-col gap-1 text-sm">Server name<input className="input font-mono" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="web-1" />
              {f.name && !nameOk && <span className="text-xs text-danger">Use lowercase letters, numbers and hyphens.</span>}</label>
            <label className="flex flex-col gap-1 text-sm">Operating system<select className="input" value={os} onChange={(e) => setF({ ...f, os: e.target.value })}>
              {images.map((i) => <option key={String(i.id)} value={String(i.id)}>{String(i.label)}</option>)}</select></label>
            <label className="flex flex-col gap-1 text-sm">Region<select className="input" value={f.region} onChange={(e) => setF({ ...f, region: e.target.value })}>
              <option value="">Any region</option>{regions.map((r) => <option key={r} value={r}>{r}</option>)}</select></label>
          </div>
          <label className="flex flex-col gap-1 text-sm">SSH public key
            <textarea className="input font-mono text-xs min-h-[72px]" value={f.key} onChange={(e) => setF({ ...f, key: e.target.value })} placeholder="ssh-ed25519 AAAA… you@laptop" />
            <span className="text-xs text-muted">The contents of ~/.ssh/id_ed25519.pub. You sign in as root with this key; there is no password.</span>
            {f.key && !keyOk && <span className="text-xs text-danger">That doesn't look like a public key.</span>}
          </label>
          <div className="flex justify-end"><button type="button" className="btn btn-primary" disabled={busy || !nameOk || !keyOk || !plan} onClick={create}>Create server</button></div>
        </div>
      </Card>}

      {page.item && servers.length === 0 && !open && <Card><p className="m-0 text-sm text-muted">No servers yet. Create one to get a Linux machine with root access.</p></Card>}
      {servers.map((v) => {
        const p = (v.plan ?? {}) as R
        const status = String(v.status)
        const ready = status === 'running' || status === 'stopped' || status === 'restarting'
        return (
          <Card key={String(v.id)}>
            <div className="flex flex-wrap items-start gap-4">
              <div className="flex-1 min-w-[220px]">
                <div className="flex items-center gap-2"><span className="font-semibold">{String(v.name)}</span><Badge tone={TONE[status] ?? 'n'}>{status === 'provisioning' ? 'Provisioning' : status[0].toUpperCase() + status.slice(1)}</Badge></div>
                <div className="text-xs text-muted mt-1">{String((v.os as R)?.label ?? '')} · {String(p.label ?? '')} · {Number(p.cpus)} vCPU · {gb(Number(p.memory_mb ?? 0))} · {String(v.region ?? '—')}</div>
                {v.error ? <div role="alert" className="text-xs text-danger mt-1">{String(v.error)}</div> : null}
                {v.ssh ? <div className="flex flex-wrap items-center gap-2 mt-2"><code className="text-xs break-all">{String(v.ssh)}</code><CopyButton value={String(v.ssh)} label="Copy" /></div> : null}
              </div>
              <div className="flex flex-wrap gap-2">
                {status === 'stopped'
                  ? <button type="button" className="btn btn-sm" disabled={busy} onClick={() => act(v, 'start')}>Start</button>
                  : <button type="button" className="btn btn-sm" disabled={busy || !ready} onClick={() => act(v, 'stop')}>Stop</button>}
                <button type="button" className="btn btn-sm" disabled={busy || status !== 'running'} onClick={() => act(v, 'restart')}>Restart</button>
                <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => {
                  const typed = window.prompt(`This deletes ${v.name} and everything on its disk. Type the server name to confirm.`)
                  if (typed !== null) run({ path: `/app/vps/${v.id}?confirm=${encodeURIComponent(typed)}`, method: 'DELETE', label: `Delete ${v.name}`, success: 'Server deleted' }).then(refetch).catch(() => {})
                }}>Delete</button>
              </div>
            </div>
          </Card>
        )
      })}
    </>
  )
}
