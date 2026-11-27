import { useEffect, useMemo, useState } from 'react'
import { useAction, useList } from '../api/hooks'
import type { Server } from '../api/types'
import { num, type R } from '../lib/util'
import { Field } from './Card'
import Select from './Select'
import { WizardChecks, WizardChoice, WizardSection, WizardShell, type ShellStep } from './WizardShell'

/** The engines the API accepts (DatabaseController::store), with the versions offered as quick picks. */
export const DB_ENGINES = [
  { value: 'postgres', label: 'PostgreSQL', short: 'PG', text: 'Relational, JSON, extensions. The default for most apps.', versions: ['17', '16', '15'] },
  { value: 'mysql', label: 'MySQL', short: 'MY', text: 'Relational. For apps written for MySQL.', versions: ['8.4', '8.0'] },
  { value: 'mariadb', label: 'MariaDB', short: 'MD', text: 'MySQL-compatible. What WorkSuite and WordPress sites use.', versions: ['11', '10.11'] },
  { value: 'redis', label: 'Redis', short: 'RD', text: 'In-memory cache, queues and sessions.', versions: ['7-alpine', '7'] },
  { value: 'mongodb', label: 'MongoDB', short: 'MG', text: 'Document database.', versions: ['8', '7'] },
] as const

const STEPS: readonly ShellStep[] = [
  { id: 'engine', label: 'Engine', hint: 'What kind of database' },
  { id: 'settings', label: 'Settings', hint: 'Name, version and size' },
  { id: 'placement', label: 'Placement', hint: 'Server and network' },
  { id: 'review', label: 'Review', hint: 'Check and create' },
]

const NAME = /^[a-z0-9][a-z0-9_-]*$/

