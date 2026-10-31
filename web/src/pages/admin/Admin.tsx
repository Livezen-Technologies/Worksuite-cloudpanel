import EmailTemplates from './EmailTemplates'
import { useEffect, useState } from 'react'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, Field, PageHeader, Toggle, Empty } from '../../components/Card'
import { Badge, StatusBadge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog } from '../../components/Overlay'
import { FormModal } from '../../components/FormModal'
import { Tabs } from '../../components/Tabs'
import { TokenReveal } from '../../components/TokenReveal'
import { ago, daysUntil, unwrap } from '../../lib/format'
import type { R } from '../../lib/util'
import Select from '../../components/Select'

const ROLE_FALLBACK = ['super_admin', 'infrastructure_admin', 'devops_engineer', 'developer', 'support_engineer', 'billing_manager', 'viewer']
const pretty = (s: string) => s.replace(/[_.]/g, ' ').replace(/^\w/, (c) => c.toUpperCase())

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
  return (
    <>
      <PageHeader title="Users & Teams" subtitle="Organisation-scoped roles with MFA, passkeys and approval workflows"
        actions={<button type="button" className="btn btn-primary" onClick={() => setInvite(true)}>Invite user</button>} />
      <div className="flex flex-wrap gap-4 items-start">
        <div className="flex-[2_1_520px] min-w-0">
          <DataTable label="Members" rows={users.rows} loading={users.isLoading} rowKey={(r) => r.id} onRowClick={(r) => setSel(r.role)} minWidth={720}
            columns={[
              { key: 'name', header: 'Member', render: (r) => <div className="flex items-center gap-2.5"><span aria-hidden="true" className="w-8 h-8 rounded-full bg-sel text-accent-fg text-xs font-bold flex items-center justify-center flex-none">{String(r.name ?? '?').split(' ').map((x: string) => x[0]).join('').slice(0, 2)}</span><div className="min-w-0"><div className="font-semibold">{r.name}</div><div className="text-xs text-muted">{r.email}</div></div></div> },
              { key: 'role', header: 'Role', render: (r) => (
                <Select className="input !w-auto !min-h-8 text-xs" aria-label={`Role for ${r.name}`} value={r.role} onClick={(e) => e.stopPropagation()}
                  onChange={(e) => run({ method: 'PATCH', path: `/users/${r.id}`, body: { role: e.target.value }, label: 'Change role', success: `${r.name} is now ${pretty(e.target.value)}` }).catch(() => {})}>
                  {[...new Set([r.role, ...roleNames])].map((x) => <option key={x} value={x}>{pretty(String(x))}</option>)}
                </Select>) },
              { key: 'auth', header: 'Sign-in', value: (r) => r.sign_in_method ?? (r.mfa_enabled ? 'MFA' : 'Password') },
              { key: 'last', header: 'Last active', value: (r) => r.last_activity_at ?? r.last_login_at ?? '', render: (r) => (r.last_activity_at ?? r.last_login_at ? ago(r.last_activity_at ?? r.last_login_at) : 'never') },
              { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status ?? (r.invited ? 'pending' : 'active')} /> },
              { key: 'rm', header: '', sortable: false, render: (r) => <button type="button" className="btn btn-sm btn-danger" onClick={(e) => { e.stopPropagation(); setRemove(r) }}>Remove</button> },
            ]} />
        </div>
        <Card className="flex-[1_1_280px]" title="Permission matrix" actions={<Select className="input !w-auto !min-h-8 text-xs" aria-label="Role" value={role} onChange={(e) => setSel(e.target.value)}>{roleNames.map((x) => <option key={x} value={x}>{pretty(x)}</option>)}</Select>}>
          {matrix.isLoading ? 'Loading…' : permRows(matrix.item).length === 0 ? <Empty>No permissions listed.</Empty> : (
            <ul className="list-none m-0 p-0">{permRows(matrix.item).map((p) => (
              <li key={p.label} className="flex justify-between gap-2 py-2 border-t border-line text-sm first:border-t-0"><span>{p.label}</span><Badge tone={p.v === 'No' ? 'n' : /approval/i.test(p.v) ? 'y' : 'g'}>{p.v}</Badge></li>
            ))}</ul>
          )}
        </Card>
      </div>
      <FormModal open={invite} onClose={() => setInvite(false)} title="Invite user" submitLabel="Send invite" initial={{ role: 'developer' }}
        fields={[{ name: 'email', label: 'Email', type: 'email', required: true }, { name: 'name', label: 'Name' }, { name: 'role', label: 'Role', type: 'select', required: true, options: roleNames.map((r) => ({ value: r, label: pretty(r) })) }]}
        onSubmit={(v) => run({ path: '/users/invite', body: v, label: 'Invite', success: `Invite sent to ${v.email}` })} />
      <ConfirmDialog open={!!remove} onClose={() => setRemove(null)} danger confirmText={remove?.email} title={`Remove ${remove?.name}?`} confirmLabel="Remove member" busy={busy}
        onConfirm={() => run({ method: 'DELETE', path: `/users/${remove!.id}`, label: 'Remove member', success: 'Member removed' }).then(() => setRemove(null)).catch(() => {})}>
        <p className="m-0">They lose access to this organisation immediately. Their audit history is kept.</p>
      </ConfirmDialog>
    </>
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
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'general', label: 'General' }, { id: 'security', label: 'Security' }, { id: 'smtp', label: 'SMTP' }, { id: 'emails', label: 'Email templates' }, { id: 'notifications', label: 'Notifications' }, { id: 'providers', label: 'Providers' }, { id: 'tokens', label: 'API tokens' }]} />
      {(tab === 'general' || tab === 'security' || tab === 'smtp') && <SettingsForm tab={tab} />}
      {tab === 'emails' && <EmailTemplates />}
      {tab === 'notifications' && <CrudCards path="/notification-channels" noun="channel"
        fields={[{ name: 'name', label: 'Name', required: true }, { name: 'type', label: 'Type', type: 'select', required: true, options: ['email', 'slack', 'teams', 'telegram', 'webhook'] }, { name: 'target', label: 'Address, channel or URL', mono: true, required: true }, { name: 'secret', label: 'Token / signing secret', type: 'password', hint: 'Write-only' }, { name: 'enabled', label: 'Enabled', type: 'checkbox' }]}
        describe={(r) => ({ name: r.name ?? r.type, sub: `${r.type} · ${r.target ?? r.config?.target ?? ''}`, state: r.enabled === false ? 'off' : 'connected' })} />}
      {tab === 'providers' && <CrudCards path="/integrations" noun="integration"
        fields={[{ name: 'provider', label: 'Provider', type: 'select', required: true, options: ['hetzner', 'digitalocean', 'vultr', 'aws', 'linode', 'proxmox', 'cloudflare', 'github', 'stripe'] }, { name: 'name', label: 'Name', required: true }, { name: 'api_token', label: 'API token', type: 'password', hint: 'Stored encrypted; write-only' }]}
        describe={(r) => ({ name: r.name ?? r.provider, sub: r.provider ?? '', state: r.status ?? 'connected' })} />}
      {tab === 'tokens' && <ApiTokens path="/api-tokens" abilities={['deployments:write', 'servers:read', 'containers:write', 'metrics:read', 'billing:read', 'audit:read']} />}
    </>
  )
}
