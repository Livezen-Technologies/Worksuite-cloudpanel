import { useState } from 'react'
import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader, Toggle } from '../../components/Card'
import { Badge, type Tone } from '../../components/Badge'
import { CopyButton } from '../../components/CopyButton'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'
import { AssistantChat } from '../../components/AssistantChat'

const TABS = ['Connect AI (MCP)', 'Activity', 'Permissions', 'Assistant'] as const
const LEVEL: Record<string, [string, string]> = {
  read_only: ['Read-only', 'AI can look at your apps and logs but never change anything.'],
  ask: ['Asks before changes', 'Changes wait here until you approve them.'],
  autonomous: ['Autonomous', 'AI can restart and redeploy without asking. Everything is still logged.'],
}
const STATUS: Record<string, Tone> = { done: 'g', pending: 'y', failed: 'r', refused: 'r', rejected: 'n' }

export const clientSnippets = (url: string): [string, string, string][] => [
  ['Claude', `claude mcp add --transport http livezen ${url}`, 'Or in Claude Desktop / claude.ai: Settings → Connectors → Add custom connector → paste the URL. You sign in with your account; no token needed.'],
  ['ChatGPT', `Settings → Connectors → Advanced → Developer mode\nAdd MCP server: ${url}`, 'Sign in when prompted and approve the connection.'],
  ['Cursor', `// .cursor/mcp.json\n{ "mcpServers": { "livezen": { "url": "${url}" } } }`, 'Cursor opens a browser window to sign in the first time.'],
  ['VS Code', `// .vscode/mcp.json\n{ "servers": { "livezen": { "type": "http", "url": "${url}" } } }`, 'Works with Copilot agent mode; it asks you to sign in.'],
  ['Any client', `POST ${url}\nAuthorization: Bearer <token>`, 'For clients without sign-in, create an access token above.'],
]

