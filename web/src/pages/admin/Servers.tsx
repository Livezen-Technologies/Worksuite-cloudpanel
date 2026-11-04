import { useAction } from '../../api/hooks'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/client'
import { useList } from '../../api/hooks'
import type { Server } from '../../api/types'
import { Card, Field, Meter, PageHeader, Empty, Loading } from '../../components/Card'
import { Badge, Dot, toneFor } from '../../components/Badge'
import { WizardChecks, WizardChoice, WizardSection, WizardShell } from '../../components/WizardShell'
import { Icon } from '../../components/Icon'
import { CopyButton } from '../../components/CopyButton'
import { unwrap } from '../../lib/format'
import type { R } from '../../lib/util'
import Select from '../../components/Select'

const meterColor = (v: number) => (v >= 85 ? 'var(--r-fg)' : v >= 70 ? 'var(--y-fg)' : 'var(--accent)')

export function ServerCard({ s }: { s: Server }) {
  const meters: [string, number | undefined][] = [['CPU', s.cpu_pct], ['RAM', s.ram_pct], ['Disk', s.disk_pct]]
  return (
    <li className="card p-5 flex flex-col gap-3.5">
      <div className="flex items-center gap-2.5">
        <Dot tone={toneFor(s.status)} />
        <Link to={`/admin/servers/${s.id}`} className="flex-1 truncate py-0.5 text-[15px] font-bold text-fg">{s.name}</Link>
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
        {s.agent_outdated && <span className="px-2 py-0.5 rounded-md bg-y-bg text-y-fg font-semibold" title={`Running ${s.agent_version}; ${s.agent_latest} is available`}>Agent {s.agent_version} → {s.agent_latest}</span>}
      </div>
    </li>
  )
}

const STEPS = [
  { id: 'details', label: 'Server details', hint: 'Name, address and where it runs' },
  { id: 'connect', label: 'Connection method', hint: 'How the panel reaches the server' },
  { id: 'install', label: 'Install the agent', hint: 'One command on the server' },
  { id: 'verify', label: 'Verification', hint: 'Docker, OS, resources, network' },
  { id: 'configure', label: 'Configuration', hint: 'Placement tags and capacity' },
  { id: 'done', label: 'Ready', hint: 'Open the server' },
] as const
const PROVIDERS: [string, string][] = [['Hetzner', 'server'], ['DigitalOcean', 'globe'], ['Vultr', 'server'], ['Linode / Akamai', 'globe'], ['AWS EC2', 'layers'], ['Proxmox VE', 'box'], ['Other', 'server']]
const REGIONS = ['eu-central', 'ap-southeast', 'us-east']

