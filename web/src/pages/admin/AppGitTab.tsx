import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { useAction, useOne } from '../../api/hooks'
import { Card, Field, Toggle } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { CopyButton } from '../../components/CopyButton'
import { ago } from '../../lib/format'

type R = Record<string, any>
type Test = { ok: boolean; message: string; commit?: string | null }

/** Where to paste the deploy key, by host. */
function keyHelp(repo: string): string {
  if (/github\.com/i.test(repo)) return 'GitHub: repository → Settings → Deploy keys → Add deploy key. Read access is enough.'
  if (/gitlab/i.test(repo)) return 'GitLab: project → Settings → Repository → Deploy keys → Add new key.'
  if (/bitbucket/i.test(repo)) return 'Bitbucket: repository → Repository settings → Access keys → Add key.'
  return 'Add it as a read-only deploy key in your Git host’s repository settings.'
}

function webhookHelp(repo: string): string {
  if (/gitlab/i.test(repo)) return 'GitLab: Settings → Webhooks. Paste the URL, put the secret in “Secret token”, tick Push events and Merge request events.'
  if (/github\.com/i.test(repo)) return 'GitHub: Settings → Webhooks → Add webhook. Paste the URL, content type application/json, paste the secret, choose “Just the push event” (or also Pull requests for previews).'
  return 'In your Git host’s webhook settings: paste the URL and the secret, JSON content, push events.'
}

