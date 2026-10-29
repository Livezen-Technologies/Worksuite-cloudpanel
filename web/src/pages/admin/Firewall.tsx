import { useMemo, useState } from 'react'
import { useAction, useList, useOne } from '../../api/hooks'
import { Card, Field, PageHeader, StatTile, Toggle, Empty } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog } from '../../components/Overlay'
import { FormModal, type FieldDef } from '../../components/FormModal'
import { Tabs } from '../../components/Tabs'
import Select from '../../components/Select'
import { num, type R } from '../../lib/util'

/** Actions Traefik enforces on its own; the rest need a challenge middleware. */
const ENFORCED = ['block', 'throttle', 'log']

const ACTION_TONE: Record<string, 'r' | 'y' | 'b' | 'n' | 'g'> = {
  block: 'r', throttle: 'y', challenge: 'y', recaptcha: 'b', log: 'n', allow: 'g',
}

const RULE_FIELDS: FieldDef[] = [
  { name: 'name', label: 'Name', required: true, placeholder: 'Login brute force' },
  { name: 'paths_text', label: 'Paths', required: true, mono: true, placeholder: '/login, /wp-login.php', hint: 'Comma-separated. Use * for the whole site, or a trailing * to match a prefix.' },
  { name: 'methods_text', label: 'Methods', mono: true, placeholder: 'POST', hint: 'Comma-separated. Leave empty for any method.' },
  { name: 'limit', label: 'Requests allowed', type: 'number', required: true, placeholder: '5' },
  { name: 'window_seconds', label: 'Per (seconds)', type: 'number', required: true, placeholder: '60', hint: '60 is a minute, 600 is ten minutes, 3600 is an hour.' },
  { name: 'counted_by', label: 'Counted by', type: 'select', options: [
    { value: 'ip', label: 'Client IP' },
    { value: 'token_else_ip', label: 'API token, else IP' },
    { value: 'ip_session', label: 'IP and session' },
  ] },
  { name: 'action', label: 'When exceeded', type: 'select', options: [
    { value: 'block', label: 'Block (429)' },
    { value: 'throttle', label: 'Throttle' },
    { value: 'log', label: 'Log only' },
    { value: 'challenge', label: 'Challenge — needs a challenge provider' },
    { value: 'recaptcha', label: 'reCAPTCHA — needs a challenge provider' },
  ] },
  { name: 'priority', label: 'Priority', type: 'number', hint: 'Lower runs first. 100 if left empty.' },
]

/** The twelve countries the policy editor offers, as the design specifies. */
const COUNTRIES: [string, string][] = [
  ['LK', 'Sri Lanka'], ['US', 'United States'], ['GB', 'United Kingdom'], ['IN', 'India'],
  ['SG', 'Singapore'], ['DE', 'Germany'], ['AU', 'Australia'], ['CN', 'China'],
  ['RU', 'Russia'], ['BR', 'Brazil'], ['KP', 'North Korea'], ['IR', 'Iran'],
]

const CHALLENGE_TYPES: [string, string, string][] = [
  ['managed', 'Managed challenge', 'Invisible browser check first, interactive only if it fails'],
  ['javascript', 'JavaScript challenge', 'A short proof-of-work page'],
  ['recaptcha', 'reCAPTCHA challenge', 'Score check, checkbox when the score is low'],
]

const TRIGGERS: [string, string, string][] = [
  ['trigger_threat_score', 'Threat score is high', 'IP reputation lists and abuse history'],
  ['trigger_bad_bots', 'Known bad bots and scanners', 'User-agent and fingerprint signatures'],
  ['trigger_rate_limit', 'A rate limit is exceeded', 'Rules set to Challenge'],
  ['trigger_anonymiser', 'Visitor uses Tor or a VPN', 'Anonymising networks'],
  ['trigger_country', 'Country set to Challenge', 'From the Country block tab'],
]

const toList = (v: unknown): string[] =>
  String(v ?? '').split(',').map((s) => s.trim()).filter(Boolean)

/**
 * Says plainly that a part of the screen is configurable but not yet enforced,
 * rather than letting the page imply the proxy is acting on it.
 */
function NotEnforced({ what, needs }: { what: string; needs: string }) {
  return (
    <div className="flex gap-3 items-start p-3 rounded-[10px] bg-head border border-line">
      <Badge tone="y">not enforced</Badge>
      <p className="m-0 text-sm text-muted">
        {what} is saved but <strong className="text-fg">not yet applied to traffic</strong>. It needs {needs}, which
        is not deployed. Rate limits on the first tab are enforced.
      </p>
    </div>
  )
}

