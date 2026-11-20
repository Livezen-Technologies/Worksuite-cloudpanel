import { useQueryClient } from '@tanstack/react-query'
import { AddApplicationModal } from '../../components/AddApplicationModal'
import { useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, Empty, Field, Loading, PageHeader, StatTile } from '../../components/Card'
import { Badge, StatusBadge } from '../../components/Badge'
import { CopyButton } from '../../components/CopyButton'
import { DataTable, type Column } from '../../components/DataTable'
import { Icon } from '../../components/Icon'
import { ConfirmDialog } from '../../components/Overlay'
import { ProjectBackups } from '../../components/ProjectBackups'
import { ProjectCanvas } from '../../components/ProjectCanvas'
import { ProjectDashboard } from '../../components/ProjectDashboard'
import { ProjectDns } from '../../components/ProjectDns'
import { ProjectResources } from '../../components/ProjectResources'
import { ProjectQuotas } from '../../components/ProjectQuotas'
import Select from '../../components/Select'
import { Tabs } from '../../components/Tabs'
import { ago } from '../../lib/format'
import { type R } from '../../lib/util'
import { EnvCard, HandOver, KIND_TONE, summarise, type Project } from './projectParts'

/**
 * PWS slice A: the project workspace. Every section is the panel's EXISTING capability, scoped to
 * this project by id; sections with no project-scoped backend yet say so instead of faking it.
 * Section and sub-page live in the URL (?s=…&sub=…) so links and bookmarks work.
 */
const SECTIONS: { id: string; label: string; subs?: [string, string][] }[] = [
  { id: 'dashboard', label: 'Dashboard' },
  { id: 'applications', label: 'Applications', subs: [['installed', 'Installed'], ['deployments', 'Deployments'], ['catalog', 'Catalog'], ['git', 'GitHub integration']] },
  { id: 'environments', label: 'Environments' },
  { id: 'docker', label: 'Docker', subs: [['containers', 'Services & containers'], ['nodes', 'Nodes & replicas'], ['networks', 'Networks']] },
  { id: 'databases', label: 'Databases' },
  { id: 'storage', label: 'Storage & volumes' },
  { id: 'dns', label: 'DNS & domains' },
  { id: 'firewall', label: 'Firewall & security' },
  { id: 'backups', label: 'Backups & restore' },
  { id: 'variables', label: 'Environment variables' },
  { id: 'monitoring', label: 'Monitoring & logs' },
  { id: 'team', label: 'Team & permissions' },
  { id: 'canvas', label: 'Canvas' },
  { id: 'settings', label: 'Settings' },
]
// ?tab= links from the first version of this page keep working.
const LEGACY: Record<string, string> = { overview: 'dashboard', environments: 'environments', canvas: 'canvas', settings: 'settings' }

type Dash = { health?: string; counts?: Record<string, number>; applications?: R[]; containers?: R[]; nodes?: R[] }
type Ws = { role: string; abilities: string[]; domains: R[]; deployments: R[]; networks: R[]; variables: R[]; activity: R[]; members: R[]; delete_blockers: string[] }

function NotYet({ children, link }: { children: ReactNode; link?: ReactNode }) {
  return <Card><Empty icon="layers" action={link}>{children}</Empty></Card>
}

