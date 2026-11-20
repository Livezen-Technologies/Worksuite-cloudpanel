import { useState } from 'react'
import { useAction, useList } from '../api/hooks'
import { Card, Empty } from './Card'
import { Badge } from './Badge'
import Select from './Select'
import type { R } from '../lib/util'

const STATES: [string, string][] = [['block', 'Block'], ['challenge', 'Challenge'], ['allow', 'Always allow']]
const TONE: Record<string, 'r' | 'y' | 'g'> = { block: 'r', challenge: 'y', allow: 'g' }

/**
 * Firewall → ISPs: block, challenge or always allow traffic from one network operator (autonomous
 * system), for all domains or one project, app or domain. Matched by CrowdSec from GeoLite2 ASN data.
 */
export function FirewallIsps({ isps, suggestions, onSaved }: { isps: R[]; suggestions: R[]; onSaved: () => void }) {
  const { run, busy } = useAction()
  const apps = useList<R>('/applications', { per_page: 500 })
  const projects = useList<R>('/projects', { per_page: 500 })
  const domains = useList<R>('/domains', { per_page: 500 })
  const [f, setF] = useState({ asn: '', name: '', state: 'block', scope: '' })
  const scopeBody = (v: string) => { const [k, id] = v.split(':'); return { domain_id: k === 'd' ? id : null, application_id: k === 'a' ? id : null, project_id: k === 'p' ? id : null } }
  const send = (list: R[], success: string) => run({ method: 'PATCH', path: '/firewall/isps', label: 'Save ISP rules', success,
    body: { isps: list.map((i) => ({ asn: Number(i.asn), name: i.name || null, state: i.state, domain_id: i.domain_id ?? null, application_id: i.application_id ?? null, project_id: i.project_id ?? null })) } })
    .then(() => { onSaved(); setF({ asn: '', name: '', state: 'block', scope: '' }) }).catch(() => {})
  const add = () => send([...isps, { asn: f.asn.replace(/^AS/i, ''), name: f.name, state: f.state, ...scopeBody(f.scope) }], `AS${f.asn.replace(/^AS/i, '')} ${f.state === 'allow' ? 'always allowed' : f.state === 'block' ? 'blocked' : 'challenged'}`)

  return (
    <Card title="ISP rules" pad={false}>
      <p className="m-0 px-4 sm:px-5 pb-3 text-xs text-muted">Block, challenge or always allow everyone on one internet provider (its AS number). “Always allow” means CrowdSec never bans that provider’s addresses on the chosen sites. Enforced by CrowdSec.</p>
      <div className="flex flex-wrap items-end gap-2 px-4 sm:px-5 pb-3">
        <label className="text-sm"><span className="block text-xs text-muted mb-1">AS number</span><input className="input font-mono !w-32" inputMode="numeric" placeholder="9329" value={f.asn} onChange={(e) => setF({ ...f, asn: e.target.value.replace(/[^0-9ASas]/g, '') })} /></label>
        <label className="text-sm flex-1 min-w-[160px]"><span className="block text-xs text-muted mb-1">Name (optional)</span><input className="input" placeholder="Sri Lanka Telecom" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></label>
        <label className="text-sm"><span className="block text-xs text-muted mb-1">Rule</span><Select className="input !w-auto" value={f.state} onChange={(e) => setF({ ...f, state: e.target.value })}>{STATES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select></label>
        <label className="text-sm"><span className="block text-xs text-muted mb-1">Applies to</span><Select className="input !w-auto" value={f.scope} onChange={(e) => setF({ ...f, scope: e.target.value })}>
          <option value="">All domains</option>
          {projects.rows.map((p) => <option key={`p${p.id}`} value={`p:${p.id}`}>Project · {p.name}</option>)}
          {apps.rows.map((a) => <option key={`a${a.id}`} value={`a:${a.id}`}>App · {a.name}</option>)}
          {domains.rows.map((d) => <option key={`d${d.id}`} value={`d:${d.id}`}>Domain · {d.name}</option>)}
        </Select></label>
        <button type="button" className="btn btn-primary" disabled={busy || !f.asn} onClick={add}>Add</button>
      </div>
      {suggestions.length > 0 && <div className="flex flex-wrap gap-2 px-4 sm:px-5 pb-3 text-xs"><span className="text-muted">Quick pick:</span>
        {suggestions.map((s) => <button key={s.asn} type="button" className="btn btn-sm btn-ghost" onClick={() => setF({ ...f, asn: String(s.asn), name: String(s.name) })}>AS{s.asn} {s.name}</button>)}</div>}
      {!isps.length ? <div className="px-5 pb-5"><Empty>No ISP rules.</Empty></div> :
        <ul className="list-none m-0 p-0">{isps.map((i) => (
          <li key={i.id} className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-2.5 border-t border-line text-[13.5px]">
            <span className="font-mono text-xs w-20">AS{i.asn}</span>
            <span className="flex-1 min-w-0 truncate">{i.name || '—'} <span className="text-xs text-muted">· {i.scope}</span></span>
            <Badge tone={TONE[String(i.state)] ?? 'n'}>{STATES.find((s) => s[0] === i.state)?.[1] ?? i.state}</Badge>
            <button type="button" className="btn btn-sm btn-ghost" disabled={busy} onClick={() => send(isps.filter((x) => x.id !== i.id), `AS${i.asn} rule removed`)}>Remove</button>
          </li>))}</ul>}
    </Card>
  )
}
