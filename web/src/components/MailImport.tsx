import { useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { Card, Field } from './Card'
import { Badge } from './Badge'
import { Modal } from './Overlay'
import Select from './Select'
import { ago } from '../lib/format'
import type { R } from '../lib/util'

const TONE: Record<string, 'g' | 'r' | 'y' | 'n'> = { succeeded: 'g', failed: 'r', running: 'y', queued: 'n' }

const bytes = (n: unknown) => {
  const v = Number(n ?? 0)
  return v >= 1073741824 ? `${(v / 1073741824).toFixed(1)} GB` : `${(v / 1048576).toFixed(1)} MB`
}

/**
 * MCL-10: copy a mailbox from Gmail, Microsoft 365, cPanel or any IMAP server.
 * `base` is the API prefix: '' for admins, '/app' for customers.
 */
export function ImportDialog({ base, domainId, box, address, onClose }: { base: string; domainId: string; box: string | null; address: string; onClose: () => void }) {
  const list = useOne<R>(box ? `${base}/mail/domains/${domainId}/imports` : null)
  const presets = (list.item?.presets ?? {}) as Record<string, R>
  const { run, busy } = useAction()
  const [f, setF] = useState<R>({ preset: 'gmail', host: 'imap.gmail.com', port: 993, security: 'ssl', username: '', password: '' })
  const preset = presets[String(f.preset)] ?? {}
  const pick = (k: string) => { const p = presets[k] ?? {}; setF({ ...f, preset: k, host: p.host ?? '', port: p.port ?? 993, security: p.security ?? 'ssl' }) }
  const start = () => run({
    path: `${base}/mail/domains/${domainId}/mailboxes/${box}/import`,
    body: { host: f.host, port: Number(f.port) || 993, security: f.security, username: f.username, password: f.password },
    label: `Import into ${address}`, success: 'Import started; it runs in the background',
  }).then(onClose).catch(() => {})

  return (
    <Modal open={!!box} onClose={onClose} title={`Import mail into ${address}`} width={560}
      footer={<><button type="button" className="btn" onClick={onClose}>Cancel</button>
        <button type="button" className="btn btn-primary" disabled={busy || !f.host || !f.username || !f.password} onClick={start}>Start import</button></>}>
      <div className="flex flex-col gap-4 text-sm">
        <p className="m-0 text-muted">Every folder and message is copied from the other provider; mail already here is kept and nothing is deleted at the source. Run it again later to pick up new mail.</p>
        <Field label="Copy from">
          <Select className="input" value={String(f.preset)} onChange={(e) => pick(e.target.value)}>
            {Object.entries(presets).map(([k, p]) => <option key={k} value={k}>{String(p.label)}</option>)}
          </Select>
        </Field>
        {preset.hint ? <p className="m-0 -mt-2 text-xs text-muted">{String(preset.hint)}</p> : null}
        <div className="grid gap-3 [grid-template-columns:2fr_1fr_1fr] max-sm:grid-cols-1">
          <Field label="IMAP server"><input className="input font-mono" value={String(f.host)} placeholder="mail.example.com" onChange={(e) => setF({ ...f, host: e.target.value })} /></Field>
          <Field label="Port"><input className="input" type="number" value={String(f.port)} onChange={(e) => setF({ ...f, port: e.target.value })} /></Field>
          <Field label="Security">
            <Select className="input" value={String(f.security)} onChange={(e) => setF({ ...f, security: e.target.value })}>
              <option value="ssl">SSL/TLS</option><option value="starttls">STARTTLS</option><option value="none">None</option>
            </Select>
          </Field>
        </div>
        <Field label="Username at the other provider"><input className="input" autoComplete="off" value={String(f.username)} placeholder="you@gmail.com" onChange={(e) => setF({ ...f, username: e.target.value })} /></Field>
        <Field label="Password or app password" hint="Used only for this import, then forgotten.">
          <input className="input" type="password" autoComplete="new-password" value={String(f.password)} onChange={(e) => setF({ ...f, password: e.target.value })} />
        </Field>
      </div>
    </Modal>
  )
}

/** The imports into a domain's mailboxes, newest first. */
export function ImportList({ base, domainId, boxes }: { base: string; domainId: string; boxes: R[] }) {
  const list = useOne<R>(`${base}/mail/domains/${domainId}/imports`, undefined, { refetchInterval: 10000 })
  const rows = (list.item?.imports ?? []) as R[]
  const [open, setOpen] = useState<string | null>(null)
  if (!rows.length) return null
  const address = (id: unknown) => String(boxes.find((b) => String(b.id) === String(id))?.address ?? boxes.find((b) => String(b.id) === String(id))?.local_part ?? 'mailbox')
  return (
    <Card title="Imports">
      <ul className="m-0 p-0 list-none flex flex-col text-sm">
        {rows.map((i) => (
          <li key={String(i.id)} className="py-2 border-t border-line first:border-t-0">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-semibold flex-1 min-w-0 break-all">{address(i.mailbox_id)} ← <span className="font-mono text-xs font-normal">{String(i.source)}</span></span>
              <Badge tone={TONE[String(i.status)] ?? 'n'}>{String(i.status)}</Badge>
            </div>
            <div className="text-xs text-muted">
              {ago(String(i.started_at))}
              {i.messages != null ? ` · ${i.messages} messages in ${i.folders ?? '?'} folders · ${bytes(i.bytes)}` : ''}
              {Number(i.errors) > 0 ? ` · ${i.errors} errors` : ''}
              {i.log ? <> · <button type="button" className="btn btn-sm btn-ghost" onClick={() => setOpen(open === i.id ? null : String(i.id))}>{open === i.id ? 'Hide log' : 'Log'}</button></> : null}
            </div>
            {open === i.id && <pre className="m-0 mt-1 p-2 rounded bg-[var(--code-bg)] text-[var(--code-fg)] text-xs whitespace-pre-wrap max-h-48 overflow-auto">{String(i.log)}</pre>}
          </li>
        ))}
      </ul>
    </Card>
  )
}
