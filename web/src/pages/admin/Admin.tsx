import EmailTemplates from './EmailTemplates'
import { useEffect, useState } from 'react'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, Field, PageHeader, Toggle, Empty } from '../../components/Card'
import { Badge, StatusBadge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import { useAuth } from '../../lib/auth'
import { FormModal } from '../../components/FormModal'
import { Tabs } from '../../components/Tabs'
import { RoleEditor } from './RoleEditor'
import { ServiceSettings } from './ServiceSettings'
import { TokenReveal } from '../../components/TokenReveal'
import { ago, daysUntil, unwrap } from '../../lib/format'
import type { R } from '../../lib/util'
import Select from '../../components/Select'

const ROLE_FALLBACK = ['super_admin', 'infrastructure_admin', 'devops_engineer', 'developer', 'support_engineer', 'billing_manager', 'viewer']
const pretty = (s: string) => s.replace(/[_.]/g, ' ').replace(/^\w/, (c) => c.toUpperCase())

const REACH: Record<string, string> = { all: 'Everything', own_org: 'Own organisation', assigned: 'Assigned only', read: 'Read only' }
function grantedRows(d: R | undefined): { label: string; v: string }[] {
  const p = (d?.permissions ?? []) as R[]
  if (!Array.isArray(p) || !p.length || !('granted' in p[0])) return permRows(d)
  return p.filter((x) => x.granted).map((x) => ({ label: String(x.label), v: `${REACH[String(x.constraint)] ?? 'Allowed'}${x.requires_approval ? ' + approval' : ''}` }))
}

function permRows(d: R | undefined): { label: string; v: string }[] {
  if (!d) return []
  const p = d.permissions ?? d.matrix ?? d
  if (Array.isArray(p)) return p.map((x) => (typeof x === 'string' ? { label: pretty(x), v: 'Allowed' } : { label: pretty(String(x.label ?? x.name ?? x.permission)), v: x.allowed === true ? 'Allowed' : x.allowed === false ? 'No' : String(x.allowed ?? x.value ?? 'Allowed') }))
  return Object.entries(p as R).filter(([k]) => k !== 'name').map(([k, v]) => ({ label: pretty(k), v: v === true ? 'Allowed' : v === false ? 'No' : String(v) }))
}

export function Users() {
  const users = useList<R>('/users')
  const roles = useList<R | string>('/roles')
  const roleNames = roles.rows.length ? roles.rows.map((r) => (typeof r === 'string' ? r : String(r.name))) : ROLE_FALLBACK
  const [sel, setSel] = useState<string | null>(null)
  const role = sel ?? (users.rows[0]?.role as string | undefined) ?? roleNames[0]
  const matrix = useOne<R>(role ? `/roles/${encodeURIComponent(role)}` : null)
  const { run, busy } = useAction()
  const [invite, setInvite] = useState(false)
  const [remove, setRemove] = useState<R | null>(null)
  const [manage, setManage] = useState<R | null>(null)
  const [q, setQ] = useState('')
  const [editor, setEditor] = useState<{ mode: 'new' | 'edit' | 'copy'; from: string | null } | null>(null)
  const [delRole, setDelRole] = useState<string | null>(null)
  const roleRows = roles.rows.length ? roles.rows.map((r) => typeof r === 'string' ? { name: r, label: pretty(r), custom: false, members: 0 } : { name: String(r.name), label: String(r.label ?? pretty(String(r.name))), custom: !!r.custom, members: Number(r.members ?? 0) }) : roleNames.map((n) => ({ name: n, label: pretty(n), custom: false, members: 0 }))
  const roleLabel = (n: string) => roleRows.find((x) => x.name === n)?.label ?? pretty(n)
  const { me } = useAuth()
  const shown = users.rows.filter((r) => !q || `${r.name} ${r.email} ${r.role}`.toLowerCase().includes(q.toLowerCase()))
  const act = (r: R, path: string, label: string, success: string, body?: R) => run({ path: `/users/${r.id}/${path}`, body: body ?? {}, label, success }).then(() => { users.refetch(); setManage(null) }).catch(() => {})
  return (
    <>
      <PageHeader title="Users & Teams" subtitle="Organisation-scoped roles with MFA, passkeys and approval workflows"
        actions={<button type="button" className="btn btn-primary" onClick={() => setInvite(true)}>Invite user</button>} />
      <div className="flex flex-wrap gap-4 items-start">
        <div className="flex-[2_1_520px] min-w-0">
          <input className="input mb-3" type="search" aria-label="Search members" placeholder="Search by name, email or role" value={q} onChange={(e) => setQ(e.target.value)} />
          <DataTable label="Members" rows={shown} loading={users.isLoading} rowKey={(r) => r.id} onRowClick={(r) => setSel(r.role)} minWidth={720}
            actions={(r) => r.id === me?.user?.id
            ? [{ label: 'Manage your own account', icon: 'user', onClick: () => { location.href = '/admin/account' } }]
            : [{ label: `Manage ${r.name}`, icon: 'adjust', onClick: () => setManage(r) }]}
            columns={[
              { key: 'name', header: 'Member', render: (r) => <div className="flex items-center gap-2.5"><span aria-hidden="true" className="w-8 h-8 rounded-full bg-sel text-accent-fg text-xs font-bold flex items-center justify-center flex-none">{String(r.name ?? '?').split(' ').map((x: string) => x[0]).join('').slice(0, 2)}</span><div className="min-w-0"><div className="font-semibold">{r.name}</div><div className="text-xs text-muted">{r.email}</div></div></div> },
              { key: 'role', header: 'Role', render: (r) => r.id === me?.user?.id ? <Badge tone="n">{roleLabel(String(r.role))}</Badge> : (
                <Select className="input !w-auto !min-h-8 text-xs" aria-label={`Role for ${r.name}`} value={r.role} onClick={(e) => e.stopPropagation()}
                  onChange={(e) => run({ method: 'PATCH', path: `/users/${r.id}`, body: { role: e.target.value }, label: 'Change role', success: `${r.name} is now ${pretty(e.target.value)}` }).catch(() => {})}>
                  {[...new Set([r.role, ...roleNames])].map((x) => <option key={x} value={x}>{roleLabel(String(x))}</option>)}
                </Select>) },
              { key: 'auth', header: 'Sign-in', value: (r) => signIn(r) },
              { key: 'last', header: 'Last active', value: (r) => r.last_activity_at ?? r.last_login_at ?? '', render: (r) => (r.last_activity_at ?? r.last_login_at ? ago(r.last_activity_at ?? r.last_login_at) : 'never') },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status === 'suspended' ? 'suspended' : r.invited ? 'invited' : (r.status ?? 'active')} /> },
            ]} />
        </div>
        <Card className="flex-[1_1_320px]" title="Roles" subtitle="Who can do what"
          actions={<button type="button" className="btn btn-sm btn-primary" onClick={() => setEditor({ mode: 'new', from: null })}>New role</button>}>
          <ul className="list-none m-0 p-0 -mx-1 mb-4 flex flex-col gap-1" aria-label="Roles">
            {roleRows.map((x) => (
              <li key={x.name}>
                <button type="button" aria-pressed={role === x.name} onClick={() => setSel(x.name)}
                  className={`w-full flex items-center gap-2 rounded-lg px-3 py-2 text-left text-sm border-0 cursor-pointer ${role === x.name ? 'bg-sel text-accent-fg' : 'bg-transparent hover:bg-head'}`}>
                  <span className="flex-1 min-w-0 truncate font-medium">{x.label}</span>
                  {x.custom && <Badge tone="b">custom</Badge>}
                  <span className="text-xs text-muted tabular-nums">{x.members} {x.members === 1 ? 'person' : 'people'}</span>
                </button>
              </li>
            ))}
          </ul>
          <div className="flex items-center justify-between gap-2 border-t border-line pt-3 mb-2">
            <div className="min-w-0"><div className="font-semibold text-sm truncate">{roleLabel(role)}</div>{matrix.item?.description && <div className="text-xs text-muted">{String(matrix.item.description)}</div>}</div>
            <span className="flex gap-1 flex-none">
              <button type="button" className="btn btn-sm" onClick={() => setEditor({ mode: 'copy', from: role })}>Copy</button>
              {matrix.item?.custom && <button type="button" className="btn btn-sm" onClick={() => setEditor({ mode: 'edit', from: role })}>Edit</button>}
              {matrix.item?.custom && <button type="button" className="btn btn-sm btn-ghost text-[var(--r-fg)]" onClick={() => setDelRole(role)}>Delete</button>}
            </span>
          </div>
          {matrix.isLoading ? 'Loading…' : grantedRows(matrix.item).length === 0 ? <Empty>This role has no permissions.</Empty> : (
            <ul className="list-none m-0 p-0">{grantedRows(matrix.item).map((p) => (
              <li key={p.label} className="flex justify-between gap-2 py-2 border-t border-line text-sm first:border-t-0"><span>{p.label}</span><Badge tone={/approval/i.test(p.v) ? 'y' : p.v === 'Read only' ? 'n' : 'g'}>{p.v}</Badge></li>
            ))}</ul>
          )}
        </Card>
      </div>
      <RoleEditor open={!!editor} onClose={() => setEditor(null)} mode={editor?.mode ?? 'new'} from={editor?.from ?? null} onSaved={(n) => { roles.refetch(); setSel(n) }} />
      <ConfirmDialog open={!!delRole} onClose={() => setDelRole(null)} danger title={`Delete ${roleLabel(delRole ?? '')}?`} confirmLabel="Delete role" busy={busy}
        onConfirm={() => run({ method: 'DELETE', path: `/roles/${delRole}`, label: 'Delete role', success: 'Role deleted' }).then(() => { setDelRole(null); setSel(null); roles.refetch() }).catch(() => {})}>
        <p className="m-0">People must have another role before it can be deleted.</p>
      </ConfirmDialog>
      <FormModal open={invite} onClose={() => setInvite(false)} title="Invite user" submitLabel="Send invite" initial={{ role: 'developer' }}
        fields={[{ name: 'email', label: 'Email', type: 'email', required: true }, { name: 'name', label: 'Name' }, { name: 'role', label: 'Role', type: 'select', required: true, options: roleNames.map((r) => ({ value: r, label: roleLabel(r) })) }]}
        onSubmit={(v) => run({ path: '/users/invite', body: v, label: 'Invite', success: `Invite sent to ${v.email}` })} />
      <Modal open={!!manage} onClose={() => setManage(null)} title={`Manage ${manage?.name ?? ''}`}>
        {manage && (
          <div className="flex flex-col gap-4 text-sm">
            <dl className="m-0 grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1.5">
              <dt className="text-muted">Email</dt><dd className="m-0">{manage.email}</dd>
              <dt className="text-muted">Role</dt><dd className="m-0">{pretty(String(manage.role))}</dd>
              <dt className="text-muted">Sign-in</dt><dd className="m-0">{manage.mfa_enabled ? 'Password + two-factor' : 'Password'}</dd>
              <dt className="text-muted">Last active</dt><dd className="m-0">{manage.last_activity_at ?? manage.last_login_at ? ago(manage.last_activity_at ?? manage.last_login_at) : 'never'}</dd>
            </dl>
            <ul className="m-0 p-0 list-none flex flex-col">
              {manage.invited && <MemberAction title="Resend the invitation" text="Sends a new link; the old one stops working." button="Resend" busy={busy} onClick={() => act(manage, 'resend-invite', 'Resend invite', `Invitation sent to ${manage.email}`)} />}
              <MemberAction title="Send a password reset link" text="They get an email with a link that works for one hour." button="Send link" busy={busy} onClick={() => act(manage, 'password-reset', 'Send reset link', `Reset link sent to ${manage.email}`)} />
              {manage.mfa_enabled && <MemberAction title="Reset two-factor" text="Turns it off and signs them out, so they can set it up again (a lost phone)." button="Reset" busy={busy} onClick={() => act(manage, 'mfa-reset', 'Reset two-factor', 'Two-factor reset')} />}
              {manage.status === 'suspended'
                ? <MemberAction title="Reactivate" text="They can sign in to this organisation again." button="Reactivate" busy={busy} onClick={() => act(manage, 'status', 'Reactivate', `${manage.name} is active again`, { status: 'active' })} />
                : <MemberAction title="Suspend" text="Signs them out and blocks sign-in here; nothing is deleted." button="Suspend" danger busy={busy} onClick={() => act(manage, 'status', 'Suspend', `${manage.name} is suspended`, { status: 'suspended' })} />}
              <MemberAction title="Remove from the organisation" text="Takes away access for good. Their audit history is kept." button="Remove…" danger busy={busy} onClick={() => { setRemove(manage); setManage(null) }} />
            </ul>
          </div>
        )}
      </Modal>
      <ConfirmDialog open={!!remove} onClose={() => setRemove(null)} danger confirmText={remove?.email} title={`Remove ${remove?.name}?`} confirmLabel="Remove member" busy={busy}
        onConfirm={() => run({ method: 'DELETE', path: `/users/${remove!.id}`, label: 'Remove member', success: 'Member removed' }).then(() => setRemove(null)).catch(() => {})}>
        <p className="m-0">They lose access to this organisation immediately. Their audit history is kept.</p>
      </ConfirmDialog>
    </>
  )
}