export default function ProjectDetail() {
  const { id } = useParams()
  const [params, setParams] = useSearchParams()
  const raw = params.get('s') ?? LEGACY[params.get('tab') ?? ''] ?? 'dashboard'
  const def = SECTIONS.find((x) => x.id === raw) ?? SECTIONS[0]
  const section = def.id
  const subs = def.subs ?? null
  const sub = subs ? (subs.find(([k]) => k === params.get('sub'))?.[0] ?? subs[0][0]) : null
  const go = (sec: string, sb?: string) => setParams(sec === 'dashboard' ? {} : sb ? { s: sec, sub: sb } : { s: sec })
  const { item: p, isLoading } = useOne<Project>(id ? `/projects/${id}` : null)
  const dash = useOne<Dash>(id ? `/projects/${id}/dashboard` : null).item ?? {}
  const wsq = useOne<Ws>(id ? `/projects/${id}/workspace` : null)
  const ws = wsq.item
  const [hand, setHand] = useState(false)
  const [adding, setAdding] = useState(false)
  const qc = useQueryClient()
  const back = <nav aria-label="Breadcrumb" className="text-sm"><Link to="/admin/projects" className="link-action">Projects</Link> <span className="text-muted">/ {p?.name ?? '…'}</span></nav>
  if (isLoading) return <><PageHeader title="Project" back={back} /><Loading label="Loading project…" /></>
  if (!p) return <><PageHeader title="Project" back={back} /><Card><Empty icon="grid">Project not found. It may have been deleted or handed over.</Empty></Card></>
  const s = summarise(p)
  const can = (a: string) => !ws || ws.abilities.includes(a)
  const appLink = (a: string) => `/admin/applications/${a}`
  const table = (props: { label: string; rows: R[]; columns: Column<R>[]; empty: string; rowKey?: (r: R) => string; loading?: boolean; actions?: Parameters<typeof DataTable<R>>[0]['actions'] }) => (
    <Card pad={false}><DataTable tableId={`project-${props.label.toLowerCase().replace(/\W+/g, '-')}`} layouts={false} rowKey={props.rowKey ?? ((r) => String(r.id))} {...props} /></Card>
  )
  return (
    <>
      <PageHeader title={p.name} back={back} subtitle={p.description ?? undefined}
        badge={<span className="flex items-center gap-2">{dash.health ? <StatusBadge status={dash.health} /> : s.apps ? <StatusBadge status={s.health} /> : null}{ws && <Badge tone="n">{ws.role.replace('_', ' ')}</Badge>}</span>}
        actions={<>
          {can('deploy') && <button type="button" className="btn btn-primary" onClick={() => setAdding(true)}>Add application</button>}
          <button type="button" className="btn" onClick={() => go('settings')}>Project settings</button>
          <button type="button" className="btn" onClick={() => go('canvas')}>Show canvas</button>
          {can('manage') && <button type="button" className="btn btn-ghost gap-1.5" onClick={() => setHand(true)}><Icon name="users" size={15} />Hand over to customer</button>}
        </>} />
      <AddApplicationModal projectId={String(p.id)} projectName={String(p.name)} open={adding} onClose={() => setAdding(false)} onAdded={() => qc.invalidateQueries()} />
      <Tabs label="Project workspace" value={section} onChange={(v) => go(v)} tabs={SECTIONS.map((x) => ({ id: x.id, label: x.id === 'environments' ? `Environments (${s.envs})` : x.label }))} />
      {subs && sub && <Tabs label={`${def.label} pages`} value={sub} onChange={(v) => go(section, v)} tabs={subs.map(([k, l]) => ({ id: k, label: l }))} />}

      {section === 'dashboard' && <div className="flex flex-col gap-4">
        <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(160px,1fr))]">
          <StatTile label="Applications" value={dash.counts?.applications ?? s.apps} />
          <StatTile label="Containers" value={dash.counts ? `${dash.counts.running}/${dash.counts.containers}` : '…'} />
          <StatTile label="Active nodes" value={dash.counts?.nodes ?? '…'} />
          <StatTile label="Domains" value={ws ? ws.domains.length : '…'} />
          <StatTile label="Last deploy" value={s.last ? ago(s.last) : 'never'} />
          <StatTile label="Owner" value={p.organization?.name ?? '—'} />
        </div>
        <ProjectDashboard projectId={p.id} appLink={appLink} />
        <ProjectQuotas projectId={p.id} />
      </div>}

      {section === 'applications' && sub === 'installed' && table({ label: 'Applications', rows: dash.applications ?? [],
        columns: [
          { key: 'name', header: 'Application', value: (a) => String(a.name), sortable: true, render: (a) => <Link to={appLink(String(a.id))} className="font-semibold">{String(a.name)}</Link> },
          { key: 'status', header: 'Status', value: (a) => String(a.status), render: (a) => <StatusBadge status={String(a.status)} /> },
          { key: 'env', header: 'Environment', value: (a) => String(a.environment ?? '') },
          { key: 'node', header: 'Node', value: (a) => String((a.server as R | null)?.name ?? '—') },
          { key: 'replicas', header: 'Replicas', value: (a) => Number(a.replicas ?? 1), kind: 'number', align: 'right' },
          { key: 'last', header: 'Last deploy', value: (a) => String(a.last_deployed_at ?? ''), render: (a) => a.last_deployed_at ? ago(String(a.last_deployed_at)) : 'never' },
        ],
        empty: 'No applications yet. Add one from the catalogue, a Git repository or a Docker image.' })}
      {section === 'applications' && sub === 'deployments' && table({ label: 'Deployments', rows: ws?.deployments ?? [], loading: wsq.isLoading,
        columns: [
          { key: 'app', header: 'Application', value: (d) => String(d.application ?? '') },
          { key: 'status', header: 'Status', value: (d) => String(d.status), render: (d) => <StatusBadge status={String(d.status)} /> },
          { key: 'when', header: 'Started', value: (d) => String(d.created_at ?? ''), render: (d) => ago(String(d.created_at)) },
          { key: 'open', header: 'Progress', render: (d) => <Link to={`/admin/deployments/${d.id}`} className="link-action">Open</Link> },
        ],
        empty: "No deployments yet. Deployments of this project's apps appear here with their progress and failures." })}
      {section === 'applications' && sub === 'catalog' && <NotYet link={<Link to={`/admin/catalog?project=${p.id}`} className="btn btn-primary">Open catalogue</Link>}>Install from the app catalogue. Pick this project's environment in the install form; the catalogue is shared by every project.</NotYet>}
      {section === 'applications' && sub === 'git' && <Card title="Git webhook" subtitle="Pushes and pull requests of a connected repository deploy this project's environments">
        <div className="flex flex-wrap items-center gap-2"><code className="font-mono text-sm break-all">{p.webhook_url}</code><CopyButton value={p.webhook_url} /></div>
        <p className="text-sm text-muted mb-0">Connections and repositories are managed under <Link to="/admin/git" className="link-action">Git sources</Link>.</p>
      </Card>}

      {section === 'environments' && <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(320px,1fr))]">{p.environments.map((e) => <EnvCard key={e.id} project={p} env={e} />)}</div>}

      {section === 'docker' && sub === 'containers' && table({ label: 'Containers', rows: dash.containers ?? [],
        columns: [
          { key: 'name', header: 'Container', value: (c) => String(c.name), sortable: true },
          { key: 'app', header: 'Application', value: (c) => String(c.application ?? '') },
          { key: 'role', header: 'Role', value: (c) => String(c.role) },
          { key: 'state', header: 'State', value: (c) => String(c.state), render: (c) => <StatusBadge status={String(c.state)} /> },
          { key: 'node', header: 'Node', value: (c) => String(c.server ?? '—') },
          { key: 'image', header: 'Image', value: (c) => String(c.image ?? ''), hidden: true },
        ],
        empty: 'No containers yet. Containers appear once an application is deployed.' })}
      {section === 'docker' && sub === 'nodes' && table({ label: 'Nodes', rows: dash.nodes ?? [],
        columns: [
          { key: 'name', header: 'Node', value: (n) => String(n.name), render: (n) => <Link to={`/admin/servers/${n.id}`} className="font-semibold">{String(n.name)}</Link> },
          { key: 'status', header: 'Status', value: (n) => String(n.status), render: (n) => <StatusBadge status={String(n.status)} /> },
          { key: 'apps', header: 'Apps', value: (n) => Number(n.applications), kind: 'number', align: 'right' },
          { key: 'containers', header: 'Containers', value: (n) => Number(n.containers), kind: 'number', align: 'right' },
          { key: 'cpu', header: 'CPU %', value: (n) => Number(n.cpu_pct ?? 0), kind: 'number', align: 'right' },
          { key: 'ram', header: 'RAM %', value: (n) => Number(n.ram_pct ?? 0), kind: 'number', align: 'right' },
        ],
        empty: "No nodes yet. Nodes are the servers this project's apps are placed on." })}
      {section === 'docker' && sub === 'networks' && table({ label: 'Networks', rows: ws?.networks ?? [], loading: wsq.isLoading,
        columns: [{ key: 'name', header: 'Network', value: (n) => String(n.name) }, { key: 'scope', header: 'Scope', value: (n) => String(n.scope ?? '') }, { key: 'status', header: 'Status', value: (n) => String(n.status ?? ''), render: (n) => <StatusBadge status={String(n.status)} /> }],
        empty: "No project networks yet. Per-project networks are a later slice; apps share the node's networks for now." })}

      {section === 'databases' && <ProjectResources projectId={p.id} kind="databases" canManage={can('manage')} />}
      {section === 'storage' && <ProjectResources projectId={p.id} kind="volumes" canManage={can('manage')} />}

      {section === 'dns' && <div className="flex flex-col gap-4">
        {table({ label: 'Domains', rows: ws?.domains ?? [], loading: wsq.isLoading,
          columns: [{ key: 'name', header: 'Domain', value: (d) => String(d.name) }, { key: 'app', header: 'Application', value: (d) => String(d.application ?? '—') }, { key: 'status', header: 'Status', value: (d) => String(d.status ?? ''), render: (d) => <StatusBadge status={String(d.status)} /> }],
          empty: "No domains yet. Domains of this project's apps appear here." })}
        {/* The existing DNS & Domains UI (records, validation, SSL), mounted unchanged. It calls the customer
            API, so it serves projects of the signed-in organisation; an admin-scoped variant belongs to Project DNS. */}
        <ProjectDns projectId={p.id} />
      </div>}

      {section === 'firewall' && <NotYet link={<Link to="/admin/firewall" className="btn">Open firewall</Link>}>Firewall rules, IP allow/block lists and blocked IPs are per server today, and app lock/unlock is on each application's page. Per-project rules and rate limiting are a later slice.</NotYet>}
      {section === 'backups' && <ProjectBackups projectId={p.id} />}

      {section === 'variables' && <div className="flex flex-col gap-4">
        <Card title="Shared variables per environment" subtitle="Edit them on the environment">
          <ul className="list-none m-0 p-0">{p.environments.map((e) => (
            <li key={e.id} className="flex flex-wrap items-center gap-2 py-2 border-t border-line text-sm first:border-t-0">
              <span className="font-semibold">{e.name}</span><Badge tone={KIND_TONE[e.kind]}>{e.kind}</Badge>
              <span className="font-mono text-xs text-muted">{e.variables.length ? e.variables.join(', ') : 'none'}</span>
            </li>))}</ul>
        </Card>
        {table({ label: 'App variables', rows: ws?.variables ?? [], loading: wsq.isLoading, rowKey: (v) => `${v.application_id}:${v.key}`,
          columns: [{ key: 'key', header: 'Key', value: (v) => String(v.key), render: (v) => <span className="font-mono">{String(v.key)}</span> }, { key: 'secret', header: 'Secret', value: (v) => v.secret ? 'yes' : 'no' }, { key: 'app', header: 'Application', value: (v) => String(v.application ?? ''), render: (v) => <Link to={appLink(String(v.application_id))} className="link-action">{String(v.application ?? '')}</Link> }],
          empty: "No app variables yet. Values stay on each application's page; only the keys are shown here." })}
      </div>}

      {section === 'monitoring' && table({ label: 'Activity', rows: ws?.activity ?? [], loading: wsq.isLoading,
        columns: [{ key: 'when', header: 'When', value: (a) => String(a.created_at), render: (a) => ago(String(a.created_at)) }, { key: 'actor', header: 'Who', value: (a) => String(a.actor) }, { key: 'action', header: 'Action', value: (a) => String(a.action), render: (a) => <span className="font-mono text-xs">{String(a.action)}</span> }],
        empty: "No activity yet. Changes to the project and its apps are audited here; container logs and metrics are on each application's page." })}

      {section === 'team' && <Team p={p} ws={ws} canManage={can('members')} onChange={() => { void wsq.refetch() }} />}
      {section === 'canvas' && <Card><ProjectCanvas projectId={p.id} staff /></Card>}
      {section === 'settings' && <Settings p={p} ws={ws} canManage={can('manage')} onHandOver={() => setHand(true)} />}
      {/* DNS slice 2 (branch claude/project-dns-domains-2) owns ProjectDns; admin DNS pages plug into the "dns" section above. */}
      {hand && <HandOver project={p} onClose={() => setHand(false)} />}
    </>
  )
}