export function AddServerWizard({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [step, setStep] = useState(0)
  const [reach, setReach] = useState(0)
  const [form, setForm] = useState({ name: '', hostname: '', ip: '', environment: 'production', region: 'eu-central', provider: 'Hetzner', tags: '' })
  const [method, setMethod] = useState<'agent' | 'ssh'>('agent')
  const [server, setServer] = useState<Server | null>(null)
  const [enroll, setEnroll] = useState<{ token: string; expires_at: string; install_command: string } | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [cfg, setCfg] = useState({ tags: '', weight: '100', max_containers: '' })

  useEffect(() => { if (!open) { setStep(0); setReach(0); setServer(null); setEnroll(null); setErr(null); setErrors({}) } }, [open])
  const goTo = (n: number) => { setErr(null); setStep(n); setReach((r) => Math.max(r, n)) }

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
  const locked = !!server   // once created, details are edited on the server page

  const validateDetails = () => {
    const e: Record<string, string> = {}
    if (!/^[a-z0-9][a-z0-9.-]{1,62}$/i.test(form.name)) e.name = 'Use 2–63 letters, numbers, dots or hyphens.'
    if (!/^(\d{1,3}\.){3}\d{1,3}$|:/.test(form.ip)) e.ip = 'Enter a valid IPv4 or IPv6 address.'
    setErrors(e)
    return Object.keys(e).length ? 'Fix the highlighted fields.' : null
  }

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
      goTo(2)
    } catch (e) {
      const f = (e as { fields?: Record<string, string[]> }).fields ?? {}
      setErrors(Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v[0]])))
      if (Object.keys(f).length) setStep(0)
      setErr((e as Error).message)
    } finally { setBusy(false) }
  }

  const saveConfig = async () => {
    if (!server) return
    setBusy(true)
    try {
      await api.patch(`/servers/${server.id}`, { tags: cfg.tags.split(',').map((x) => x.trim()).filter(Boolean), scheduling_weight: Number(cfg.weight) || 100, max_containers: cfg.max_containers ? Number(cfg.max_containers) : null })
      goTo(5)
    } catch (e) { setErr((e as Error).message) } finally { setBusy(false) }
  }

  const verified = checks.length > 0 && checks.every((c) => c.ok)
  const next = [
    { label: 'Next: Connection method', onClick: () => { const e = validateDetails(); if (e) setErr(e); else goTo(1) } },
    { label: busy ? 'Creating…' : enroll ? 'Next: Install the agent' : 'Create server & get command', onClick: () => (enroll ? goTo(2) : createAndEnroll()) },
    { label: connected ? 'Next: Verification' : 'Waiting for the agent…', onClick: () => goTo(3), disabled: !connected },
    { label: verified ? 'Next: Configuration' : checks.length ? 'Fix the failed checks' : 'Running checks…', onClick: () => goTo(4), disabled: !verified },
    { label: busy ? 'Saving…' : 'Save and finish', onClick: saveConfig },
    null,
  ][step]

  const summary: [string, string][] = [
    ['Name', form.name || '—'], ['IP address', form.ip || '—'], ['Provider', form.provider], ['Environment', form.environment], ['Region', form.region],
    ['Connection', method === 'agent' ? 'Agent (outbound HTTPS)' : 'SSH-assisted'],
    ['Status', !server ? 'Not created yet' : connected ? (verified ? 'Verified' : 'Connected') : 'Waiting for agent'],
  ]

  const content = [
    <div key="details" className="flex flex-col gap-6">
      {locked && <p className="m-0 text-sm text-muted">The server is created; change these details later on its page.</p>}
      <WizardSection title="Identity" hint="How the server appears in the panel.">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Server name" error={errors.name}><input className="input" disabled={locked} value={form.name} onChange={set('name')} placeholder="vps-eu-03" /></Field>
          <Field label="Hostname" error={errors.hostname} hint="Defaults to the server name"><input className="input" disabled={locked} value={form.hostname} onChange={set('hostname')} /></Field>
          <Field label="IP address" error={errors.ip}><input className="input font-mono" disabled={locked} value={form.ip} onChange={set('ip')} placeholder="203.0.113.10" /></Field>
        </div>
      </WizardSection>
      <WizardSection title="Provider" hint="Where the machine runs.">
        <div className="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4">
          {PROVIDERS.map(([p, icon]) => <WizardChoice key={p} disabled={locked} on={form.provider === p} onClick={() => setForm({ ...form, provider: p })} title={p} text={p === 'Other' ? 'Any Linux VPS or bare metal' : 'Cloud or VPS'} icon={icon} />)}
        </div>
      </WizardSection>
      <div className="grid gap-4 sm:grid-cols-3">
        <Field label="Environment"><Select className="input" disabled={locked} value={form.environment} onChange={set('environment')}><option>production</option><option>staging</option><option>development</option></Select></Field>
        <Field label="Region"><Select className="input" disabled={locked} value={form.region} onChange={set('region')}>{REGIONS.map((r) => <option key={r}>{r}</option>)}</Select></Field>
        <Field label="Tags" hint="Comma separated"><input className="input" disabled={locked} value={form.tags} onChange={set('tags')} /></Field>
      </div>
    </div>,
    <WizardSection key="connect" title="How should the panel reach this server?" hint="Both install the same agent; nothing listens on inbound ports.">
      <div className="grid gap-3 sm:grid-cols-2">
        <WizardChoice disabled={locked} on={method === 'agent'} onClick={() => setMethod('agent')} icon="shield" title="Agent-based (recommended)" text="Outbound HTTPS from the agent; no inbound ports or Docker socket exposed." />
        <WizardChoice disabled={locked} on={method === 'ssh'} onClick={() => setMethod('ssh')} icon="list" title="SSH-assisted installation" text="Run the same one-line command over your own SSH session." />
      </div>
      {!enroll && <p className="m-0 text-xs text-muted">Next creates the server and a single-use install token valid for 15 minutes.</p>}
    </WizardSection>,
    enroll ? (
      <div key="install" className="flex flex-col gap-4">
        <WizardSection title="Run this on the server" hint={`The token is single-use and expires in 15 minutes${left ? ` (${left} left)` : ''}. The agent connects outbound over 443; no Docker socket is exposed.`}>
          <div className="flex gap-2 items-start">
            <pre className="flex-1 m-0 p-3.5 rounded-lg bg-code text-code-fg font-mono text-xs whitespace-pre-wrap break-all">{enroll.install_command}</pre>
            <CopyButton value={enroll.install_command} label="Copy command" />
          </div>
          <div className="text-xs text-muted">Token <span className="font-mono">{enroll.token.slice(0, 8)}…</span></div>
        </WizardSection>
        <div className="flex items-center gap-2 rounded-lg border border-line p-3 text-sm font-semibold" aria-live="polite">
          {connected ? <><Badge tone="g">connected</Badge> Agent heartbeat received</> : <><span aria-hidden="true" className="w-2 h-2 rounded-full bg-[var(--y-fg)] animate-pulse" />Waiting for agent heartbeat…</>}
        </div>
        {left === 'expired' && !connected && <button type="button" className="btn self-start" onClick={createAndEnroll}>Generate a new token</button>}
      </div>
    ) : <div key="install" className="text-sm text-muted">Generating enrolment token…</div>,
    <WizardSection key="verify" title="Server checks" hint="Docker version, OS, kernel, CPU, memory, disk, networking and agent health. Re-checked every 5 seconds.">
      {checks.length === 0 ? <p className="m-0 text-sm text-muted">Running checks…</p>
        : <WizardChecks label="Verification" checks={checks.map((c) => ({ key: c.name, status: c.ok ? 'pass' : 'fail', label: c.name, message: c.detail }))} />}
    </WizardSection>,
    <WizardSection key="configure" title="Placement and capacity" hint="Automatic placement uses these when choosing a server for new apps.">
      <div className="grid gap-4 sm:grid-cols-3">
        <Field label="Server group tags" hint="Comma separated"><input className="input" value={cfg.tags} onChange={(e) => setCfg({ ...cfg, tags: e.target.value })} /></Field>
        <Field label="Scheduling weight" hint="Higher gets more new workloads"><input className="input" type="number" min={0} value={cfg.weight} onChange={(e) => setCfg({ ...cfg, weight: e.target.value })} /></Field>
        <Field label="Max containers" hint="Leave empty for no limit"><input className="input" type="number" min={0} value={cfg.max_containers} onChange={(e) => setCfg({ ...cfg, max_containers: e.target.value })} /></Field>
      </div>
    </WizardSection>,
    <div key="done" className="flex flex-col gap-4 items-start">
      <div className="flex items-center gap-3"><span aria-hidden="true" className="grid place-items-center w-12 h-12 rounded-xl bg-g-bg text-g-fg"><Icon name="check" size={24} /></span>
        <div><div className="text-lg font-semibold">{server?.name ?? form.name} is ready</div><div className="text-sm text-muted">Metrics are being collected and new apps can be placed on it.</div></div></div>
      {server && <Link to={`/admin/servers/${server.id}`} className="btn btn-primary no-underline" onClick={onClose}>Open {server.name}</Link>}
    </div>,
  ]

  return (
    <WizardShell open={open} onClose={onClose} title="Add server" subtitle="Connect a Linux server with the agent; no inbound ports needed."
      steps={STEPS} step={step} reach={reach} busy={busy} error={err} summary={summary} cancelLabel={step === 5 ? 'Close' : 'Cancel'}
      onStep={(n) => { if (n >= 2 && !server) return; goTo(n) }} back={step < 5} next={next}>
      {content[step]}
    </WizardShell>
  )
}