/** "password+mfa" → "Password + 2FA", "passkey" → "Passkey". */
function signIn(r: R): string {
  const m = String(r.sign_in_method ?? 'password').toLowerCase()
  const base = m.split('+')[0]
  const label = base === 'passkey' ? 'Passkey' : base === 'sso' || base === 'oidc' ? 'SSO' : 'Password'
  return r.mfa_enabled || m.includes('mfa') ? `${label} + 2FA` : label
}

function MemberAction({ title, text, button, onClick, busy, danger }: { title: string; text: string; button: string; onClick: () => void; busy: boolean; danger?: boolean }) {
  return (
    <li className="flex flex-wrap items-center gap-3 py-3 border-t border-line">
      <div className="flex-1 min-w-[200px]"><div className="font-semibold">{title}</div><div className="text-xs text-muted">{text}</div></div>
      <button type="button" className={`btn btn-sm ${danger ? 'btn-danger' : ''}`} disabled={busy} onClick={onClick}>{button}</button>
    </li>
  )
}

const POLICIES = [
  ['require_mfa', 'Require MFA for every member', 'Passkeys or TOTP before any session starts'],
  ['require_approval_destructive', 'Approval for destructive production actions', 'Delete, restore and volume removal need a second approver'],
  ['require_production_deploy_approval', 'Approval for production deployments', 'Every production deployment waits for a second approver'],
  ['secure_container_defaults', 'Secure container defaults', 'Non-root user, dropped capabilities, no-new-privileges'],
  ['mandatory_backup_encryption', 'Encrypt all remote backups', 'Mandatory — keys held separately from backup storage'],
  ['allow_host_network', 'Allow host networking', 'Only for approved system containers'],
  ['block_critical_cves', 'Block images with critical CVEs', 'Deployments fail if the scan finds a critical issue'],
  ['allow_builds_on_deploy_targets', 'Allow builds on deploy targets', 'Only on servers with the builder role; otherwise builds run on separate builder nodes'],
] as const