export default function Firewall() {
  const [tab, setTab] = useState('rules')
  const { item, isLoading, refetch } = useOne<R>('/firewall')
  const rules = useList<R>('/firewall-rules')
  const events = useList<R>('/firewall/events', undefined, { enabled: tab === 'events' })
  const domains = useList<R>('/domains')
  const { run, busy } = useAction()
  const [form, setForm] = useState<R | null>(null)
  const [remove, setRemove] = useState<R | null>(null)
  const [newPath, setNewPath] = useState('')
  // Select is a controlled listbox, so the provider form is held in state
  // rather than read from the DOM with FormData.
  const [prov, setProv] = useState<R | null>(null)

  const settings = (item?.settings ?? {}) as R
  const unsupported = (item?.unsupported ?? []) as R[]
  const paths = (item?.paths ?? []) as R[]
  const countries = useMemo(() => {
    const by: Record<string, string> = {}
    for (const c of (item?.countries ?? []) as R[]) by[String(c.code)] = String(c.state)
    return by
  }, [item])
  const mode = String(settings.country_mode ?? 'block')
  const p = prov ?? {
    provider: String(settings.provider ?? 'turnstile'),
    site_key: String(settings.site_key ?? ''),
    secret_key: '',
    min_score: String(settings.min_score ?? 0.5),
    clearance_minutes: num(settings.clearance_minutes) || 30,
  }
  const setP = (patch: R) => setProv({ ...p, ...patch })

  const domainOptions = [{ value: '', label: 'All domains' }, ...domains.rows.map((d) => ({ value: String(d.id), label: String(d.name) }))]
  const fields: FieldDef[] = [...RULE_FIELDS.slice(0, 1), { name: 'domain_id', label: 'Applies to', type: 'select', options: domainOptions }, ...RULE_FIELDS.slice(1)]

  const saveSetting = (patch: R, success: string) =>
    run({ method: 'PATCH', path: '/settings', body: { firewall: patch }, label: 'Update firewall settings', success })
      .then(() => refetch()).catch(() => {})

  const saveRule = (v: Record<string, unknown>) => {
    const body: R = {
      name: v.name,
      domain_id: v.domain_id || null,
      paths: toList(v.paths_text),
      methods: toList(v.methods_text).map((m) => m.toUpperCase()),
      limit: num(v.limit),
      window_seconds: num(v.window_seconds) || 60,
      counted_by: v.counted_by || 'ip',
      action: v.action || 'block',
      priority: num(v.priority) || 100,
    }
    if (!body.methods.length) body.methods = null
    const editing = form?.id
    return run({
      method: editing ? 'PATCH' : 'POST',
      path: editing ? `/firewall-rules/${editing}` : '/firewall-rules',
      body,
      label: editing ? 'Save rule' : 'Add rule',
      success: editing ? 'Rule saved' : `Rule "${String(v.name)}" added`,
    }).then(() => { rules.refetch(); refetch() })
  }

  const cycleCountry = (code: string) => {
    const next = { ...countries }
    if (mode === 'block') {
      // allowed -> challenge -> blocked -> allowed
      if (!next[code]) next[code] = 'challenge'
      else if (next[code] === 'challenge') next[code] = 'block'
      else delete next[code]
    } else {
      if (next[code]) delete next[code]
      else next[code] = 'block'
    }
    run({
      method: 'PATCH', path: '/firewall/countries',
      body: { countries: Object.entries(next).map(([c, state]) => ({ code: c, state })) },
      label: 'Update country policy', success: 'Country policy saved',
    }).then(() => refetch()).catch(() => {})
  }

  const countryLabel = (code: string) => {
    const s = countries[code]
    if (mode === 'block') return s === 'challenge' ? 'challenge' : s ? 'blocked' : 'allowed'
    return s ? 'allowed' : 'blocked'
  }

  const addPath = () => {
    if (!newPath.trim()) return
    run({ path: '/firewall/paths', body: { path: newPath.trim() }, label: 'Add path', success: `${newPath.trim()} is now protected` })
      .then(() => { setNewPath(''); refetch() }).catch(() => {})
  }

  const blockedCount = Object.values(countries).filter((s) => s === 'block').length
  const challengeCount = Object.values(countries).filter((s) => s === 'challenge').length

  if (isLoading) return <Card>Loading…</Card>

  return (
    <>
      <PageHeader title="Firewall" subtitle="Rate limits, challenges and country rules at the edge"
        actions={tab === 'rules' ? <button type="button" className="btn btn-primary" onClick={() => setForm({})}>Add rule</button> : undefined} />

      <div className="flex flex-wrap gap-4">
        <StatTile label="Rules" value={num(item?.rules_total)} />
        <StatTile label="Enforced" value={num(item?.rules_total) - unsupported.length}
          valueTone={unsupported.length ? 'y' : 'g'} />
        <StatTile label="Countries blocked" value={blockedCount} />
        <StatTile label="Countries challenged" value={challengeCount} />
      </div>

      {unsupported.length > 0 && (
        <div className="mt-4">
          <Card title="Rules that are saved but not enforced">
            <p className="mt-0 text-sm text-muted">
              Traefik enforces rate limits natively. A challenge or reCAPTCHA action needs a middleware that can serve
              a challenge page — CrowdSec or Cloudflare Turnstile at the edge — and none is deployed, so these rules
              currently do nothing:
            </p>
            <ul className="m-0 pl-5 text-sm flex flex-col gap-1">
              {unsupported.map((u) => (
                <li key={String(u.id)}>{String(u.name)} <Badge tone={ACTION_TONE[String(u.action)] ?? 'n'}>{String(u.action)}</Badge></li>
              ))}
            </ul>
          </Card>
        </div>
      )}

      <div className="mt-4">
        <Tabs value={tab} onChange={setTab} label="Firewall sections" tabs={[
          { id: 'rules', label: 'Rate limits' },
          { id: 'challenges', label: 'Challenges' },
          { id: 'countries', label: 'Country block' },
          { id: 'recaptcha', label: 'reCAPTCHA' },
          { id: 'events', label: 'Events' },
        ]} />
      </div>

      {tab === 'rules' && (
        <DataTable label="Rate limit rules" rows={rules.rows} loading={rules.isLoading} rowKey={(r) => String(r.id)} pageSize={25}
          searchKeys={(r) => `${r.name} ${(r.paths as string[] ?? []).join(' ')}`}
          empty={<Empty>No rules yet. Add one to rate-limit a path.</Empty>}
          columns={[
            { key: 'name', header: 'Rule', sortable: true, render: (r) => (
              <div>
                <div className="font-semibold">{String(r.name)}</div>
                <div className="text-xs text-muted">{String(r.scope ?? 'all domains')}</div>
              </div>
            ) },
            { key: 'match', header: 'Match', render: (r) => (
              <span className="font-mono text-xs">
                {((r.methods as string[]) ?? []).join('/')} {((r.paths as string[]) ?? []).join(', ')}
              </span>
            ) },
            { key: 'rate', header: 'Limit', value: (r) => num(r.limit), render: (r) => String(r.rate ?? '—') },
            { key: 'counted_by', header: 'Counted by', render: (r) => ({ ip: 'IP', token_else_ip: 'API token, else IP', ip_session: 'IP + session' }[String(r.counted_by)] ?? String(r.counted_by)) },
            { key: 'action', header: 'Action', render: (r) => (
              <span className="flex items-center gap-2">
                <Badge tone={ACTION_TONE[String(r.action)] ?? 'n'}>{String(r.action)}</Badge>
                {!ENFORCED.includes(String(r.action)) && <Badge tone="y">not enforced</Badge>}
              </span>
            ) },
            { key: 'hits', header: 'Hits', sortable: true, value: (r) => num(r.hits) },
            { key: 'enabled', header: 'On', render: (r) => (
              <Toggle checked={!!r.enabled} label={`Enable ${String(r.name)}`}
                onChange={(v) => run({ method: 'PATCH', path: `/firewall-rules/${r.id}`, body: { enabled: v }, label: 'Toggle rule', success: v ? 'Rule on' : 'Rule off' }).then(() => rules.refetch()).catch(() => {})} />
            ) },
            { key: 'act', header: '', render: (r) => (
              <span className="flex gap-2">
                <button type="button" className="btn btn-sm" onClick={() => setForm({
                  ...r,
                  paths_text: ((r.paths as string[]) ?? []).join(', '),
                  methods_text: ((r.methods as string[]) ?? []).join(', '),
                  domain_id: r.domain_id ?? '',
                })}>Edit</button>
                <button type="button" className="btn btn-sm btn-danger" onClick={() => setRemove(r)}>Delete</button>
              </span>
            ) },
          ]} />
      )}

      {tab === 'challenges' && (
        <div className="flex flex-wrap gap-4 items-start">
          <Card className="flex-[1_1_340px]" title="Challenge type">
            <NotEnforced what="The challenge configuration" needs="CrowdSec or Cloudflare Turnstile at the edge" />
            <div className="mt-3 flex flex-col gap-2" role="radiogroup" aria-label="Challenge type">
              {CHALLENGE_TYPES.map(([id, name, sub]) => (
                <label key={id} className="flex gap-3 items-start py-2 min-h-[44px] cursor-pointer">
                  <input type="radio" name="challenge_type" className="mt-1" checked={settings.challenge_type === id}
                    onChange={() => saveSetting({ challenge_type: id }, 'Challenge type saved')} />
                  <span><span className="block font-semibold text-sm">{name}</span><span className="block text-xs text-muted">{sub}</span></span>
                </label>
              ))}
            </div>
          </Card>
          <Card className="flex-[1_1_340px]" title="When to challenge">
            {TRIGGERS.map(([key, label, sub]) => (
              <div key={key} className="flex items-center gap-3 py-2 border-t border-line first:border-t-0">
                <div className="flex-1"><div className="font-semibold text-sm">{label}</div><div className="text-xs text-muted">{sub}</div></div>
                <Toggle checked={!!settings[key]} label={label} onChange={(v) => saveSetting({ [key]: v }, 'Saved')} />
              </div>
            ))}
          </Card>
          <Card className="flex-[1_1_340px]" title="Protected paths"
            actions={<span className="flex gap-2">
              <input className="input !w-44 font-mono" value={newPath} placeholder="/login" aria-label="Path to protect"
                onChange={(e) => setNewPath(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addPath() } }} />
              <button type="button" className="btn" disabled={busy || !newPath.trim()} onClick={addPath}>Add</button>
            </span>}>
            {paths.length === 0 ? <Empty>No paths are challenged.</Empty> : paths.map((p) => (
              <div key={String(p.id)} className="flex items-center gap-3 py-2 border-t border-line first:border-t-0">
                <div className="flex-1 min-w-0">
                  <div className="font-mono text-sm truncate">{String(p.path)}</div>
                  <div className="text-xs text-muted">{String(p.scope ?? 'all domains')}</div>
                </div>
                <Toggle checked={!!p.enabled} label={`Protect ${String(p.path)}`}
                  onChange={(v) => run({ method: 'PATCH', path: `/firewall/paths/${p.id}`, body: { enabled: v }, label: 'Toggle path', success: 'Saved' }).then(() => refetch()).catch(() => {})} />
                <button type="button" className="btn btn-sm btn-danger"
                  onClick={() => run({ method: 'DELETE', path: `/firewall/paths/${p.id}`, label: 'Remove path', success: `${String(p.path)} removed` }).then(() => refetch()).catch(() => {})}>Remove</button>
              </div>
            ))}
          </Card>
        </div>
      )}

      {tab === 'countries' && (
        <Card title="Country policy" actions={
          <Select className="input !w-auto" aria-label="Country policy mode" value={mode}
            onChange={(e) => saveSetting({ country_mode: e.target.value }, 'Policy mode saved')}>
            <option value="block">Block listed</option>
            <option value="allow">Allow only listed</option>
          </Select>
        }>
          <NotEnforced what="The country policy" needs="a GeoIP plugin such as traefik-geoip2, or CrowdSec" />
          <p className="mt-3 text-sm text-muted">
            {mode === 'block'
              ? 'Every country is allowed except the ones you mark. Click a country to cycle allowed → challenge → blocked.'
              : 'Every country is blocked except the ones you mark. Click a country to allow or block it.'}
          </p>
          <div className="grid gap-2 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
            {COUNTRIES.map(([code, name]) => {
              const label = countryLabel(code)
              return (
                <button key={code} type="button" onClick={() => cycleCountry(code)} disabled={busy}
                  className="flex items-center gap-2.5 min-h-[44px] px-3 rounded-lg bg-card border border-line text-left text-[13px] font-medium cursor-pointer hover:bg-head">
                  <span className="font-mono text-xs text-muted w-6">{code}</span>
                  <span className="flex-1 truncate">{name}</span>
                  <Badge tone={label === 'blocked' ? 'r' : label === 'challenge' ? 'y' : 'g'}>{label}</Badge>
                </button>
              )
            })}
          </div>
        </Card>
      )}

      {tab === 'recaptcha' && (
        <div className="flex flex-wrap gap-4 items-start">
          <Card className="flex-[1_1_360px]" title="Challenge provider">
            <NotEnforced what="The provider configuration" needs="a challenge middleware at the edge" />
            <form className="mt-3 flex flex-col gap-4" onSubmit={(e) => {
              e.preventDefault()
              const patch: R = { provider: p.provider, site_key: p.site_key, min_score: Number(p.min_score), clearance_minutes: Number(p.clearance_minutes) }
              // An empty box keeps the stored secret rather than clearing it.
              if (String(p.secret_key ?? '')) patch.secret_key = p.secret_key
              saveSetting(patch, 'Provider settings saved').then(() => setProv(null))
            }}>
              <Field label="Provider">
                <Select className="input" value={String(p.provider)} onChange={(e) => setP({ provider: e.target.value })}>
                  <option value="turnstile">Cloudflare Turnstile</option>
                  <option value="hcaptcha">hCaptcha</option>
                  <option value="recaptcha">Google reCAPTCHA</option>
                </Select>
              </Field>
              <Field label="Site key"><input className="input font-mono" value={String(p.site_key)} onChange={(e) => setP({ site_key: e.target.value })} /></Field>
              <Field label="Secret key" hint={settings.secret_key_set ? 'A key is saved. Leave blank to keep it.' : 'Stored encrypted and never shown again.'}>
                <input className="input font-mono" type="password" autoComplete="new-password" value={String(p.secret_key ?? '')}
                  placeholder={settings.secret_key_set ? '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022' : ''} onChange={(e) => setP({ secret_key: e.target.value })} />
              </Field>
              <Field label="Minimum score" hint="Below this, the visitor is challenged. Between 0 and 1.">
                <Select className="input" value={String(p.min_score)} onChange={(e) => setP({ min_score: e.target.value })}>
                  {['0.1', '0.3', '0.5', '0.7', '0.9'].map((v) => <option key={v} value={v}>{v}</option>)}
                </Select>
              </Field>
              <Field label="Clearance (minutes)" hint="How long a visitor stays cleared after passing.">
                <input className="input" type="number" min={1} max={1440} value={num(p.clearance_minutes)} onChange={(e) => setP({ clearance_minutes: e.target.value })} />
              </Field>
              <button className="btn btn-primary self-start" disabled={busy}>Save provider</button>
            </form>
          </Card>
        </div>
      )}

      {tab === 'events' && (
        <DataTable label="Firewall events" rows={events.rows} loading={events.isLoading} rowKey={(r) => String(r.id)} pageSize={25}
          searchKeys={(r) => `${r.ip} ${r.request} ${r.rule}`}
          empty={<Empty>No events recorded. Events appear once a rule is enforced at the edge.</Empty>}
          filters={[
            { id: 'block', label: 'Blocked', test: (r) => r.action === 'block' },
            { id: 'throttle', label: 'Throttled', test: (r) => r.action === 'throttle' },
            { id: 'challenge', label: 'Challenged', test: (r) => r.action === 'challenge' || r.action === 'recaptcha' },
          ]}
          columns={[
            { key: 'occurred_at', header: 'Time', sortable: true, render: (r) => <span className="font-mono text-xs">{String(r.occurred_at ?? '').replace('T', ' ').slice(0, 19)}</span> },
            { key: 'action', header: 'Action', render: (r) => <Badge tone={ACTION_TONE[String(r.action)] ?? 'n'}>{String(r.action)}</Badge> },
            { key: 'ip', header: 'IP', render: (r) => <span className="font-mono text-xs">{String(r.ip)}</span> },
            { key: 'country', header: 'Country', render: (r) => String(r.country ?? '—') },
            { key: 'request', header: 'Request', render: (r) => <span className="font-mono text-xs">{String(r.request ?? '—')}</span> },
            { key: 'domain', header: 'Domain', render: (r) => String(r.domain ?? 'all') },
            { key: 'rule', header: 'Rule', render: (r) => String(r.rule ?? '—') },
          ]} />
      )}

      <FormModal open={!!form} onClose={() => setForm(null)} title={form?.id ? 'Edit rule' : 'Add rule'}
        fields={fields} initial={form ?? {}} submitLabel={form?.id ? 'Save rule' : 'Add rule'} onSubmit={saveRule} />

      <ConfirmDialog open={!!remove} onClose={() => setRemove(null)} title="Delete this rule?"
        confirmLabel="Delete rule" danger
        onConfirm={() => run({ method: 'DELETE', path: `/firewall-rules/${remove?.id}`, label: 'Delete rule', success: `Rule "${String(remove?.name)}" deleted` })
          .then(() => { setRemove(null); rules.refetch(); refetch() }).catch(() => {})}>
        {String(remove?.name)} will stop limiting {((remove?.paths as string[]) ?? []).join(', ') || 'this site'} immediately.
      </ConfirmDialog>
    </>
  )
}
