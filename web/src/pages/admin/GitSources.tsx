import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'
import { useAction, useOne } from '../../api/hooks'
import { api } from '../../api/client'
import { useToast } from '../../components/Toasts'
import { Card, PageHeader } from '../../components/Card'
import { Badge, type Tone } from '../../components/Badge'
import { CopyButton } from '../../components/CopyButton'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

const PROVIDERS: Record<string, { label: string; abbr: string; color: string; hint: string }> = {
  github: { label: 'GitHub', abbr: 'GH', color: '#24292F', hint: 'A fine-grained personal access token with read access to contents' },
  gitlab: { label: 'GitLab', abbr: 'GL', color: '#E24329', hint: 'A personal or project access token with read_repository and read_api' },
  gitea: { label: 'Gitea', abbr: 'GT', color: '#609926', hint: 'An access token from your self-hosted Gitea (Settings → Applications)' },
  bitbucket: { label: 'Bitbucket', abbr: 'BB', color: '#2684FF', hint: 'Your Bitbucket username and an app password with repository read' },
}
const OUTCOME: Record<string, Tone> = { deployed: 'g', preview: 'b', ignored: 'n', rejected: 'r' }

/** GIT: provider connections, deploy keys and recent webhook deliveries. */
export default function GitSources({ base = '/git', customer = false }: { base?: string; customer?: boolean }) {
  const page = useOne<R>(base)
  const { run, busy } = useAction()
  const [form, setForm] = useState<string | null>(null)
  const [f, setF] = useState({ token: '', base_url: '', username: '' })
  const [keyName, setKeyName] = useState('')
  const [repos, setRepos] = useState<Record<string, R[]>>({})
  const w = page.item ?? {}
  const connections = (w.connections as R[]) ?? []
  const keys = (w.keys as R[]) ?? []
  const refetch = () => page.refetch()

  const connect = (provider: string) => run({ path: `${base}/connections`, body: { provider, token: f.token, ...(f.base_url ? { base_url: f.base_url } : {}), ...(f.username ? { username: f.username } : {}) }, label: `Connect ${PROVIDERS[provider].label}`, success: 'Connection saved' })
    .then(() => { setForm(null); setF({ token: '', base_url: '', username: '' }); refetch() }).catch(() => {})
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const oauth = (w.oauth ?? {}) as Record<string, boolean>
  useEffect(() => {
    const ok = params.get('connected'), bad = params.get('git_error')
    if (ok) toast.success(`${PROVIDERS[ok]?.label ?? ok} connected`)
    if (bad) toast.error(new Error(bad), 'Sign-in failed')
    if (ok || bad) setParams({}, { replace: true })
  }, [params, setParams, toast])
  const signIn = (provider: string) => run<R>({ path: `${base}/oauth/${provider}/start`, label: `Sign in with ${PROVIDERS[provider].label}`, silent: true })
    .then((r) => { const url = String(((r as R)?.data as R)?.url ?? ''); if (url.startsWith('https://')) window.location.assign(url) }).catch((e) => toast.error(e, 'Sign-in is not available'))
  const loadRepos = (c: R) => api.get<R>(`${base}/connections/${c.id}/repositories`)
    .then((r) => setRepos((cur) => ({ ...cur, [String(c.id)]: ((r as R)?.data as R[]) ?? [] }))).catch((e) => toast.error(e, 'Could not list repositories'))

  return (
    <>
      <PageHeader title={customer ? 'Git & deploy keys' : 'Git sources'} subtitle={customer ? 'Connect your Git account or add a deploy key to host apps from private repositories.' : 'Connect Git providers for private repositories, and give builds a deploy key for SSH repositories.'} />
      <Card title="Providers">
        <div className="flex flex-col">
          {Object.entries(PROVIDERS).map(([id, p]) => {
            const mine = connections.filter((c) => c.provider === id)
            return (
              <div key={id} className="py-3 border-t border-line first:border-t-0">
                <div className="flex flex-wrap items-center gap-3">
                  <span aria-hidden="true" className="grid place-items-center w-9 h-9 rounded-lg text-white text-xs font-bold" style={{ background: p.color }}>{p.abbr}</span>
                  <span className="flex-1 min-w-[160px]"><span className="block font-semibold text-sm">{p.label}</span><span className="block text-xs text-muted">{mine.length ? `${mine.length} connection${mine.length === 1 ? '' : 's'}` : 'Not connected'}</span></span>
                  {oauth[id] && <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={() => signIn(id)}>Sign in with {p.label}</button>}
                  <button type="button" className="btn btn-sm" onClick={() => setForm(form === id ? null : id)}>{form === id ? 'Cancel' : oauth[id] ? 'Use a token' : `Connect ${p.label}`}</button>
                </div>
                {form === id && (
                  <div className="mt-3 flex flex-col gap-2 text-sm">
                    {(id === 'gitea' || id === 'gitlab' || id === 'github') && <label className="flex flex-col gap-1">{id === 'gitea' ? 'Server address' : 'Server address (self-hosted only)'}<input className="input font-mono" placeholder="https://git.example.com" value={f.base_url} onChange={(e) => setF({ ...f, base_url: e.target.value })} /></label>}
                    {id === 'bitbucket' && <label className="flex flex-col gap-1">Username<input className="input" value={f.username} onChange={(e) => setF({ ...f, username: e.target.value })} /></label>}
                    <label className="flex flex-col gap-1">{id === 'bitbucket' ? 'App password' : 'Access token'}<input className="input font-mono" type="password" autoComplete="off" value={f.token} onChange={(e) => setF({ ...f, token: e.target.value })} /></label>
                    <span className="text-xs text-muted">{p.hint}. It is stored encrypted and never shown again.</span>
                    <div><button type="button" className="btn btn-primary" disabled={busy || f.token.length < 8 || (id === 'gitea' && !f.base_url) || (id === 'bitbucket' && !f.username)} onClick={() => connect(id)}>Connect</button></div>
                  </div>
                )}
                {mine.map((c) => (
                  <div key={String(c.id)} className="mt-2 ml-12 rounded-md border border-line p-2 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-semibold">{String(c.account || p.label)}</span>{c.base_url ? <span className="text-xs text-muted font-mono">{String(c.base_url)}</span> : null}
                      <Badge tone={c.status === 'connected' ? 'g' : 'r'}>{c.status === 'connected' ? 'Connected' : 'Failed'}</Badge>
                      <span className="text-xs text-muted">{((c.used_by as string[]) ?? []).length ? `Used by ${(c.used_by as string[]).join(', ')}` : 'Not used yet'}</span>
                      <span className="ml-auto flex gap-1">
                        <button type="button" className="btn btn-sm" disabled={busy || c.status !== 'connected'} onClick={() => loadRepos(c)}>Repositories</button>
                        <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${base}/connections/${c.id}/check`, label: 'Check connection', success: 'Checked' }).then(refetch).catch(() => {})}>Check</button>
                        <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => run({ path: `${base}/connections/${c.id}`, method: 'DELETE', label: 'Remove connection', success: 'Removed' }).then(refetch).catch(() => {})}>Remove</button>
                      </span>
                    </div>
                    {c.error ? <div role="alert" className="text-xs text-danger mt-1">{String(c.error)}</div> : null}
                    {repos[String(c.id)] && <ul className="list-none m-0 mt-2 p-0 flex flex-col gap-1 max-h-48 overflow-auto">{repos[String(c.id)].map((r) => (
                      <li key={String(r.name)} className="flex flex-wrap items-center gap-2 text-xs"><span className="font-semibold">{String(r.name)}</span>{r.private ? <Badge tone="n">private</Badge> : null}<code className="text-muted break-all">{String(r.clone_url)}</code><CopyButton value={String(r.clone_url)} label="Copy" /></li>
                    ))}</ul>}
                  </div>
                ))}
              </div>
            )
          })}
        </div>
      </Card>

      <Card title="SSH deploy keys">
        <div className="flex flex-wrap gap-2 mb-3">
          <input className="input flex-1 min-w-[180px] font-mono" aria-label="Key name" placeholder="deploy-shop" value={keyName} onChange={(e) => setKeyName(e.target.value)} />
          <button type="button" className="btn btn-primary" disabled={busy || keyName.trim().length < 2} onClick={() => run({ path: `${base}/keys`, body: { name: keyName.trim() }, label: 'Generate key', success: 'ed25519 key generated' }).then(() => { setKeyName(''); refetch() }).catch(() => {})}>Generate key</button>
        </div>
        {keys.length === 0 ? <p className="m-0 text-sm text-muted">No deploy keys yet.</p> : (
          <ul className="list-none m-0 p-0 flex flex-col">{keys.map((k) => (
            <li key={String(k.id)} className="py-2 border-t border-line first:border-t-0 text-sm flex flex-col gap-1">
              <div className="flex flex-wrap items-center gap-2"><span className="font-semibold">{String(k.name)}</span><code className="text-xs text-muted">{String(k.fingerprint)}</code>
                <span className="text-xs text-muted">{((k.used_by as string[]) ?? []).length ? `Used by ${(k.used_by as string[]).join(', ')}` : 'Not used yet'}{k.last_used_at ? ` · last build ${ago(String(k.last_used_at))}` : ''}</span>
                <span className="ml-auto flex gap-1"><CopyButton value={String(k.public_key)} label="Copy public key" />
                  <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => run({ path: `${base}/keys/${k.id}`, method: 'DELETE', label: 'Delete key', success: 'Key deleted' }).then(refetch).catch(() => {})}>Delete</button></span>
              </div>
              <code className="text-xs break-all text-muted">{String(k.public_key)}</code>
            </li>
          ))}</ul>
        )}
        <p className="m-0 mt-3 text-xs text-muted">Add the public key as a read-only deploy key in the repository, then pick the key on the app's source. Private keys are encrypted and only sent to build servers.</p>
      </Card>

      {!customer && <Card title="Recent webhook deliveries">
        {((w.deliveries as R[]) ?? []).length === 0 ? <p className="m-0 text-sm text-muted">No deliveries yet. Each project shows its webhook address on the Projects page.</p> : (
          <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="text-left text-xs text-muted"><th className="py-1 pr-3">When</th><th className="pr-3">Event</th><th className="pr-3">Repository</th><th>Result</th></tr></thead>
            <tbody>{((w.deliveries as R[]) ?? []).map((d) => (
              <tr key={String(d.id)} className="border-t border-line"><td className="py-1.5 pr-3 whitespace-nowrap text-muted">{ago(String(d.created_at))}</td><td className="pr-3 font-mono text-xs">{String(d.event)}</td>
                <td className="pr-3">{String(d.repo ?? '—')}{d.ref ? <span className="text-muted"> @ {String(d.ref).replace('refs/heads/', '')}</span> : null}</td>
                <td><Badge tone={OUTCOME[String(d.outcome)] ?? 'n'}>{String(d.outcome)}</Badge>{d.detail ? <span className="ml-2 text-xs text-muted">{String(d.detail)}</span> : null}</td></tr>
            ))}</tbody></table></div>
        )}
      </Card>}
    </>
  )
}
