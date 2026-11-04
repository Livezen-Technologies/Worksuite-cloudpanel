import { useAction, useOne } from '../../api/hooks'
import { Card, PageHeader } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { ago } from '../../lib/format'

type Lead = { id: string; name: string; email: string; company: string | null; budget: string | null; message: string | null; handled_at: string | null; created_at: string }

/** SITE: "Contact sales" requests from the marketing website. */
export default function SalesLeads() {
  const q = useOne<Lead[]>('/sales-leads')
  const { run, busy } = useAction()
  const leads = q.item ?? []
  const mark = (l: Lead, handled: boolean) => run({ path: `/sales-leads/${l.id}/handled`, body: { handled }, label: handled ? `Mark ${l.name} handled` : `Reopen ${l.name}` }).then(() => q.refetch()).catch(() => {})
  return (
    <div className="space-y-4">
      <PageHeader title="Sales leads" subtitle="Messages sent from the Contact sales page on the website." />
      <Card pad={false}>
        {q.isLoading ? <p className="p-5 text-muted">Loading…</p> : !leads.length ? <p className="p-5 text-muted">No messages yet.</p> :
          <ul className="divide-y divide-line">
            {leads.map((l) => <li key={l.id} className="p-4 flex gap-4 items-start">
              <div className="flex-1 min-w-0">
                <div className="font-medium">{l.name} <a className="text-muted text-sm" href={`mailto:${l.email}`}>{l.email}</a></div>
                <div className="text-sm text-muted">{[l.company, l.budget, ago(l.created_at)].filter(Boolean).join(' · ')}</div>
                {l.message && <p className="text-sm mt-2 whitespace-pre-wrap">{l.message}</p>}
              </div>
              {l.handled_at ? <><Badge>Handled</Badge><button className="btn btn-sm" disabled={busy} onClick={() => mark(l, false)}>Reopen</button></>
                : <button className="btn btn-sm" disabled={busy} onClick={() => mark(l, true)}>Mark handled</button>}
            </li>)}
          </ul>}
      </Card>
    </div>
  )
}