export function NewDatabaseWizard({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [step, setStep] = useState(0)
  const [reach, setReach] = useState(0)
  const [f, setF] = useState({ engine: 'postgres', name: '', version: '17', environment: 'production', memory_mb: '', project_id: '', server_id: '', network: '' })
  const [err, setErr] = useState<string | null>(null)
  const { run, busy } = useAction()

  useEffect(() => {
    if (!open) { setStep(0); setReach(0); setErr(null); setF({ engine: 'postgres', name: '', version: '17', environment: 'production', memory_mb: '', project_id: '', server_id: '', network: '' }) }
  }, [open])

  const servers = useList<Server>(open ? '/servers' : null, { per_page: 200 })
  const projects = useList<R>(open ? '/projects' : null, { per_page: 200 })
  const networks = useList<R>(open && f.server_id ? '/networks' : null, { server_id: f.server_id, per_page: 200 })
  const usable = networks.rows.filter((n) => n.driver !== 'host')

  // Default to the server's `internal` network, which databases join; when the server has none, say so.
  useEffect(() => {
    if (!f.server_id || networks.isLoading) return
    if (f.network && usable.some((n) => n.name === f.network)) return
    const pick = usable.find((n) => n.name === 'internal') ?? usable[0]
    setF((v) => ({ ...v, network: pick ? String(pick.name) : '' }))
  }, [f.server_id, networks.isLoading, usable.length]) // eslint-disable-line react-hooks/exhaustive-deps

  const engine = DB_ENGINES.find((e) => e.value === f.engine)!
  const server = servers.rows.find((s) => s.id === f.server_id)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  // Database servers first, then the rest; offline servers last.
  const ordered = useMemo(() => [...servers.rows].sort((a, b) =>
    Number(b.category === 'database') - Number(a.category === 'database') || Number(a.status !== 'online') - Number(b.status !== 'online') || a.name.localeCompare(b.name)), [servers.rows])

  const nameError = !f.name ? 'Give the database a name.' : !NAME.test(f.name) ? 'Lower-case letters, digits, - and _ only, starting with a letter or digit.' : f.name.length > 63 ? 'At most 63 characters.' : null
  const memError = f.memory_mb && num(f.memory_mb) < 64 ? 'At least 64 MB.' : null

  const checks = [
    { key: 'name', status: nameError ? 'fail' as const : 'pass' as const, label: 'Name', message: nameError ?? f.name },
    { key: 'server', status: !server ? 'fail' as const : server.status === 'online' ? 'pass' as const : 'warn' as const, label: 'Server', message: !server ? 'Choose a server.' : server.status === 'online' ? `${server.name} is online` : `${server.name} is ${server.status}; the database starts when it reconnects.` },
    { key: 'network', status: !f.network ? 'fail' as const : f.network === 'internal' ? 'pass' as const : 'warn' as const, label: 'Network',
      message: !f.network ? 'This server has no network a database can join. Create one under Networks first.' : f.network === 'internal' ? 'Joins the private internal network' : `Joins ${f.network}; apps must be on the same network to reach it.` },
    { key: 'disk', status: server && num(server.disk_pct) >= 85 ? 'warn' as const : 'pass' as const, label: 'Disk', message: server ? `${Math.round(num(server.disk_pct))}% used on ${server.name}` : '—' },
  ]

  const validate = (i: number): string | null => {
    if (i === 1) return nameError ?? memError
    if (i === 2) return !f.server_id ? 'Choose a server.' : !f.network ? 'This server has no network a database can join.' : null
    return null
  }
  const go = (i: number) => {
    if (i > step) { const e = validate(step); if (e) { setErr(e); return } }
    setErr(null); setStep(i); setReach((r) => Math.max(r, i))
  }
  const create = () => {
    if (checks.some((c) => c.status === 'fail')) { setErr('Fix the failed checks first.'); return }
    const body: R = { engine: f.engine, name: f.name, version: f.version || null, server_id: f.server_id, environment: f.environment, network: f.network }
    if (f.memory_mb) body.memory_mb = num(f.memory_mb)
    if (f.project_id) body.project_id = f.project_id
    run({ path: '/databases', body, label: `Create ${f.name}`, success: `${f.name} is being created` }).then(onClose).catch((e) => setErr(e instanceof Error ? e.message : 'Could not create the database'))
  }

  const summary: [string, string][] = [
    ['Engine', `${engine.label}${f.version ? ' ' + f.version : ''}`], ['Name', f.name || '—'], ['Environment', f.environment],
    ['Memory', f.memory_mb ? `${f.memory_mb} MB` : 'No limit'], ['Project', String(projects.rows.find((p) => p.id === f.project_id)?.name ?? '—')],
    ['Server', server?.name ?? '—'], ['Network', f.network || '—'],
  ]

  return (
    <WizardShell open={open} onClose={onClose} title="New database" subtitle="A managed container with its own encrypted credentials and backups"
      steps={STEPS} step={step} reach={reach} onStep={go} summary={summary} error={err} busy={busy}
      next={step < STEPS.length - 1 ? { label: 'Continue', onClick: () => go(step + 1) } : { label: busy ? 'Creating…' : 'Create database', onClick: create }}>
      {step === 0 && (
        <WizardSection title="Choose an engine" hint="You can run several engines side by side on one server.">
          <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(200px,1fr))]">
            {DB_ENGINES.map((e) => (
              <WizardChoice key={e.value} on={f.engine === e.value} title={e.label} text={e.text} icon="db"
                onClick={() => setF({ ...f, engine: e.value, version: e.versions[0] })} />
            ))}
          </div>
        </WizardSection>
      )}
      {step === 1 && (
        <div className="flex flex-col gap-6">
          <WizardSection title="Name" hint="Also the container name (db-<name>) and the default database and user.">
            <Field label="Database name"><input className="input font-mono" aria-label="Database name" autoFocus value={f.name} placeholder="shop-db"
              onChange={(e) => setF({ ...f, name: e.target.value.toLowerCase().replace(/\s+/g, '-') })} aria-invalid={!!(f.name && nameError)} /></Field>
            {f.name && nameError && <p className="m-0 text-xs text-[var(--r-fg)]">{nameError}</p>}
          </WizardSection>
          <WizardSection title="Version" hint="Pick a recent version, or type any image tag.">
            <div className="flex flex-wrap gap-2" role="group" aria-label="Version">
              {engine.versions.map((v) => (
                <button key={v} type="button" aria-pressed={f.version === v} onClick={() => setF({ ...f, version: v })}
                  className={`btn btn-sm ${f.version === v ? '!border-[var(--accent)] !bg-sel text-accent' : ''}`}>{v}</button>
              ))}
              <input className="input !w-40 h-8 text-sm font-mono" aria-label="Other version" placeholder="other tag" value={(engine.versions as readonly string[]).includes(f.version) ? '' : f.version} onChange={set('version')} />
            </div>
          </WizardSection>
          <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
            <Field label="Environment"><Select className="input" aria-label="Environment" value={f.environment} onChange={set('environment')}><option value="production">Production</option><option value="staging">Staging</option><option value="development">Development</option></Select></Field>
            <Field label="Memory limit (MB)" hint="Empty for no limit"><input className="input" type="number" min={64} inputMode="numeric" aria-label="Memory limit" value={f.memory_mb} onChange={set('memory_mb')} placeholder="1024" /></Field>
            <Field label="Project" hint="Optional: groups it with an app"><Select className="input" aria-label="Project" value={f.project_id} onChange={set('project_id')}><option value="">No project</option>{projects.rows.map((p) => <option key={String(p.id)} value={String(p.id)}>{String(p.name)}</option>)}</Select></Field>
          </div>
          {f.environment === 'production' && <p className="m-0 text-xs text-muted">Production databases need an approval to delete.</p>}
        </div>
      )}
      {step === 2 && (
        <div className="flex flex-col gap-6">
          <WizardSection title="Server" hint="Servers marked Database come first.">
            <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">
              {ordered.map((s) => (
                <WizardChoice key={s.id} on={f.server_id === s.id} icon="server" title={s.name} disabled={s.status === 'offline'}
                  text={`${s.status} · RAM ${Math.round(num(s.ram_pct))}% · Disk ${Math.round(num(s.disk_pct))}%${s.category === 'database' ? ' · Database' : ''}`}
                  onClick={() => setF({ ...f, server_id: s.id, network: '' })} />
              ))}
            </div>
          </WizardSection>
          {f.server_id && (
            <WizardSection title="Network" hint="Apps reach the database by name over this network.">
              {networks.isLoading ? <p className="m-0 text-sm text-muted">Loading networks…</p> : usable.length === 0
                ? <p role="alert" className="m-0 text-sm text-[var(--r-fg)]">{server?.name} has no network a database can join. Create an <span className="font-mono">internal</span> network for it under Networks, then come back.</p>
                : <Field label="Network"><Select className="input !w-auto" aria-label="Network" value={f.network} onChange={set('network')}>{usable.map((n) => <option key={String(n.id)} value={String(n.name)}>{String(n.name)}{n.name === 'internal' ? ' (private, recommended)' : ''}</option>)}</Select></Field>}
            </WizardSection>
          )}
        </div>
      )}
      {step === 3 && (
        <WizardSection title="Ready to create" hint="Credentials are generated and stored encrypted; apps get them by reference.">
          <WizardChecks checks={checks} label="Pre-flight checks" />
        </WizardSection>
      )}
    </WizardShell>
  )
}