function SettingsForm({ tab }: { tab: 'general' | 'security' | 'smtp' }) {
  const { item, isLoading } = useOne<R>('/settings')
  const { run, busy } = useAction()
  const [g, setG] = useState<R>({})
  const [testTo, setTestTo] = useState('')
  useEffect(() => { if (item) setG({ ...(item.general ?? item), ...(item.security ?? {}), ...(item.smtp ?? {}) }) }, [item])
  if (isLoading) return <Card>Loading…</Card>
  const nested = !!item?.general || !!item?.security || !!item?.smtp
  const save = (keys: string[], section: 'general' | 'security' | 'smtp') => {
    // API shape: {name, general:{default_region, audit_retention_days, heartbeat_interval, refresh_interval}, security:{<policy>: bool}}
    const vals = Object.fromEntries(keys.filter((k) => k !== 'name')
        .filter((k) => !(section === 'smtp' && k === 'password' && !g[k]))
        .map((k) => [k, g[k]]))
    const body = nested ? { [section]: vals, ...(keys.includes('name') ? { name: g.name } : {}) } : { ...vals, ...(keys.includes('name') ? { name: g.name } : {}) }
    return run({ method: 'PATCH', path: '/settings', body, label: 'Save settings', success: 'Settings saved' }).catch(() => {})
  }
  const SMTP: [string, string, string][] = [['host', 'SMTP host', 'text'], ['port', 'Port', 'number'], ['username', 'Username', 'text'], ['password', 'Password', 'password'], ['encryption', 'Encryption', 'encryption'], ['from_address', 'From address', 'email'], ['from_name', 'From name', 'text']]
  const GEN: [string, string, string][] = [['name', 'Organisation name', 'text'], ['panel_url', 'Panel URL', 'url'], ['default_region', 'Default region', 'region'], ['audit_retention_days', 'Audit retention (days)', 'number'], ['heartbeat_interval', 'Agent heartbeat (seconds)', 'number'], ['refresh_interval', 'Dashboard refresh (seconds)', 'number']]
  return (
    <>
      {tab === 'general' && (
        <Card>
          <form className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]" onSubmit={(e) => { e.preventDefault(); save(GEN.map((x) => x[0]), 'general') }}>
            {GEN.map(([k, l, t]) => (
              <Field key={k} label={l}>
                {t === 'region' ? <Select className="input" value={g[k] ?? ''} onChange={(e) => setG({ ...g, [k]: e.target.value })}>{['eu-central', 'ap-southeast', 'us-east'].map((r) => <option key={r}>{r}</option>)}</Select>
                  : <input className="input" type={t} value={g[k] ?? ''} onChange={(e) => setG({ ...g, [k]: t === 'number' ? Number(e.target.value) : e.target.value })} />}
              </Field>
            ))}
            <div className="col-span-full flex justify-end gap-2"><button type="button" className="btn" onClick={() => setG({ ...(item?.general ?? item), ...(item?.security ?? {}) })}>Cancel</button><button className="btn btn-primary" disabled={busy}>Save changes</button></div>
          </form>
        </Card>
      )}
      {tab === 'smtp' && (
        <Card>
          <p className="m-0 mb-3 text-sm text-muted">
            Outgoing mail for invitations, alerts and invoices. The password is stored encrypted and never shown again.
            {g.password_set ? ' A password is currently set.' : ' No password is set.'}
          </p>
          <form className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]"
                onSubmit={(e) => { e.preventDefault(); save(SMTP.map((x) => x[0]), 'smtp') }}>
            {SMTP.map(([k, l, t]) => (
              <Field key={k} label={l} hint={k === 'password' && g.password_set ? 'Leave blank to keep the current password.'
                : k === 'encryption' ? 'Port 587 always uses STARTTLS and 465 always uses SSL/TLS.' : undefined}>
                {t === 'encryption'
                  ? <Select className="input" value={g[k] ?? 'tls'} onChange={(e) => setG({ ...g, [k]: e.target.value })}>
                      <option value="tls">STARTTLS (port 587)</option><option value="ssl">SSL/TLS (port 465)</option><option value="none">None</option>
                    </Select>
                  : <input className="input" type={t} value={t === 'password' ? (g[k] ?? '') : (g[k] ?? '')}
                           autoComplete={t === 'password' ? 'new-password' : undefined}
                           placeholder={k === 'password' && g.password_set ? '••••••••' : undefined}
                           onChange={(e) => setG({ ...g, [k]: t === 'number' ? Number(e.target.value) : e.target.value })} />}
              </Field>
            ))}
            <div className="col-span-full flex justify-end gap-2">
              <button className="btn btn-primary" disabled={busy}>Save changes</button>
            </div>
          </form>
          <div className="mt-4 pt-4 border-t border-[var(--line)]">
            <label htmlFor="smtp-test-to" className="block text-sm font-medium mb-1.5">Send a test email to</label>
            <div className="flex flex-wrap items-center gap-2 max-w-2xl">
              {/* A one-off recipient, not a login: keep password managers out of it. */}
              <input id="smtp-test-to" className="input flex-1 min-w-[220px]" type="email" inputMode="email" autoComplete="off" data-1p-ignore data-lpignore="true" data-bwignore
                     placeholder="you@example.com" aria-describedby="smtp-test-hint" value={testTo} onChange={(e) => setTestTo(e.target.value)} />
              <button type="button" className="btn flex-none" disabled={busy || !g.host}
                      onClick={() => run({ path: '/settings/smtp/test', body: testTo ? { to: testTo } : {}, label: 'Send test email', success: `Test email sent to ${testTo || 'you'}` }).catch(() => {})}>Send test email</button>
            </div>
            <p id="smtp-test-hint" className="m-0 mt-1.5 text-xs text-muted">{g.host ? 'Uses the saved settings, so save your changes first. Leave it empty to send it to yourself.' : 'Save an SMTP host first.'}</p>
          </div>
        </Card>
      )}
      {tab === 'security' && (
        <Card>
          <ul className="list-none m-0 p-0">
            {POLICIES.map(([k, l, s]) => (
              <li key={k} className="flex items-center gap-4 py-3.5 border-t border-line first:border-t-0">
                <div className="flex-1"><div className="font-semibold text-sm">{l}</div><div className="text-xs text-muted">{s}</div></div>
                <Toggle checked={k === 'mandatory_backup_encryption' ? true : !!g[k]} disabled={k === 'mandatory_backup_encryption'} label={l} onChange={(v) => setG({ ...g, [k]: v })} />
              </li>
            ))}
          </ul>
          <div className="flex justify-end gap-2 mt-3"><button type="button" className="btn btn-primary" disabled={busy} onClick={() => save(POLICIES.map((p) => p[0]), 'security')}>Save changes</button></div>
        </Card>
      )}
    </>
  )
}

