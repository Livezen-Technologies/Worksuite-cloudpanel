import { useAction, useOne } from '../api/hooks'
import { Card } from './Card'
import { Badge, StatusBadge } from './Badge'
import { CopyButton } from './CopyButton'
import { DataTable } from './DataTable'
import { type R } from '../lib/util'

/**
 * PWS-F: the project's Docker networks and how its domains reach it — through the central ingress
 * (an app can move between nodes with no DNS change) or pinned to a node's IP. `base` is '' or '/app'.
 */
export function ProjectNetworking({ projectId, base = '' }: { projectId: string; base?: string }) {
  const path = `${base}/projects/${projectId}/networking`
  const q = useOne<R>(path)
  const { run, busy } = useAction()
  const d = q.item ?? {}
  const ingress = (d.ingress ?? {}) as R
  return (
    <div className="flex flex-col gap-4">
      <Card title="Central ingress" subtitle="Point domains here, never at a node: apps can then move between nodes without a DNS change."
        actions={d.can_manage ? <button type="button" className="btn btn-sm" disabled={busy} onClick={() => run({ path: `${path}/reconcile`, label: 'Reconcile networks', success: 'Networks reconciled' }).then(() => q.refetch()).catch(() => {})}>Reconcile networks</button> : undefined}>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          {ingress.hostname ? <><span className="text-muted">CNAME</span><code className="font-mono">{String(ingress.hostname)}</code><CopyButton value={String(ingress.hostname)} /></> : null}
          <span className="text-muted">A</span><code className="font-mono">{((ingress.ips ?? []) as string[]).join(', ')}</code>
        </div>
      </Card>
      <Card pad={false}>
        <DataTable label="Domain routing" tableId="project-net-domains" layouts={false} rows={(d.domains ?? []) as R[]} loading={q.isLoading} rowKey={(r) => String(r.id)}
          columns={[
            { key: 'name', header: 'Domain', value: (r) => String(r.name) },
            { key: 'routing', header: 'Routed', value: (r) => String(r.routing), render: (r) => r.routing === 'ingress' ? <Badge tone="g">via ingress</Badge> : <Badge tone="y">pinned to node</Badge> },
            { key: 'ip', header: 'Points at', value: (r) => String(r.pinned_ip ?? ''), render: (r) => r.pinned_ip ? <code className="font-mono">{String(r.pinned_ip)}</code> : '—' },
            { key: 'dns', header: 'DNS', value: (r) => String(r.dns_status), render: (r) => <StatusBadge status={String(r.dns_status)} /> },
          ]}
          empty="No domains in this project." />
      </Card>
      <Card pad={false}>
        <DataTable label="Application networks" tableId="project-net-apps" layouts={false} rows={(d.apps ?? []) as R[]} rowKey={(r) => String(r.id)}
          columns={[
            { key: 'name', header: 'Application', value: (r) => String(r.name) },
            { key: 'node', header: 'Node', value: (r) => String(r.node ?? '—') },
            { key: 'network', header: 'Network', value: (r) => String(r.network ?? '—') },
            { key: 'note', header: 'Notes', value: (r) => String(r.note ?? ''), render: (r) => r.note ? <span className="text-sm text-muted">{String(r.note)}</span> : <Badge tone="g">ok</Badge> },
          ]}
          empty="No applications yet." />
      </Card>
      <Card pad={false}>
        <DataTable label="Networks" tableId="project-net-networks" layouts={false} rows={(d.networks ?? []) as R[]} rowKey={(r) => String(r.id)}
          columns={[
            { key: 'name', header: 'Network', value: (r) => String(r.name) },
            { key: 'node', header: 'Node', value: (r) => String(r.node ?? '—') },
            { key: 'status', header: 'Status', value: (r) => String(r.status), render: (r) => <StatusBadge status={String(r.status)} /> },
            { key: 'ingress', header: 'Ingress joined', value: (r) => r.ingress_attached ? 'yes' : 'no' },
          ]}
          empty="No project networks yet. Each environment gets one on every node its apps run on." />
      </Card>
    </div>
  )
}