/** GIT-TAB: deploy this app from Git on its own: key, repository, webhook, test, switch. */
export function AppGitTab({ app }: { app: R }) {
  const q = useOne<R>(`/applications/${app.id}/git`)
  const { run, busy } = useAction()
  const s = q.item
  const [f, setF] = useState({ repo: '', branch: 'main', git_ssh_key_id: '', deploy_on_push: true })
  const [test, setTest] = useState<Test | null>(null)
  const [secret, setSecret] = useState<string | null>(null)
  // Filled from the server once; later reloads (after generating a key) keep what was typed.
  const [loaded, setLoaded] = useState(false)
  useEffect(() => {
    if (s && !loaded) {
      setF({ repo: s.repo ?? '', branch: s.branch ?? 'main', git_ssh_key_id: s.deploy_key?.id ?? '', deploy_on_push: !!s.deploy_on_push })
      setLoaded(true)
    }
  }, [s, loaded])
  if (!s) return <p className="text-sm text-muted">{q.error ? 'Could not load the Git settings.' : 'Loading…'}</p>

  const ssh = f.repo.trim() !== '' && !/^https?:\/\//.test(f.repo.trim())
  const key = (s.keys as R[]).find((k) => k.id === f.git_ssh_key_id)
  const shownKey = s.deploy_key && s.deploy_key.id === f.git_ssh_key_id ? s.deploy_key : null
  const body = { repo: f.repo.trim(), branch: f.branch.trim(), git_ssh_key_id: ssh ? (f.git_ssh_key_id || null) : null, deploy_on_push: f.deploy_on_push }
  const refetch = () => q.refetch()
  const onKey = (r: { data: R }) => {
    const made = r.data.deploy_key as R
    setF((x) => ({ ...x, git_ssh_key_id: String(made.id) }))
    setTest(null)
    refetch()
  }
  const testBody = () => ({ repo: body.repo, branch: body.branch, git_ssh_key_id: body['git_ssh_key_id'] })
  const runTest = () => run<{ data: Test }>({ path: `/applications/${app.id}/git/test`, body: testBody(), label: 'Test repository', silent: true })
    .then((r) => setTest(r.data)).catch(() => {})
  const steps = [
    { done: !!f.repo.trim(), label: 'Repository and branch' },
    { done: !ssh || !!f.git_ssh_key_id, label: 'Deploy key added to the repository' },
    { done: !!test?.ok, label: 'Connection tested' },
    { done: !!s.webhook, label: 'Webhook added to the repository' },
  ]

  return (
    <div className="flex flex-col gap-4">
      <Card title="Setup" subtitle="Four steps; the app then deploys by itself on every push.">
        <ol className="m-0 pl-0 list-none flex flex-wrap gap-2">{steps.map((st, i) => (
          <li key={st.label}><Badge tone={st.done ? 'g' : 'n'}>{st.done ? '✓' : i + 1} {st.label}</Badge></li>
        ))}</ol>
      </Card>

      <Card title="1 · Repository">
        <div className="grid gap-3 md:grid-cols-[2fr_1fr]">
          <Field label="Repository address" hint="SSH (git@github.com:acme/shop.git) for private repositories with a deploy key, or https:// for public ones.">
            <input className="input font-mono" value={f.repo} placeholder="git@github.com:acme/shop.git" onChange={(e) => { setF({ ...f, repo: e.target.value }); setTest(null) }} />
          </Field>
          <Field label="Branch"><input className="input font-mono" value={f.branch} onChange={(e) => { setF({ ...f, branch: e.target.value }); setTest(null) }} /></Field>
        </div>
        {app.source_type === 'image' && <p className="m-0 mt-2 text-xs text-muted">This app runs a fixed image today. Saving makes it build from the repository instead.</p>}
      </Card>

      <Card title="2 · Deploy key (SSH)" subtitle="A key only this app uses, so the panel can read a private repository.">
        {!ssh ? <p className="m-0 text-sm text-muted">Not needed for an https:// address. Use an SSH address for a private repository.</p> : <>
          <div className="flex flex-wrap items-end gap-2">
            <Field label="Key"><select className="input" aria-label="Deploy key" value={f.git_ssh_key_id} onChange={(e) => { setF({ ...f, git_ssh_key_id: e.target.value }); setTest(null) }}>
              <option value="">Choose a key…</option>{(s.keys as R[]).map((k) => <option key={k.id} value={k.id}>{k.name}</option>)}
            </select></Field>
            <button type="button" className="btn" disabled={busy} onClick={() => run<{ data: R }>({ path: `/applications/${app.id}/git/key`, label: 'Generate deploy key', success: 'Deploy key generated. Add it to the repository.' })
              .then(onKey).catch(() => {})}>Generate new key</button>
          </div>
          {shownKey ? <div className="mt-3">
            <div className="flex items-center gap-2 mb-1 text-sm"><span className="font-semibold">Public key</span><span className="text-xs text-muted font-mono">{shownKey.fingerprint}</span><CopyButton value={shownKey.public_key} label="Copy public key" /></div>
            <pre aria-label="Public key" className="m-0 p-2 rounded-md border border-line text-xs font-mono whitespace-pre-wrap break-all">{shownKey.public_key}</pre>
            <p className="m-0 mt-2 text-xs text-muted">{keyHelp(f.repo)}</p>
          </div> : key ? <p className="m-0 mt-2 text-xs text-muted">Save to see this key here, or find it under <Link to="/admin/git">Git sources</Link>.</p> : null}
        </>}
      </Card>

      <Card title="3 · Test the connection">
        <div className="flex flex-wrap items-center gap-3">
          <button type="button" className="btn" disabled={busy || !f.repo.trim()} onClick={runTest}>Test connection</button>
          {test && <span role="status" className={`text-sm ${test.ok ? 'text-success' : 'text-danger'}`}>{test.ok ? `✓ ${test.message}${test.commit ? ` Latest commit ${test.commit.slice(0, 7)}.` : ''}` : test.message}</span>}
        </div>
      </Card>

      <Card title="4 · Webhook" subtitle="Your Git host calls this on every push; the panel then deploys the app.">
        {!s.webhook ? <p className="m-0 text-sm text-muted">This app is not in a project yet. Put it in an environment of a project (Projects page) to get a webhook.</p> : <>
          <dl className="m-0 grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm items-center">
            <dt className="text-muted">Payload URL</dt><dd className="m-0 flex items-center gap-2 min-w-0"><code className="font-mono text-xs truncate">{s.webhook.url}</code><CopyButton value={s.webhook.url} label="Copy URL" /></dd>
            <dt className="text-muted">Content type</dt><dd className="m-0 font-mono text-xs">application/json</dd>
            <dt className="text-muted">Secret</dt><dd className="m-0 flex flex-wrap items-center gap-2">
              {secret ? <><code className="font-mono text-xs break-all">{secret}</code><CopyButton value={secret} label="Copy secret" /><span className="text-xs text-muted">Shown once.</span></>
                : <span className="text-xs text-muted">Hidden.</span>}
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => {
                if (!window.confirm(`Make a new webhook secret for project ${s.project.name}? Every repository webhook for this project must be updated with it.`)) return
                run<{ data: { webhook_secret: string } }>({ path: `/applications/${app.id}/git/webhook-secret`, label: 'New webhook secret' }).then((r) => setSecret(r.data.webhook_secret)).catch(() => {})
              }}>{secret ? 'New secret again' : 'Show a new secret'}</button>
            </dd>
            <dt className="text-muted">Project</dt><dd className="m-0">{s.project.name}{s.environment ? ` · ${s.environment.name}${s.environment.branch ? ` (deploys ${s.environment.branch})` : ''}` : ''}</dd>
          </dl>
          <p className="m-0 mt-2 text-xs text-muted">{webhookHelp(f.repo)}</p>
          {(s.deliveries as R[]).length > 0 && <div className="mt-3"><div className="text-xs font-semibold mb-1">Recent deliveries</div>
            <ul className="list-none m-0 p-0 flex flex-col gap-1">{(s.deliveries as R[]).map((d) => (
              <li key={d.id} className="flex flex-wrap items-center gap-2 text-xs"><Badge tone={d.outcome === 'rejected' ? 'r' : d.outcome === 'deployed' || d.outcome === 'ok' ? 'g' : 'n'}>{d.outcome}</Badge>
                <span>{d.event}{d.ref ? ` · ${d.ref}` : ''}</span><span className="text-muted">{d.detail ?? ''} · {ago(d.created_at)}</span></li>
            ))}</ul></div>}
        </>}
      </Card>

      <Card title="Deploy on push">
        <div className="flex items-center gap-3">
          <Toggle checked={f.deploy_on_push} onChange={(v) => setF({ ...f, deploy_on_push: v })} label="Deploy on push" />
          <span className="text-sm">{f.deploy_on_push ? `Every push to ${f.branch || 'the branch'} deploys this app.` : 'Pushes are ignored; deploy by hand.'}</span>
        </div>
        <div className="flex justify-end mt-3">
          <button type="button" className="btn btn-primary" disabled={busy || !body.repo || !body.branch || (ssh && !body.git_ssh_key_id)}
            onClick={() => run({ path: `/applications/${app.id}/git`, method: 'PATCH', body, label: 'Save Git settings', success: 'Git settings saved' }).then(refetch).catch(() => {})}>Save</button>
        </div>
      </Card>
    </div>
  )
}
