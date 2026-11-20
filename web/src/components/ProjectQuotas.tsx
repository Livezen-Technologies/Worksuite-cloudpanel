import { useEffect, useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { Card, Field } from './Card'
import { DataTable, type Column } from './DataTable'
import { type R } from '../lib/util'

/**
 * PWS-D: a project's usage against its quotas (project-wide and per container). Platform staff can
 * edit the limits; owners and everyone else see them. `base` is '' (admin) or '/app' (customer).
 */
export function ProjectQuotas({ projectId, base = '' }: { projectId: string; base?: string }) {
  const q = useOne<R>(`${base}/projects/${projectId}/quotas`)
  const { run, busy } = useAction()
  const rows = (q.item?.quotas ?? []) as R[]
  const canEdit = Boolean(q.item?.can_edit)
  const [draft, setDraft] = useState<Record<string, string>>({})
  useEffect(() => { setDraft(Object.fromEntries(rows.map((r) => [String(r.key), r.limit == null ? '' : String(r.limit)]))) }, [JSON.stringify(rows)]) // eslint-disable-line react-hooks/exhaustive-deps
  const cols: Column<R>[] = [
    { key: 'label', header: 'Resource', value: (r) => String(r.label) },
    { key: 'used', header: 'Used', value: (r) => Number(r.used ?? 0), kind: 'number', align: 'right', render: (r) => r.used == null ? '—' : String(r.used) },
    { key: 'limit', header: 'Limit', value: (r) => Number(r.limit ?? 0), kind: 'number', align: 'right', render: (r) => r.limit == null ? 'No limit' : String(r.limit) },
    { key: 'pct', header: 'Usage', value: (r) => Number(r.pct ?? 0), render: (r) => r.pct == null ? '—'
      : <span className="flex items-center gap-2"><span className="h-1.5 w-20 rounded bg-track overflow-hidden" aria-hidden="true"><span className={`block h-full ${Number(r.pct) >= 100 ? 'bg-danger' : 'bg-accent'}`} style={{ width: `${Math.min(100, Number(r.pct))}%` }} /></span>{String(r.pct)}%</span> },
  ]
  const save = () => run({ method: 'PATCH', path: `${base}/projects/${projectId}/quotas`, body: Object.fromEntries(Object.entries(draft).map(([k, v]) => [k, v === '' ? null : Number(v)])), label: 'Save quotas', success: 'Quotas saved' }).then(() => q.refetch()).catch(() => {})
  return (
    <div className="flex flex-col gap-4">
      <Card pad={false}><DataTable label="Limits & quotas" tableId="project-quotas" layouts={false} rows={rows} loading={q.isLoading} rowKey={(r) => String(r.key)} columns={cols} empty="No quota information." /></Card>
      {canEdit && <Card title="Edit limits" subtitle="Leave a field empty for no limit. Deploys, scaling and new apps, databases or volumes that would go over a limit are refused.">
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
          {rows.map((r) => <Field key={String(r.key)} label={String(r.label)}><input className="input" type="number" min={0} value={draft[String(r.key)] ?? ''} onChange={(e) => setDraft({ ...draft, [String(r.key)]: e.target.value })} /></Field>)}
        </div>
        <div className="mt-3"><button type="button" className="btn btn-primary" disabled={busy} onClick={save}>Save limits</button></div>
      </Card>}
    </div>
  )
}