function CrudCards({ path, noun, fields, describe }: { path: string; noun: string; fields: Parameters<typeof FormModal>[0]['fields']; describe: (r: R) => { name: string; sub: string; state: string } }) {
  const { rows, isLoading } = useList<R>(path)
  const { run } = useAction()
  const [edit, setEdit] = useState<R | null>(null)
  const [del, setDel] = useState<R | null>(null)
  return (
    <Card title={`${noun[0].toUpperCase()}${noun.slice(1)}s`} actions={<button type="button" className="btn btn-sm btn-primary" onClick={() => setEdit({})}>Add {noun}</button>}>
      {isLoading ? 'Loading…' : rows.length === 0 ? <Empty>Nothing set up yet.</Empty> : (
        <ul className="list-none m-0 p-0">{rows.map((r) => { const d = describe(r); return (
          <li key={r.id} className="flex flex-wrap items-center gap-3 py-3 border-t border-line first:border-t-0">
            <div className="flex-1 min-w-[160px]"><div className="font-semibold text-sm">{d.name}</div><div className="text-xs text-muted">{d.sub}</div></div>
            <StatusBadge status={d.state} />
            <button type="button" className="btn btn-sm" onClick={() => setEdit(r)}>Edit</button>
            <button type="button" className="btn btn-sm btn-danger" onClick={() => setDel(r)}>Remove</button>
          </li>) })}</ul>
      )}
      <FormModal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? `Edit ${noun}` : `Add ${noun}`} fields={fields} initial={edit ?? {}} submitLabel="Save"
        onSubmit={(v) => run({ method: edit?.id ? 'PATCH' : 'POST', path: edit?.id ? `${path}/${edit.id}` : path, body: v, label: `Save ${noun}`, success: `${noun} saved` })} />
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} danger title={`Remove ${del ? describe(del).name : ''}?`} confirmLabel="Remove"
        onConfirm={() => run({ method: 'DELETE', path: `${path}/${del!.id}`, label: `Remove ${noun}`, success: 'Removed' }).then(() => setDel(null)).catch(() => {})}>
        <p className="m-0">This {noun} stops working immediately.</p>
      </ConfirmDialog>
    </Card>
  )
}

