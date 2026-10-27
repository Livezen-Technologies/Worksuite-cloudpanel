import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/client'
import { useList } from '../../api/hooks'
import type { Server } from '../../api/types'
import { Card, Field, Meter, PageHeader, Empty } from '../../components/Card'
import { Badge, Dot, toneFor } from '../../components/Badge'
import { Modal } from '../../components/Overlay'
import { StepWizard } from '../../components/StepWizard'
import { CopyButton } from '../../components/CopyButton'
import { unwrap } from '../../lib/format'
import type { R } from '../../lib/util'

const meterColor = (v: number) => (v >= 85 ? '#DC2626' : v >= 70 ? '#D97706' : 'var(--accent)')

export function ServerCard({ s }: { s: Server }) {
  const meters: [string, number | undefined][] = [['CPU', s.cpu_pct], ['RAM', s.ram_pct], ['Disk', s.disk_pct]]
  return (
    <li className="card p-5 flex flex-col gap-3.5">
      <div className="flex items-center gap-2.5">
        <Dot tone={toneFor(s.status)} />
        <Link to={`/admin/servers/${s.id}`} className="font-bold text-[15px] flex-1 truncate text-fg">{s.name}</Link>
        <Badge tone={toneFor(s.status)}>{s.status}</Badge>
      </div>
      <div className="text-xs text-muted"><span className="font-mono">{s.ip}</span>{s.provider ? ` · ${s.provider}` : ''} · {s.region}</div>
      <div className="grid grid-cols-3 gap-3">
        {meters.map(([k, v]) => (
          <div key={k}>
            <div className="flex justify-between text-xs mb-1"><span className="text-muted">{k}</span><span className="font-semibold">{v == null ? '—' : `${Math.round(v)}%`}</span></div>
            <Meter value={v ?? 0} label={`${s.name} ${k}`} color={meterColor(v ?? 0)} />
          </div>
        ))}
      </div>
      <div className="flex flex-wrap gap-1.5 text-xs">
        {[s.environment, s.os, s.docker_version ? `Docker ${s.docker_version}` : null, `${s.container_count ?? 0} containers`].filter(Boolean).map((t) => (
          <span key={t as string} className="px-2 py-0.5 rounded-md bg-track text-muted font-medium">{t}</span>
        ))}
      </div>
    </li>
  )
}

const STEPS = ['Server information', 'Connection method', 'Agent installation', 'Verification', 'Configuration', 'Completion']

