import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { useAction, useList, useOne } from '../api/hooks'
import { Card, PageHeader, StatTile } from './Card'
import { Tabs } from './Tabs'
import { Badge } from './Badge'
import { ago } from '../lib/format'
import type { R } from '../lib/util'
import Select from './Select'

const PRESETS: [string, string][] = [['* * * * *', 'Every minute'], ['*/5 * * * *', 'Every 5 minutes'], ['0 * * * *', 'Hourly'], ['0 3 * * *', 'Daily at 03:00'], ['0 3 * * 1', 'Weekly (Monday 03:00)']]

const MODE_LABEL: Record<string, string> = {
  container: 'In the app container', oneoff: 'One-off container', host: 'On the server (shell)', http: 'HTTP request',
}

const MODE_DESC: Record<string, string> = {
  container: 'Run a command inside the running app container', oneoff: 'Start a fresh container, run, then remove it',
  host: 'Run a shell command on the server (admins only)', http: 'Call a URL — webhook or health ping',
}

const STATUS_TONE: Record<string, 'g' | 'r' | 'y' | 'n'> = { succeeded: 'g', failed: 'r', missed: 'r', running: 'y', skipped: 'n', stopped: 'n' }

const ZONES = ['UTC', 'Asia/Colombo', 'Asia/Kolkata', 'Asia/Singapore', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Los_Angeles', 'Australia/Sydney']

const BLANK: R = {
  name: '', run_mode: 'container', application_id: '', server_id: '', command: '', url: '', method: 'GET', expected_status: '',
  cron: '*/5 * * * *', custom: false, timezone: 'UTC', overlap: 'skip', retries: 0, timeout: 600, long_after: '',
}

const actionFor = (mode: string) => (mode === 'http' ? 'url' : mode === 'host' ? 'server_command' : 'app_command')

/**
 * CRON-1..9: the scheduled-job screen, shared by the customer panel (/app/cron) and
 * Operations → Cron & scheduler (/cron), which also lists every customer's jobs for
 * platform admins and offers the server mode.
 */
export function CronManager({ base, title, subtitle }: { base: string; title: string; subtitle?: string }) {
  const page = useOne<R>(base, undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const w = page.item ?? {}
  const apps = (w.apps as R[]) ?? []
  const modes = (w.modes as string[]) ?? ['container', 'oneoff', 'http']
  const servers = useList<R>(modes.includes('host') ? '/servers' : null)
  const [f, setF] = useState<R>(BLANK)
  const [preview, setPreview] = useState<R | null>(null)
  const [log, setLog] = useState<{ id: string; runs: R[]; search: string } | null>(null)
  const [open, setOpen] = useState(false)
  const [tab, setTab] = useState<'jobs' | 'history'>('jobs')
  const jobs = (w.jobs as R[]) ?? []

  // CRON-1: a plain-words preview and the next three runs, as the schedule is typed.
  useEffect(() => {
    const t = setTimeout(() => {
      api.post<{ data: R }>(`${base}/preview`, { cron: f.cron, timezone: f.timezone }).then((r) => setPreview(r.data)).catch(() => setPreview(null))
    }, 300)
    return () => clearTimeout(t)
  }, [base, f.cron, f.timezone])

  const showRuns = async (id: string, search = '') => {
    const q = search ? `?search=${encodeURIComponent(search)}` : ''
    setLog({ id, search, runs: (await api.get<{ data: R[] }>(`${base}/${id}/runs${q}`)).data })
  }

  const download = async (jobId: string, runId: string) => {
    const r = await api.get<{ data: { filename: string; text: string } }>(`${base}/${jobId}/runs/${runId}/download`)
    const url = URL.createObjectURL(new Blob([r.data.text], { type: 'text/plain' }))
    const a = document.createElement('a')
    a.href = url; a.download = r.data.filename; document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  }

  const mode = String(f.run_mode)
  const body = (): R => {
    const b: R = { name: f.name, action: actionFor(mode), run_mode: mode, cron: f.cron, timezone: f.timezone, overlap: f.overlap, retries: Number(f.retries) || 0, timeout: Number(f.timeout) || 600 }
    if (f.long_after) b.long_after = Number(f.long_after)
    if (mode === 'http') Object.assign(b, { url: f.url, method: f.method, ...(f.expected_status ? { expected_status: Number(f.expected_status) } : {}) })
    else if (mode === 'host') Object.assign(b, { server_id: f.server_id || servers.rows[0]?.id, command: f.command })
    else Object.assign(b, { application_id: f.application_id || apps[0]?.id, command: f.command })
    return b
  }
  const ready = !!f.name && (mode === 'http' ? !!f.url : !!f.command) && (mode === 'host' ? servers.rows.length > 0 : mode === 'http' || apps.length > 0)
  const create = () => run({ path: base, body: body(), label: 'Add scheduled job', success: 'Scheduled job added' })
    .then(() => { setF({ ...BLANK, timezone: f.timezone }); setOpen(false); page.refetch() }).catch(() => {})

  const set = (patch: R) => setF({ ...f, ...patch })
  const field = 'flex flex-col gap-1'

  const viewLog = (id: string, search = '') => { setTab('history'); return showRuns(id, search) }
  const logJob = log ? jobs.find((j) => String(j.id) === log.id) : undefined
  const failed = jobs.filter((j) => j.last_status === 'failed' || j.last_status === 'missed').length
  const paused = jobs.filter((j) => !j.enabled).length

  return (
    <>
      <PageHeader title={title} subtitle={subtitle ?? `${w.used ?? 0} of ${w.limit ?? '…'} used.`}
        actions={!open && <button type="button" className="btn btn-primary" onClick={() => setOpen(true)}>New cron job</button>} />

      <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
        <StatTile label="Jobs" value={jobs.length} delta={w.limit != null ? `of ${String(w.limit)}` : undefined} />
        <StatTile label="Active" value={jobs.length - paused} />
        <StatTile label="Failed / missed" value={failed} valueTone={failed ? 'r' : 'n'} />
        <StatTile label="Paused" value={paused} />
      </div>

      {open && <Card title="New cron job" subtitle="Pick where it runs, then when.">
        <div role="radiogroup" aria-label="Run mode" className="grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
          {modes.map((m) => {
            const on = m === mode
            return <button key={m} type="button" role="radio" aria-checked={on} onClick={() => set({ run_mode: m })}
              className="text-left rounded-lg border p-3 cursor-pointer bg-transparent"
              style={{ borderColor: on ? 'var(--accent)' : 'var(--line)', background: on ? 'var(--sel)' : undefined }}>
              <div className="text-sm font-semibold">{MODE_LABEL[m] ?? m}</div>
              <div className="text-xs text-muted mt-1">{MODE_DESC[m] ?? ''}</div>
            </button>
          })}
        </div>
        <div className="mt-4 grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 text-sm">
          <label className={field}>Name<input className="input" value={String(f.name)} onChange={(e) => set({ name: e.target.value })} placeholder="Nightly cleanup" /></label>
          {mode === 'http' ? <>
            <label className={field}>URL<input className="input font-mono text-xs" value={String(f.url)} onChange={(e) => set({ url: e.target.value })} placeholder="https://example.com/cron.php" /></label>
            <label className={field}>Method<Select className="input" value={String(f.method)} onChange={(e) => set({ method: e.target.value })}>{['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'].map((m) => <option key={m}>{m}</option>)}</Select></label>
            <label className={field}>Expected status<input className="input" type="number" value={String(f.expected_status)} onChange={(e) => set({ expected_status: e.target.value })} placeholder="any 2xx" /></label>
          </> : <>
            {mode === 'host'
              ? <label className={field}>Server<Select className="input" value={String(f.server_id || servers.rows[0]?.id || '')} onChange={(e) => set({ server_id: e.target.value })}>{servers.rows.map((s) => <option key={String(s.id)} value={String(s.id)}>{String(s.name)}</option>)}</Select></label>
              : <label className={field}>App<Select className="input" value={String(f.application_id || apps[0]?.id || '')} onChange={(e) => set({ application_id: e.target.value })}>{apps.map((a) => <option key={String(a.id)} value={String(a.id)}>{String(a.name)}</option>)}</Select></label>}
            <label className={field}>Command<input className="input font-mono text-xs" value={String(f.command)} onChange={(e) => set({ command: e.target.value })} placeholder="php artisan schedule:run" /></label>
          </>}
          <label className={field}>When
            {f.custom ? <input className="input font-mono text-xs" value={String(f.cron)} onChange={(e) => set({ cron: e.target.value })} placeholder="m h dom mon dow" />
              : <Select className="input" value={String(f.cron)} onChange={(e) => e.target.value === 'custom' ? set({ custom: true }) : set({ cron: e.target.value })}>{PRESETS.map(([c, l]) => <option key={c} value={c}>{l}</option>)}<option value="custom">Custom expression…</option></Select>}
          </label>
          <label className={field}>Timezone<Select className="input" value={String(f.timezone)} onChange={(e) => set({ timezone: e.target.value })}>{ZONES.map((z) => <option key={z}>{z}</option>)}</Select></label>
          <label className={field}>If still running
            <Select className="input" value={String(f.overlap)} onChange={(e) => set({ overlap: e.target.value })}>
              <option value="skip">Skip the new run</option><option value="parallel">Run in parallel</option><option value="stop">Stop the old run</option>
            </Select>
          </label>
          <label className={field}>Retries<Select className="input" value={String(f.retries)} onChange={(e) => set({ retries: e.target.value })}>{[0, 1, 2, 3, 5].map((n) => <option key={n} value={n}>{n === 0 ? 'None' : `${n}, with backoff`}</option>)}</Select></label>
          <label className={field}>Timeout (s)<input className="input" type="number" min={10} value={String(f.timeout)} onChange={(e) => set({ timeout: e.target.value })} /></label>
          <label className={field}>Alert if running longer than (s)<input className="input" type="number" min={60} value={String(f.long_after)} onChange={(e) => set({ long_after: e.target.value })} placeholder="off" /></label>
        </div>
        <p className="m-0 mt-2 text-xs text-muted">
          {preview ? <>{String(preview.description)} ({String(preview.timezone)}). Next runs: {((preview.next as string[]) ?? []).map((d) => new Date(d).toLocaleString()).join(' · ')}</> : 'Not a valid schedule.'}
        </p>
        <p className="m-0 mt-1 text-xs text-muted">
          Commands run as the app's non-root user with its environment; secret values are hidden in the log.
          {w.min_interval_minutes ? ` The shortest interval on your plan is ${String(w.min_interval_minutes)} minute(s).` : ''}
        </p>
        <div className="flex flex-wrap gap-2 mt-3">
          <button type="button" className="btn btn-primary" disabled={busy || !ready || !preview} onClick={create}>Create job</button>
          <button type="button" className="btn" onClick={() => setOpen(false)}>Cancel</button>
        </div>
      </Card>}

      <Tabs tabs={[{ id: 'jobs', label: `Jobs (${jobs.length})` }, { id: 'history', label: 'Run history' }]} value={tab} onChange={setTab} />

      {tab === 'jobs' && <Card pad={false}>
        {jobs.length === 0 ? <p className="m-0 p-5 text-sm text-muted">No scheduled jobs yet.</p> : <>
          <div className="hidden md:grid grid-cols-[minmax(0,2.2fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] gap-3 px-5 py-2.5 text-xs font-semibold text-muted border-b border-line">
            <span>Job</span><span>Owner · runs in</span><span>Schedule</span><span>Last run</span><span>Status</span>
          </div>
          <ul className="m-0 p-0 list-none text-sm">
            {jobs.map((j) => (
              <li key={String(j.id)} className="px-5 py-3 border-b border-line last:border-b-0">
                <div className="grid gap-2 md:gap-3 md:grid-cols-[minmax(0,2.2fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-center">
                  <div className="min-w-0">
                    <div className="font-semibold">{String(j.name)}</div>
                    <div className="font-mono text-xs text-muted truncate">{j.run_mode === 'http' ? `${j.method} ${j.url}` : String(j.command)}</div>
                  </div>
                  <div className="text-xs text-muted">{[j.owner, j.app ?? (j.run_mode === 'host' ? 'server' : null)].filter(Boolean).map(String).join(' · ')}{' · '}{MODE_LABEL[String(j.run_mode)] ?? String(j.run_mode)}</div>
                  <div className="text-xs"><span className="font-mono">{String(j.cron ?? '')}</span><div className="text-muted">{String(j.schedule ?? '')} ({String(j.timezone)})</div></div>
                  <div className="text-xs text-muted">{j.last_run_at ? ago(String(j.last_run_at)) : 'Not run yet'}{j.next_run_at ? <div>next {ago(String(j.next_run_at))}</div> : null}</div>
                  <div className="flex flex-wrap gap-1">
                    {!j.enabled ? <Badge>paused</Badge> : j.last_status ? <Badge tone={STATUS_TONE[String(j.last_status)] ?? 'y'}>{String(j.last_result ?? j.last_status)}</Badge> : <Badge tone="g">active</Badge>}
                    {Number(j.failure_streak) >= 3 && <Badge tone="r">{String(j.failure_streak)} failures in a row</Badge>}
                  </div>
                </div>
                <div className="text-xs text-muted mt-1">If still running: {String(j.overlap)}{Number(j.retries) ? ` · ${j.retries} retries` : ''}</div>
                <span className="flex flex-wrap gap-2 mt-2">
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${base}/${j.id}/run`, label: `Run ${j.name}` }).then(() => setTimeout(() => { page.refetch(); viewLog(String(j.id)) }, 3000)).catch(() => {})}>Run now</button>
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${base}/${j.id}/run`, body: { test: true }, label: `Test ${j.name}`, success: 'Test run started — no alerts' }).then(() => setTimeout(() => viewLog(String(j.id)), 3000)).catch(() => {})}>Test run</button>
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${base}/${j.id}`, method: 'PATCH', body: { enabled: !j.enabled }, label: j.enabled ? 'Pause' : 'Resume' }).then(() => page.refetch()).catch(() => {})}>{j.enabled ? 'Pause' : 'Resume'}</button>
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${base}/${j.id}/duplicate`, label: 'Duplicate', success: 'Copy added, paused' }).then(() => page.refetch()).catch(() => {})}>Duplicate</button>
                  <button type="button" className="btn btn-sm" onClick={() => viewLog(String(j.id))}>Log</button>
                  <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => { if (window.confirm(`Delete ${j.name}?`)) run({ path: `${base}/${j.id}`, method: 'DELETE', label: 'Delete job' }).then(() => page.refetch()).catch(() => {}) }}>Delete</button>
                </span>
              </li>
            ))}
          </ul>
        </>}
      </Card>}

      {tab === 'history' && <Card title={logJob ? `Run history — ${String(logJob.name)}` : 'Run history'}>
        <div className="flex flex-wrap gap-2 mb-3">
          <Select className="input !w-64" aria-label="Job" value={log?.id ?? ''} onChange={(e) => e.target.value && showRuns(e.target.value)}>
            <option value="">Choose a job…</option>
            {jobs.map((j) => <option key={String(j.id)} value={String(j.id)}>{String(j.name)}</option>)}
          </Select>
          {log && <input key={log.id} className="input !w-64 text-xs" placeholder="Search output…" aria-label="Search run output" defaultValue={log.search}
            onKeyDown={(e) => { if (e.key === 'Enter') showRuns(log.id, (e.target as HTMLInputElement).value) }} />}
        </div>
        {!log ? <p className="m-0 text-sm text-muted">Pick a job, or use Log on a job, to see its runs.</p> :
          <ul className="m-0 p-0 list-none flex flex-col gap-2">
            {log.runs.length === 0 ? <li className="text-xs text-muted">No runs{log.search ? ' match' : ' yet'}.</li> : log.runs.map((r) => <li key={String(r.id)} className="text-xs border-b border-line pb-2">
              <Badge tone={STATUS_TONE[String(r.status)] ?? 'y'}>{String(r.status)}</Badge> {ago(String(r.started_at))} · exit {String(r.exit_code ?? '–')} · {r.duration_ms != null ? `${(Number(r.duration_ms) / 1000).toFixed(1)} s` : ''} · {String(r.source)}{Number(r.attempt) > 1 ? ` (attempt ${r.attempt})` : ''}
              {' '}<button type="button" className="btn btn-sm btn-ghost" onClick={() => download(log.id, String(r.id)).catch(() => {})}>Download</button>
              {r.output ? <pre className="m-0 mt-1 p-2 rounded bg-[var(--code-bg)] text-[var(--code-fg)] whitespace-pre-wrap max-h-40 overflow-auto">{String(r.output)}</pre> : null}
            </li>)}
          </ul>}
      </Card>}
    </>
  )
}