export function ApiTokens({ path, abilities }: { path: string; abilities: string[] }) {
  const { rows, isLoading } = useList<R>(path)
  const { run } = useAction()
  const [create, setCreate] = useState(false)
  const [token, setToken] = useState<string | null>(null)
  const [revoke, setRevoke] = useState<R | null>(null)
  return (
    <Card title="API tokens" actions={<button type="button" className="btn btn-sm btn-primary" onClick={() => setCreate(true)}>Create token</button>}>
      {isLoading ? 'Loading…' : rows.length === 0 ? <Empty>No tokens yet.</Empty> : (
        <ul className="list-none m-0 p-0">{rows.map((t) => {
          const d = daysUntil(t.expires_at)
          return (
            <li key={t.id} className="flex flex-wrap items-center gap-3 py-3 border-t border-line first:border-t-0 text-sm">
              <span className="font-semibold flex-1 min-w-[140px]">{t.name}</span>
              <span className="font-mono text-xs text-muted">{t.prefix ?? t.token_prefix ?? ''}…</span>
              <span className="text-xs">{(t.abilities ?? []).join(', ') || '*'}</span>
              <span className="text-xs text-muted">{d === null ? 'no expiry' : d < 0 ? 'expired' : `expires in ${d} days`}</span>
              <button type="button" className="btn btn-sm btn-danger" onClick={() => setRevoke(t)}>Revoke</button>
            </li>)
        })}</ul>
      )}
      <FormModal open={create} onClose={() => setCreate(false)} title="Create API token" submitLabel="Create token" initial={{ expires_in_days: 90 }}
        fields={[{ name: 'name', label: 'Name', required: true, placeholder: 'GitHub Actions deploy' }, { name: 'expires_in_days', label: 'Expires in (days)', type: 'number', hint: 'Leave empty for no expiry' }, ...abilities.map((a) => ({ name: `ab_${a}`, label: a, type: 'checkbox' as const }))]}
        onSubmit={async (v) => {
          const ab = Object.keys(v).filter((k) => k.startsWith('ab_') && v[k]).map((k) => k.slice(3))
          const days = Number(v.expires_in_days)
          const res = await run<R>({ path, body: { name: v.name, abilities: ab, expires_at: days ? new Date(Date.now() + days * 86400e3).toISOString() : null }, label: 'Create token', silent: true })
          const d = unwrap<R>(res)
          setToken(String(d.token ?? d.plain_text_token ?? d.plainTextToken ?? ''))
        }} />
      <TokenReveal token={token} onClose={() => setToken(null)} />
      <ConfirmDialog open={!!revoke} onClose={() => setRevoke(null)} danger title={`Revoke ${revoke?.name}?`} confirmLabel="Revoke token"
        onConfirm={() => run({ method: 'DELETE', path: `${path}/${revoke!.id}`, label: 'Revoke token', success: 'Token revoked' }).then(() => setRevoke(null)).catch(() => {})}>
        <p className="m-0">Anything using this token stops working immediately.</p>
      </ConfirmDialog>
    </Card>
  )
}