const ROLE_HELP: Record<string, string> = { owner: 'apps, domains, backups, firewall and members', developer: 'deploys approved versions, reads logs', viewer: 'read only' }

function Team({ p, ws, canManage, onChange }: { p: Project; ws?: Ws; canManage: boolean; onChange: () => void }) {
  const users = useList<R>(canManage ? '/users' : null)
  const { run, busy } = useAction()
  const [who, setWho] = useState('')
  const [role, setRole] = useState('developer')
  const set = (userId: string, r: string) => run({ method: 'PATCH', path: `/projects/${p.id}/members/${userId}`, body: { role: r }, label: 'Project role', success: 'Role saved' }).then(onChange).catch(() => {})
  const cols: Column<R>[] = [
    { key: 'name', header: 'Name', value: (m) => String(m.name ?? '') },
    { key: 'email', header: 'Email', value: (m) => String(m.email ?? '') },
    { key: 'role', header: 'Role', value: (m) => String(m.role), render: (m) => canManage
      ? <Select className="input !w-auto" aria-label={`Role of ${String(m.email)}`} value={String(m.role)} onChange={(e) => set(String(m.user_id), e.target.value)}>{Object.keys(ROLE_HELP).map((r) => <option key={r} value={r}>{r}</option>)}</Select>
      : <Badge tone="n">{String(m.role)}</Badge> },
  ]
  return (
    <div className="flex flex-col gap-4">
      <Card title="Project roles" subtitle="Organisation owners and platform staff always have full access. Everyone else needs a role here.">
        <ul className="list-none m-0 p-0 text-sm">{Object.entries(ROLE_HELP).map(([r, h]) => <li key={r} className="py-1"><b className="capitalize">{r}</b> <span className="text-muted">— {h}</span></li>)}</ul>
      </Card>
      <Card pad={false}>
        <DataTable label="Members" tableId="project-members" layouts={false} rows={ws?.members ?? []} rowKey={(m) => String(m.user_id)} columns={cols}
          actions={canManage ? (m) => [{ label: 'Remove from project', icon: 'trash', danger: true, onClick: () => { run({ method: 'DELETE', path: `/projects/${p.id}/members/${String(m.user_id)}`, label: 'Remove member', success: 'Removed' }).then(onChange).catch(() => {}) } }] : undefined}
          empty="No project members yet. Give people of the owning organisation a role to let them work on this project." />
      </Card>
      {canManage && <Card title="Add a member">
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Person"><Select className="input" value={who} onChange={(e) => setWho(e.target.value)}><option value="">Choose…</option>{users.rows.map((u) => <option key={String(u.id)} value={String(u.id)}>{String(u.name)} · {String(u.email)}</option>)}</Select></Field>
          <Field label="Role"><Select className="input" value={role} onChange={(e) => setRole(e.target.value)}>{Object.keys(ROLE_HELP).map((r) => <option key={r} value={r}>{r}</option>)}</Select></Field>
          <button type="button" className="btn btn-primary" disabled={busy || !who} onClick={() => { set(who, role); setWho('') }}>Add</button>
        </div>
      </Card>}
    </div>
  )
}

