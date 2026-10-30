import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { useAction, useList, useOne } from '../api/hooks'
import { Card, PageHeader } from './Card'
import { Badge } from './Badge'
import { ago } from '../lib/format'
import type { R } from '../lib/util'
import Select from './Select'

const PRESETS: [string, string][] = [['* * * * *', 'Every minute'], ['*/5 * * * *', 'Every 5 minutes'], ['0 * * * *', 'Hourly'], ['0 3 * * *', 'Daily at 03:00'], ['0 3 * * 1', 'Weekly (Monday 03:00)']]

const MODE_LABEL: Record<string, string> = {
  container: 'In the app container', oneoff: 'One-off container', host: 'On the server (shell)', http: 'HTTP request',
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
    .then(() => { setF({ ...BLANK, timezone: f.timezone }); page.refetch() }).catch(() => {})

  const set = (patch: R) => setF({ ...f, ...patch })
  const field = 'flex flex-col gap-1'

  return (
    <>
      <PageHeader title={title} subtitle={subtitle ?? `${w.used ?? 0} of ${w.limit ?? '…'} used.`} />
      <Card title="New job">
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))] text-sm">
          <label className={field}>Name<input className="input" value={String(f.name)} onChange={(e) => set({ name: e.target.value })} placeholder="Nightly cleanup" /></label>
          <label className={field}>Run
            <Select className="input" value={mode} onChange={(e) => set({ run_mode: e.target.value })}>
              {modes.map((m) => <option key={m} value={m}>{MODE_LABEL[m] ?? m}</option>)}
            </Select>
          </label>
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
          {preview ? <>{String(preview.description)} ({String(preview.timezone)}). Next: {((preview.next as string[]) ?? []).map((d) => new Date(d).toLocaleString()).join(' · ')}</> : 'Not a valid schedule.'}
        </p>
        <p className="m-0 mt-1 text-xs text-muted">
          Commands run as the app's non-root user with its environment; secret values are hidden in the log.
          {w.min_interval_minutes ? ` The shortest interval on your plan is ${String(w.min_interval_minutes)} minute(s).` : ''}
        </p>
        <button type="button" className="btn btn-primary mt-3" disabled={busy || !ready || !preview} onClick={create}>Add job</button>
      </Card>

      <Card title="Jobs">
        {((w.jobs as R[]) ?? []).length === 0 ? <p className="m-0 text-sm text-muted">No scheduled jobs yet.</p> : (
          <ul className="m-0 p-0 list-none flex flex-col gap-3 text-sm">
            {(w.jobs as R[]).map((j) => (
              <li key={String(j.id)} className="flex flex-col gap-1 border-b border-[var(--line)] pb-3">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-semibold flex-1">{String(j.name)}</span>
                  <Badge>{MODE_LABEL[String(j.run_mode)] ?? String(j.run_mode)}</Badge>
                  {!j.enabled && <Badge>paused</Badge>}
                  {Number(j.failure_streak) >= 3 && <Badge tone="r">{String(j.failure_streak)} failures in a row</Badge>}
                  {j.last_status ? <Badge tone={STATUS_TONE[String(j.last_status)] ?? 'y'}>{String(j.last_result ?? j.last_status)}</Badge> : null}
                </div>
                <span className="text-xs text-muted">{String(j.schedule ?? j.cron)} ({String(j.timezone)}) · <span className="font-mono">{j.run_mode === 'http' ? `${j.method} ${j.url}` : `${j.app ?? 'server'}: ${j.command}`}</span></span>
                <span className="text-xs text-muted">{j.last_run_at ? `Last run ${ago(String(j.last_run_at))}` : 'Not run yet'}{j.next_run_at ? ` · next ${ago(String(j.next_run_at))}` : ''} · if still running: {String(j.overlap)}{Number(j.retries) ? ` · ${j.retries} retries` : ''}</span>
                <span className="flex flex-wrap gap-2">
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${base}/${j.id}/run`, label: `Run ${j.name}` }).then(() => setTimeout(() => { page.refetch(); showRuns(String(j.id)) }, 3000)).catch(() => {})}>Run now</button>
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${base}/${j.id}/run`, body: { test: true }, label: `Test ${j.name}`, success: 'Test run started — no alerts' }).then(() => setTimeout(() => showRuns(String(j.id)), 3000)).catch(() => {})}>Test run</button>
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${base}/${j.id}`, method: 'PATCH', body: { enabled: !j.enabled }, label: j.enabled ? 'Pause' : 'Resume' }).then(() => page.refetch()).catch(() => {})}>{j.enabled ? 'Pause' : 'Resume'}</button>
                  <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${base}/${j.id}/duplicate`, label: 'Duplicate', success: 'Copy added, paused' }).then(() => page.refetch()).catch(() => {})}>Duplicate</button>
                  <button type="button" className="btn btn-sm" onClick={() => showRuns(String(j.id))}>Run log</button>
                  <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => { if (window.confirm(`Delete ${j.name}?`)) run({ path: `${base}/${j.id}`, method: 'DELETE', label: 'Delete job' }).then(() => page.refetch()).catch(() => {}) }}>Delete</button>
                </span>
                {log && log.id === j.id && <div className="flex flex-col gap-2">
                  <input className="input !w-64 text-xs" placeholder="Search output…" aria-label="Search run output" defaultValue={log.search}
                    onKeyDown={(e) => { if (e.key === 'Enter') showRuns(String(j.id), (e.target as HTMLInputElement).value) }} />
                  <ul className="m-0 p-0 list-none flex flex-col gap-1">
                    {log.runs.length === 0 ? <li className="text-xs text-muted">No runs{log.search ? ' match' : ' yet'}.</li> : log.runs.map((r) => <li key={String(r.id)} className="text-xs">
                      <Badge tone={STATUS_TONE[String(r.status)] ?? 'y'}>{String(r.status)}</Badge> {ago(String(r.started_at))} · exit {String(r.exit_code ?? '–')} · {r.duration_ms != null ? `${(Number(r.duration_ms) / 1000).toFixed(1)} s` : ''} · {String(r.source)}{Number(r.attempt) > 1 ? ` (attempt ${r.attempt})` : ''}
                      {' '}<button type="button" className="btn btn-sm btn-ghost" onClick={() => download(String(j.id), String(r.id)).catch(() => {})}>Download</button>
                      {r.output ? <pre className="m-0 mt-1 p-2 rounded bg-[var(--code-bg)] text-[var(--code-fg)] whitespace-pre-wrap max-h-40 overflow-auto">{String(r.output)}</pre> : null}
                    </li>)}
                  </ul>
                </div>}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}