type STab = 'general' | 'security' | 'smtp' | 'emails' | 'notifications' | 'providers' | 'tokens'
export function Settings() {
  const [tab, setTab] = useState<STab>('general')
  return (
    <>
      <PageHeader title="Settings" subtitle="Organisation, security, notifications and provider integrations" />
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'general', label: 'General' }, { id: 'security', label: 'Security' }, { id: 'smtp', label: 'SMTP' }, { id: 'emails', label: 'Email templates' }, { id: 'notifications', label: 'Notifications' }, { id: 'providers', label: 'Third-party services' }, { id: 'tokens', label: 'API tokens' }]} />
      {(tab === 'general' || tab === 'security' || tab === 'smtp') && <SettingsForm tab={tab} />}
      {tab === 'emails' && <EmailTemplates />}
      {tab === 'notifications' && <CrudCards path="/notification-channels" noun="channel"
        fields={[{ name: 'name', label: 'Name', required: true }, { name: 'type', label: 'Type', type: 'select', required: true, options: ['email', 'slack', 'teams', 'telegram', 'webhook'] }, { name: 'target', label: 'Address, channel or URL', mono: true, required: true }, { name: 'secret', label: 'Token / signing secret', type: 'password', hint: 'Write-only' }, { name: 'enabled', label: 'Enabled', type: 'checkbox' }]}
        describe={(r) => ({ name: r.name ?? r.type, sub: `${r.type} · ${r.target ?? r.config?.target ?? ''}`, state: r.enabled === false ? 'off' : 'connected' })} />}
      {tab === 'providers' && <ServiceSettings />}
      {tab === 'tokens' && <ApiTokens path="/api-tokens" abilities={['deployments:write', 'servers:read', 'containers:write', 'metrics:read', 'billing:read', 'audit:read']} />}
    </>
  )
}