function Settings({ p, ws, canManage, onHandOver }: { p: Project; ws?: Ws; canManage: boolean; onHandOver: () => void }) {
  const { run, busy } = useAction()
  const nav = useNavigate()
  const [del, setDel] = useState(false)
  const blockers = ws?.delete_blockers ?? []
  return (
    <div className="flex flex-col gap-4">
      <Card title="Git webhook" subtitle="Point pushes and pull request events of the repository here">
        <div className="flex flex-wrap items-center gap-2"><code className="font-mono text-sm break-all">{p.webhook_url}</code><CopyButton value={p.webhook_url} /></div>
      </Card>
      <Card title="Pull request previews">
        <div className="flex flex-col gap-4">
          <Field label="Pull request previews copy" hint="The environment a preview environment is cloned from">
            <Select className="input" value={p.preview_environment_id ?? ''} onChange={(e) => run({ path: `/projects/${p.id}`, method: 'PATCH', body: { preview_environment_id: e.target.value || null }, label: 'Preview source', success: 'Preview source saved' }).catch(() => {})}>
              <option value="">staging (default)</option>{p.environments.filter((e) => e.kind !== 'preview').map((e) => <option key={e.id} value={e.id}>{e.name}</option>)}
            </Select>
          </Field>
          <div className="flex flex-wrap items-center gap-2 text-sm">Preview links on pull requests: <b>{p.preview_comments ? 'commented' : 'off'}</b>
            <button type="button" className="btn btn-sm" onClick={() => {
              const t = window.prompt('Git token that may comment on pull requests (GitHub, Gitea or GitLab). Leave empty to turn comments off.')
              if (t !== null) run({ path: `/projects/${p.id}`, method: 'PATCH', body: { preview_comment_token: t || null }, label: 'Preview comments', success: t ? 'Preview links will be commented on pull requests' : 'Preview comments off' }).catch(() => {})
            }}>{p.preview_comments ? 'Change token' : 'Set token'}</button>
          </div>
        </div>
      </Card>
      <ProjectQuotas projectId={p.id} />
      <Card title="Ownership" subtitle={`Owned by ${p.organization?.name ?? 'this organisation'}`}
        actions={canManage ? <button type="button" className="btn btn-sm" onClick={onHandOver}>Hand over to customer</button> : undefined} />
      {canManage && <Card title="Delete project" subtitle={blockers.length ? `Blocked: move or delete the project's ${blockers.join(' and ')} first.` : 'Removes the project and its environments.'}
        actions={<button type="button" className="btn btn-sm btn-danger" disabled={blockers.length > 0 || !ws} onClick={() => setDel(true)}>Delete project</button>} />}
      <ConfirmDialog open={del} onClose={() => setDel(false)} danger busy={busy} title={`Delete ${p.name}?`} confirmLabel="Delete" confirmText={p.name}
        onConfirm={() => run({ method: 'DELETE', path: `/projects/${p.id}`, label: `Delete ${p.name}`, success: 'Project deleted' }).then(() => nav('/admin/projects')).catch(() => {})}>
        The project and its environments are removed. This cannot be undone.
      </ConfirmDialog>
    </div>
  )
}