/** UAI: connect AI tools through MCP, see what they did, approve changes and set what they may do. */
export default function UserAi() {
  const page = useOne<R>('/app/ai', undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const [tab, setTab] = useState(0)
  const [client, setClient] = useState(0)
  const [tok, setTok] = useState({ name: '', write: false })
  const [created, setCreated] = useState<string | null>(null)
  const w = page.item ?? {}
  const s = (w.settings ?? {}) as R
  const url = String(w.mcp_url ?? '')
  const pending = (w.pending as R[]) ?? []
  const refetch = () => page.refetch()
  const save = (b: R) => run({ path: '/app/ai/settings', method: 'PATCH', body: b, label: 'AI permissions', success: 'Saved' }).then(refetch).catch(() => {})
  const decide = (a: R, d: 'approve' | 'reject') => run({ path: `/app/ai/actions/${a.id}/${d}`, label: d === 'approve' ? 'Approve' : 'Reject', success: d === 'approve' ? 'Approved' : 'Rejected' }).then(refetch).catch(() => {})
  const snippets = clientSnippets(url)

  return (
    <>
      <PageHeader title="AI assistant & MCP" subtitle="Connect your own AI tools to your apps through MCP, and decide what they may do."
        actions={s.level ? <Badge tone={s.level === 'autonomous' ? 'y' : 'g'}>AI · {LEVEL[String(s.level)]?.[0]}</Badge> : undefined} />
      {pending.length > 0 && (
        <Card title={`Waiting for your approval (${pending.length})`}>
          <ul className="list-none m-0 p-0 flex flex-col">{pending.map((a) => (
            <li key={String(a.id)} className="flex flex-wrap items-center gap-3 py-2 border-t border-line first:border-t-0 text-sm">
              <span className="flex-1 min-w-[200px]"><span className="font-semibold">{String(a.client)}</span> wants to <span className="font-mono">{String(a.tool)}</span>{a.target ? <> on <span className="font-semibold">{String(a.target)}</span></> : null} <span className="text-muted">· {ago(String(a.created_at))}</span></span>
              <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={() => decide(a, 'approve')}>Approve</button>
              <button type="button" className="btn btn-sm" disabled={busy} onClick={() => decide(a, 'reject')}>Reject</button>
            </li>
          ))}</ul>
        </Card>
      )}
      <div role="tablist" aria-label="AI sections" className="flex gap-1 border-b border-line overflow-x-auto">
        {TABS.map((t, i) => <button key={t} role="tab" type="button" aria-selected={tab === i} onClick={() => setTab(i)}
          className={`h-10 px-3.5 border-0 bg-transparent cursor-pointer text-sm whitespace-nowrap ${tab === i ? 'font-semibold' : 'text-muted'}`}
          style={tab === i ? { boxShadow: 'inset 0 -2px 0 var(--accent)' } : undefined}>{t}</button>)}
      </div>

      {tab === 0 && <>
        <Card title="Your MCP server">
          <div className="flex flex-wrap items-center gap-2"><code className="flex-1 min-w-0 break-all text-sm">{url}</code>{url && <CopyButton value={url} />}</div>
          <p className="m-0 mt-2 text-xs text-muted">Tools: {((w.tools as R[]) ?? []).map((t) => String(t.name)).join(', ')}. They only see the apps in this account.</p>
        </Card>
        <Card title="Access tokens">
          {created && <div role="status" className="mb-3 rounded-md border border-line p-3 text-sm"><div className="font-semibold mb-1">Copy this token now. It is shown only once.</div><div className="flex flex-wrap gap-2 items-center"><code className="flex-1 break-all text-xs">{created}</code><CopyButton value={created} /></div></div>}
          <div className="flex flex-wrap gap-2 items-center mb-3">
            <input className="input flex-1 min-w-[160px]" aria-label="Token name" placeholder="e.g. Cursor on my laptop" value={tok.name} onChange={(e) => setTok({ ...tok, name: e.target.value })} />
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={tok.write} onChange={(e) => setTok({ ...tok, write: e.target.checked })} /> Allow changes</label>
            <button type="button" className="btn btn-primary" disabled={busy || tok.name.trim().length < 2} onClick={() => run<R>({ path: '/app/ai/tokens', body: { name: tok.name.trim(), write: tok.write }, label: 'Access token', success: 'Token created' }).then((r) => { setCreated(String(((r as R)?.data as R | undefined)?.token ?? '')); setTok({ name: '', write: false }); refetch() }).catch(() => {})}>+ Access token</button>
          </div>
          {((w.tokens as R[]) ?? []).length === 0 ? <p className="m-0 text-sm text-muted">No AI clients connected yet.</p> : (
            <ul className="list-none m-0 p-0 flex flex-col">{((w.tokens as R[]) ?? []).map((t) => (
              <li key={String(t.id)} className="flex flex-wrap items-center gap-3 py-2 border-t border-line first:border-t-0 text-sm">
                <span className="flex-1 font-semibold">{String(t.name)}</span>
                <Badge tone={t.write ? 'y' : 'n'}>{t.write ? 'Can change' : 'Read-only'}</Badge>
                <span className="text-xs text-muted">{t.last_used_at ? `Used ${ago(String(t.last_used_at))}` : 'Never used'}</span>
                <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => run({ path: `/app/ai/tokens/${t.id}`, method: 'DELETE', label: 'Revoke token', success: 'Token revoked' }).then(refetch).catch(() => {})}>Revoke</button>
              </li>
            ))}</ul>
          )}
        </Card>
        <Card title="Set up your AI client">
          <div className="flex flex-wrap gap-1 mb-3">{snippets.map(([n], i) => <button key={n} type="button" aria-pressed={client === i} className={`btn btn-sm ${client === i ? 'btn-primary' : ''}`} onClick={() => setClient(i)}>{n}</button>)}</div>
          <pre className="m-0 p-3 rounded-md bg-head text-xs overflow-x-auto whitespace-pre-wrap break-all">{snippets[client][1]}</pre>
          <p className="m-0 mt-2 text-xs text-muted">{snippets[client][2]}</p>
        </Card>
      </>}

      {tab === 1 && <Card title="Activity">
        {((w.activity as R[]) ?? []).length === 0 ? <p className="m-0 text-sm text-muted">No AI activity yet.</p> : (
          <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="text-left text-xs text-muted"><th className="py-1 pr-3">When</th><th className="pr-3">Client</th><th className="pr-3">Tool</th><th className="pr-3">Target</th><th>Result</th></tr></thead>
            <tbody>{((w.activity as R[]) ?? []).map((a) => (
              <tr key={String(a.id)} className="border-t border-line"><td className="py-1.5 pr-3 whitespace-nowrap text-muted">{ago(String(a.created_at))}</td><td className="pr-3">{String(a.client)}</td><td className="pr-3 font-mono text-xs">{String(a.tool)}</td><td className="pr-3">{String(a.target ?? '—')}</td>
                <td><Badge tone={STATUS[String(a.status)] ?? 'n'}>{String(a.status)}</Badge>{a.error ? <span className="block text-xs text-muted">{String(a.error)}</span> : null}</td></tr>
            ))}</tbody></table></div>
        )}
      </Card>}

      {tab === 2 && <>
        <Card title="What AI may do">
          <fieldset className="m-0 p-0 border-0 flex flex-col gap-2"><legend className="sr-only">AI level</legend>
            {((w.levels as string[]) ?? Object.keys(LEVEL)).map((l) => (
              <label key={l} className="flex items-start gap-2 text-sm cursor-pointer"><input type="radio" name="ai-level" checked={s.level === l} disabled={busy} onChange={() => save({ level: l })} />
                <span><span className="font-semibold">{LEVEL[l]?.[0] ?? l}</span><span className="block text-xs text-muted">{LEVEL[l]?.[1]}</span></span></label>
            ))}
          </fieldset>
        </Card>
        <Card title="Safeguards">
          {([['assistant', 'Built-in assistant', 'Let people in this account chat with the assistant. MCP clients are not affected.'], ['confirm_destructive', 'Always confirm destructive actions', 'Deleting or restoring needs you to type the name, even when AI asks.'], ['hide_secrets', 'Never send secret values to AI', 'Variables marked secret are never shown to the model.'], ['audit', 'Keep an audit log', 'Every AI and MCP tool call is logged with who, what and the result.']] as const).map(([k, t, d]) => (
            <div key={k} className="flex items-center gap-3 py-2 border-t border-line first:border-t-0 text-sm"><span className="flex-1"><span className="font-semibold">{t}</span><span className="block text-xs text-muted">{d}</span></span><Toggle checked={!!s[k]} label={t} disabled={busy} onChange={(v) => save({ [k]: v })} /></div>
          ))}
        </Card>
      </>}

      {tab === 3 && <Card title="Assistant"><AssistantChat /></Card>}
    </>
  )
}