export function AddServerWizard({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [step, setStep] = useState(0)
  const [maxReached, setMax] = useState(0)
  const [form, setForm] = useState({ name: '', hostname: '', ip: '', environment: 'production', region: 'eu-central', provider: 'Hetzner', tags: '' })
  const [method, setMethod] = useState<'agent' | 'ssh'>('agent')
  const [server, setServer] = useState<Server | null>(null)
  const [enroll, setEnroll] = useState<{ token: string; expires_at: string; install_command: string } | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [cfg, setCfg] = useState({ tags: '', weight: '100', max_containers: '' })

  useEffect(() => { if (!open) { setStep(0); setMax(0); setServer(null); setEnroll(null); setErr(null) } }, [open])
  useEffect(() => setMax((m) => Math.max(m, step)), [step])

  const heartbeat = useQuery({
    queryKey: ['/servers', server?.id, 'wizard'],
    queryFn: async () => unwrap<Server>(await api.get(`/servers/${server!.id}`)),
    enabled: !!server && step === 2,
    refetchInterval: (q) => (q.state.data && q.state.data.status !== 'pending' ? false : 3000),
  })
  const connected = !!heartbeat.data && heartbeat.data.status !== 'pending'
  const verification = useQuery({
    queryKey: ['/servers', server?.id, 'verification'],
    queryFn: async () => unwrap<{ checks: { name: string; ok: boolean; detail?: string }[] } | { name: string; ok: boolean; detail?: string }[]>(await api.get(`/servers/${server!.id}/verification`)),
    enabled: !!server && step === 3,
    refetchInterval: 5000,
  })
  const checks = Array.isArray(verification.data) ? verification.data : verification.data?.checks ?? []

  const [left, setLeft] = useState('')
  useEffect(() => {
    if (!enroll) return
    const t = setInterval(() => {
      const s = Math.max(0, Math.round((new Date(enroll.expires_at).getTime() - Date.now()) / 1000))
      setLeft(s ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` : 'expired')
    }, 1000)
    return () => clearInterval(t)
  }, [enroll])

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [k]: e.target.value })

  const createAndEnroll = async () => {
    setBusy(true); setErr(null)
    try {
      let s = server
      if (!s) {
        s = unwrap<Server>(await api.post('/servers', { ...form, hostname: form.hostname || form.name, tags: form.tags.split(',').map((x) => x.trim()).filter(Boolean), connection_method: method }))
        setServer(s)
      }
      const e = unwrap<{ token: string; expires_at: string; install_command: string }>(await api.post('/servers/enrollment', { server_id: s.id }))
      setEnroll(e)
      setStep(2)
    } catch (e) {
      const f = (e as { fields?: Record<string, string[]> }).fields ?? {}
      setErrors(Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v[0]])))
      setErr((e as Error).message)
      if (Object.keys(f).length) setStep(0)
    } finally { setBusy(false) }
  }

  const saveConfig = async () => {
    if (!server) return
    setBusy(true)
    try {
      await api.patch(`/servers/${server.id}`, { tags: cfg.tags.split(',').map((x) => x.trim()).filter(Boolean), scheduling_weight: Number(cfg.weight) || 100, max_containers: cfg.max_containers ? Number(cfg.max_containers) : null })
      setStep(5)
    } catch (e) { setErr((e as Error).message) } finally { setBusy(false) }
  }

  const steps = [
    {
      label: STEPS[0],
      validate: () => {
        const e: Record<string, string> = {}
        if (!/^[a-z0-9][a-z0-9.-]{1,62}$/i.test(form.name)) e.name = 'Use 2–63 letters, numbers, dots or hyphens.'
        if (!/^(\d{1,3}\.){3}\d{1,3}$|:/.test(form.ip)) e.ip = 'Enter a valid IPv4 or IPv6 address.'
        setErrors(e)
        return Object.keys(e).length ? 'Fix the highlighted fields.' : null
      },
      content: (
        <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
          <Field label="Server name" error={errors.name}><input className="input" value={form.name} onChange={set('name')} placeholder="vps-eu-03" /></Field>
          <Field label="Hostname" error={errors.hostname} hint="Defaults to the server name"><input className="input" value={form.hostname} onChange={set('hostname')} /></Field>
          <Field label="IP address" error={errors.ip}><input className="input font-mono" value={form.ip} onChange={set('ip')} placeholder="203.0.113.10" /></Field>
          <Field label="Provider"><select className="input" value={form.provider} onChange={set('provider')}>{['Hetzner', 'DigitalOcean', 'Vultr', 'Linode / Akamai', 'AWS EC2', 'Proxmox VE', 'Other'].map((x) => <option key={x}>{x}</option>)}</select></Field>
          <Field label="Environment"><select className="input" value={form.environment} onChange={set('environment')}><option>production</option><option>staging</option><option>development</option></select></Field>
          <Field label="Region"><select className="input" value={form.region} onChange={set('region')}><option>eu-central</option><option>ap-southeast</option><option>us-east</option></select></Field>
          <Field label="Tags" hint="Comma separated"><input className="input" value={form.tags} onChange={set('tags')} /></Field>
        </div>
      ),
    },
    {
      label: STEPS[1],
      validate: () => { if (!enroll) { createAndEnroll(); return 'pending' } return null },
      content: (
        <fieldset className="border-0 p-0 m-0 grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))' }}>
          <legend className="text-sm text-muted mb-3">How should the portal reach this server?</legend>
          {([['agent', 'Agent-based connection (recommended)', 'Outbound HTTPS from the agent; no inbound ports or Docker socket exposed.'], ['ssh', 'SSH-assisted installation', 'We show the same one-line command; run it over your own SSH session.']] as const).map(([v, t, s]) => (
            <label key={v} className="card p-4 flex gap-3 cursor-pointer" style={method === v ? { border: '2px solid var(--accent)', background: 'var(--sel)' } : undefined}>
              <input type="radio" name="method" checked={method === v} onChange={() => setMethod(v)} />
              <span><span className="block font-semibold text-sm">{t}</span><span className="block text-xs text-muted mt-1">{s}</span></span>
            </label>
          ))}
        </fieldset>
      ),
    },
    {
      label: STEPS[2],
      validate: () => (connected ? null : 'Waiting for the agent to connect. Run the command on the server first.'),
      content: enroll ? (
        <div className="flex flex-col gap-3">
          <p className="m-0 text-sm text-muted">Run this on the server. The token is single-use and expires in 15 minutes{left ? ` (${left} left)` : ''}. The agent connects outbound over 443 — no Docker socket is exposed.</p>
          <div className="flex gap-2 items-start">
            <pre className="flex-1 m-0 p-3.5 rounded-lg bg-code text-code-fg font-mono text-xs whitespace-pre-wrap break-all">{enroll.install_command}</pre>
            <CopyButton value={enroll.install_command} label="Copy command" />
          </div>
          <div className="text-xs text-muted">Token <span className="font-mono">{enroll.token.slice(0, 8)}…</span></div>
          <div className="flex items-center gap-2 text-sm font-semibold" aria-live="polite">
            {connected ? <><Badge tone="g">connected</Badge> Agent heartbeat received</> : <><span aria-hidden="true" className="w-2 h-2 rounded-full bg-[#D97706] animate-pulse" />Waiting for agent heartbeat…</>}
          </div>
          {left === 'expired' && !connected && <button type="button" className="btn self-start" onClick={createAndEnroll}>Generate a new token</button>}
        </div>
      ) : <div className="text-sm text-muted">Generating enrolment token…</div>,
    },
    {
      label: STEPS[3],
      validate: () => (checks.length && checks.every((c) => c.ok) ? null : checks.length ? 'Some checks failed. Fix them on the server, then continue.' : 'Running checks…'),
      content: (
        <div>
          <p className="mt-0 text-sm text-muted">Checks Docker version, OS, kernel, CPU, memory, disk, networking and agent health.</p>
          <ul className="list-none m-0 p-0 flex flex-col">
            {checks.length === 0 && <li className="text-sm text-muted">Running checks…</li>}
            {checks.map((c) => (
              <li key={c.name} className="flex gap-3 items-center py-2.5 border-t border-line text-sm">
                <Badge tone={c.ok ? 'g' : 'r'}>{c.ok ? 'passed' : 'failed'}</Badge>
                <span className="font-semibold">{c.name}</span>
                <span className="text-muted text-xs ml-auto text-right">{c.detail}</span>
              </li>
            ))}
          </ul>
        </div>
      ),
    },
    {
      label: STEPS[4],
      validate: () => { saveConfig(); return 'pending' },
      content: (
        <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
          <Field label="Server group tags" hint="Comma separated, used for placement"><input className="input" value={cfg.tags} onChange={(e) => setCfg({ ...cfg, tags: e.target.value })} /></Field>
          <Field label="Scheduling weight" hint="Higher gets more new workloads"><input className="input" type="number" min={0} value={cfg.weight} onChange={(e) => setCfg({ ...cfg, weight: e.target.value })} /></Field>
          <Field label="Max containers (capacity)"><input className="input" type="number" min={0} value={cfg.max_containers} onChange={(e) => setCfg({ ...cfg, max_containers: e.target.value })} /></Field>
        </div>
      ),
    },
    {
      label: STEPS[5],
      content: (
        <div className="flex flex-col gap-2 text-sm">
          <div className="font-semibold">Server registered — metrics initialised and dashboard ready.</div>
          {server && <Link to={`/admin/servers/${server.id}`} className="font-semibold" onClick={onClose}>Open {server.name} →</Link>}
        </div>
      ),
    },
  ]
  return (
    <Modal open={open} onClose={onClose} title="Add server" width={860}>
      <StepWizard steps={steps} step={step} maxReached={maxReached} busy={busy} error={err}
        onStep={(n) => { setErr(null); if (n > 1 && !server) return; setStep(n) }}
        onFinish={onClose} finishLabel="Done" />
    </Modal>
  )
}

export default function Servers() {
  const [params, setParams] = useSearchParams()
  const { rows, isLoading } = useList<Server>('/servers', undefined, { refetchInterval: 15000 })
  const [group, setGroup] = useState<'none' | 'environment' | 'region'>('none')
  const open = params.get('add') === '1'
  const groups = group === 'none' ? [['', rows] as const] : Object.entries(rows.reduce<Record<string, Server[]>>((a, s) => { const k = String((s as unknown as R)[group] ?? '—'); (a[k] ??= []).push(s); return a }, {}))
  return (
    <>
      <PageHeader title="Servers" subtitle="VPS and dedicated servers connected through the outbound agent"
        actions={<>
          <label className="flex items-center gap-2 text-sm text-muted">Group by
            <select className="input !w-auto" value={group} onChange={(e) => setGroup(e.target.value as typeof group)}><option value="none">None</option><option value="environment">Environment</option><option value="region">Region</option></select>
          </label>
          <button type="button" className="btn btn-primary" onClick={() => setParams({ add: '1' })}>Add server</button>
        </>} />
      {isLoading && <Card>Loading servers…</Card>}
      {!isLoading && rows.length === 0 && <Card><Empty>No servers yet. Add your first server to start managing containers.</Empty></Card>}
      {groups.map(([g, list]) => (
        <div key={g} className="flex flex-col gap-3">
          {g && <h2 className="m-0 text-sm font-semibold text-muted">{g}</h2>}
          <ul className="list-none m-0 p-0 grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))' }}>
            {list.map((s) => <ServerCard key={s.id} s={s} />)}
          </ul>
        </div>
      ))}
      <AddServerWizard open={open} onClose={() => setParams({})} />
    </>
  )
}
