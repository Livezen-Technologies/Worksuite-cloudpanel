import { useState } from 'react'
import { Link } from 'react-router'
import { useAction, useList } from '../../api/hooks'
import { PageHeader, StatTile } from '../../components/Card'
import { Badge, StatusBadge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { Modal } from '../../components/Overlay'
import { WizardChecks } from '../../components/WizardShell'
import { ago, unwrap } from '../../lib/format'
import type { R } from '../../lib/util'

/** PDNS-2: every customer's project domains, for staff. */
export function ProjectDomains() {
  const { rows, isLoading } = useList<R>('/project-domains')
  const { run } = useAction()
  const [result, setResult] = useState<{ domain: R; data: R } | null>(null)
  const validate = (d: R) => run<R>({ path: `/project-domains/${d.id}/validate`, label: `Validate ${String(d.name)}`, silent: true })
    .then((r) => setResult({ domain: d, data: unwrap<R>(r) })).catch(() => {})
  const checks = ((result?.data.checks as R[]) ?? [])
  return (
    <>
      <PageHeader title="Project domains" subtitle="Domains customers connected to their projects: ownership, DNS, propagation and SSL, across every account" />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
        <StatTile label="Project domains" value={rows.length} />
        <StatTile label="Active" value={rows.filter((d) => d.status === 'active').length} />
        <StatTile label="Awaiting ownership or DNS" value={rows.filter((d) => String(d.status).startsWith('awaiting')).length} />
        <StatTile label="SSL failed" value={rows.filter((d) => d.status === 'SSL failed').length} deltaTone={rows.some((d) => d.status === 'SSL failed') ? 'r' : 'n'} />
      </div>
      <DataTable label="Project domains" rows={rows} loading={isLoading} rowKey={(r) => String(r.id)} minWidth={960}
        filters={[
          { id: 'active', label: 'Active', test: (d) => d.status === 'active' },
          { id: 'ownership', label: 'Awaiting ownership', test: (d) => d.status === 'awaiting ownership' },
          { id: 'dns', label: 'Awaiting DNS', test: (d) => d.status === 'awaiting DNS' },
          { id: 'ssl', label: 'SSL problems', test: (d) => d.status === 'SSL failed' || d.status === 'issuing SSL' },
        ]}
        actions={(r) => [{ label: `Validate ${String(r.name)}`, icon: 'globe', onClick: () => validate(r) }]}
        columns={[
          { key: 'name', header: 'Domain', value: (r) => String(r.name), render: (r) => <span className="font-mono text-xs font-semibold">{String(r.name)} {r.is_primary ? <Badge tone="b">Primary</Badge> : null}</span> },
          { key: 'customer', header: 'Customer', value: (r) => String(r.organization_name ?? ''), render: (r) => <Link className="link" to={`/admin/customers/${r.organization_id}`}>{String(r.organization_name ?? '—')}</Link> },
          { key: 'project', header: 'Project', value: (r) => String(r.project_name ?? '') },
          { key: 'app', header: 'App', value: (r) => String(r.app_name ?? '—') },
          { key: 'kind', header: 'Kind', value: (r) => r.platform_subdomain ? 'Livezen' : r.subdomain ? 'Subdomain' : 'Apex' },
          { key: 'status', header: 'Status', value: (r) => String(r.status), render: (r) => <StatusBadge status={String(r.status)} /> },
          { key: 'dns', header: 'DNS', render: (r) => <StatusBadge status={String(r.dns_status)} /> },
          { key: 'https', header: 'HTTPS', render: (r) => <StatusBadge status={String(r.https_status ?? 'pending')} /> },
          { key: 'checked', header: 'Last checked', value: (r) => String(r.dns_checked_at ?? ''), render: (r) => r.dns_checked_at ? ago(String(r.dns_checked_at)) : 'never' },
        ]} />
      <Modal open={!!result} onClose={() => setResult(null)} title={`Validation for ${String(result?.domain.name ?? '')}`}>
        {checks.length === 0 ? <p className="m-0 text-sm text-muted">No checks returned.</p> : (
          <WizardChecks label="Validation results" checks={checks.map((c) => ({ key: String(c.key), label: String(c.label), status: c.status === 'skip' ? 'pending' : (c.status as 'pass' | 'warn' | 'fail'), message: [c.message, c.expected ? `Expected: ${String(c.expected)}.` : '', c.actual ? `Found: ${String(c.actual)}.` : ''].filter(Boolean).join(' ') }))} />
        )}
      </Modal>
    </>
  )
}
