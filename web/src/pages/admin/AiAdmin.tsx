import { useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge, type Tone } from '../../components/Badge'
import { CopyButton } from '../../components/CopyButton'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

const STATUS: Record<string, Tone> = { done: 'g', pending: 'y', failed: 'r', refused: 'r', rejected: 'n' }

/** UAI admin: how AI is set up on the platform, and what AI clients have done. */
export default function AiAdmin() {
  const page = useOne<R>('/ai', undefined, { refetchInterval: 30000 })
  const w = page.item ?? {}
  const a = (w.assistant ?? {}) as R
  const d = (w.last_24h ?? {}) as R
  return (
    <>
      <PageHeader title="AI & MCP" subtitle="The built-in assistant, the MCP server, and every tool call AI has made." />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
        {([['Tool calls (24 h)', d.tool_calls], ['Changes (24 h)', d.changes], ['Waiting for approval', d.pending], ['Refused (24 h)', d.refused], ['Conversations (24 h)', d.conversations]] as const).map(([k, v]) => (
          <Card key={k}><div className="text-xs text-muted">{k}</div><div className="text-2xl font-bold">{Number(v ?? 0)}</div></Card>
        ))}
      </div>
      <Card title="Built-in assistant">
        <div className="flex flex-wrap items-center gap-2 text-sm mb-2"><Badge tone={a.available ? 'g' : 'y'}>{a.available ? 'Available' : 'Not set up'}</Badge>
          <span className="text-muted">Model <code>{String(a.model ?? '')}</code> · effort {String(a.effort ?? '')} · up to {Number(a.max_turns_per_day ?? 0)} messages per account per day</span></div>
        {!a.available && <p className="m-0 text-sm text-muted">Add <code>ANTHROPIC_API_KEY</code> to the environment settings to turn the assistant on. <code>AI_MODEL</code>, <code>AI_EFFORT</code> and <code>AI_MAX_TURNS_PER_DAY</code> are optional.</p>}
        <p className="m-0 mt-2 text-xs text-muted">Each account chooses what AI may do (read-only, ask before changes, autonomous) and can turn the assistant off, under AI assistant & MCP in its panel.</p>
      </Card>
      <Card title="MCP server">
        <div className="flex flex-wrap items-center gap-2"><code className="break-all text-sm">{String(w.mcp_url ?? '')}</code>{w.mcp_url ? <CopyButton value={String(w.mcp_url)} /> : null}</div>
        <p className="m-0 mt-2 text-xs text-muted">Customers create scoped access tokens for their AI clients in their panel.</p>
      </Card>
      <Card title="Recent AI activity">
        {((w.recent as R[]) ?? []).length === 0 ? <p className="m-0 text-sm text-muted">No AI activity yet.</p> : (
          <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="text-left text-xs text-muted"><th className="py-1 pr-3">When</th><th className="pr-3">Client</th><th className="pr-3">Tool</th><th className="pr-3">Target</th><th>Result</th></tr></thead>
            <tbody>{((w.recent as R[]) ?? []).map((x) => (
              <tr key={String(x.id)} className="border-t border-line"><td className="py-1.5 pr-3 text-muted whitespace-nowrap">{ago(String(x.created_at))}</td><td className="pr-3">{String(x.client)}</td><td className="pr-3 font-mono text-xs">{String(x.tool)}</td><td className="pr-3">{String(x.target ?? '—')}</td>
                <td><Badge tone={STATUS[String(x.status)] ?? 'n'}>{String(x.status)}</Badge>{x.error ? <span className="ml-2 text-xs text-muted">{String(x.error)}</span> : null}</td></tr>
            ))}</tbody></table></div>
        )}
      </Card>
    </>
  )
}