export default function Servers() {
  const [params, setParams] = useSearchParams()
  const { rows, isLoading } = useList<Server>('/servers', undefined, { refetchInterval: 15000 })
  const [group, setGroup] = useState<'none' | 'environment' | 'region'>('none')
  const open = params.get('add') === '1'
  const groups = group === 'none' ? [['', rows] as const] : Object.entries(rows.reduce<Record<string, Server[]>>((a, s) => { const k = String((s as unknown as R)[group] ?? '—'); (a[k] ??= []).push(s); return a }, {}))
  const { run: updRun, busy: updBusy } = useAction()
  return (
    <>
      <PageHeader title="Servers" subtitle="VPS and dedicated servers connected through the outbound agent"
        actions={<>
          <label className="flex items-center gap-2 text-sm text-muted">Group by
            <Select className="input !w-auto" value={group} onChange={(e) => setGroup(e.target.value as typeof group)}><option value="none">None</option><option value="environment">Environment</option><option value="region">Region</option></Select>
          </label>
          <button type="button" className="btn btn-primary" onClick={() => setParams({ add: '1' })}>Add server</button>
        </>} />
      {rows.some((s) => s.agent_outdated) && (
        <div role="status" className="flex flex-wrap items-center gap-3 px-4 py-3 rounded-xl border border-line bg-y-bg text-y-fg text-sm">
          <span className="flex-1 min-w-[220px]"><b>{rows.filter((s) => s.agent_outdated).length} server{rows.filter((s) => s.agent_outdated).length === 1 ? ' runs' : 's run'} an older agent.</b> Version {rows.find((s) => s.agent_outdated)?.agent_latest} is available; updating restarts the agent only, not your apps.</span>
          <button type="button" className="btn btn-sm" disabled={updBusy} onClick={() => updRun({ path: '/servers/agents/update', label: 'Update agents', success: 'Agent updates sent' }).catch(() => {})}>Update all</button>
        </div>
      )}
      {isLoading && <Loading label="Loading servers…" />}
      {!isLoading && rows.length === 0 && <Card><Empty icon="server" action={<button type="button" className="btn btn-primary" onClick={() => setParams({ add: '1' })}>Add server</button>}>No servers yet. Add your first server to start managing containers.</Empty></Card>}
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
