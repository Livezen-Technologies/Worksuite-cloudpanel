import { useState } from 'react'
import { useAction, useOne } from '../api/hooks'
import { Card, Empty } from './Card'
import { Badge } from './Badge'
import { ConfirmDialog } from './Overlay'
import { ago } from '../lib/format'

type File = { name: string; hosts: string[]; upstream: string | null; adoptable: boolean; reason: string | null; routers: { name: string; hosts: string[]; service: string; url: string | null; tls: boolean }[] }
type Page = { files: File[]; adopted: string[]; scanned_at: string | null }

/** Live sites on hand-written Traefik files on this server, and taking them over into the panel. */
export function AdoptRoutesPanel({ serverId }: { serverId: string }) {
  const path = `/servers/${serverId}/proxy/adoptable`
  const page = useOne<Page>(path, undefined, { refetchInterval: 10000 })
  const { run, busy } = useAction()
  const [confirm, setConfirm] = useState<File | null>(null)
  const p = page.item
  if (!p) return <Card>Loading…</Card>
  const scan = () => run({ path: `/servers/${serverId}/proxy/scan`, label: 'Scan Traefik files', success: 'Reading the files on the server' }).then(() => setTimeout(() => page.refetch(), 4000)).catch(() => {})

  return (
    <div className="flex flex-col gap-4">
      <Card title="Hand-written routes" subtitle={p.scanned_at ? `Scanned ${ago(p.scanned_at)}` : 'Not scanned yet'}
        actions={<button type="button" className="btn btn-sm" disabled={busy} onClick={scan}>Scan the server</button>}>
        <p className="m-0 text-sm text-muted">Sites routed by Traefik files you wrote yourself are invisible to the panel, so firewall rules, the WAF, public pages and uptime checks don't reach them. Adopting a file creates a managed domain for each of its hosts, sent to the same container. The old file is renamed to <code>.adopted</code> once the panel's route is in place, and put back automatically if the site then fails to answer. Certificates are issued again by the panel's resolver.</p>
      </Card>
      {p.files.length === 0 ? <Card><Empty>{p.scanned_at ? `No hand-written route files.${p.adopted.length ? ` Already adopted: ${p.adopted.join(', ')}` : ''}` : 'Scan to see the files. Only files you wrote are listed, not the panel\'s own dmp-* files.'}</Empty></Card> : (
        <Card pad={false} title="Files">
          <ul className="m-0 p-0 list-none">
            {p.files.map((f) => (
              <li key={f.name} className="flex flex-wrap items-center gap-3 px-4 py-3 border-t border-line first:border-t-0">
                <div className="flex-1 min-w-[220px]">
                  <div className="font-mono text-sm">{f.name}</div>
                  <div className="text-xs text-muted">{f.hosts.length ? f.hosts.join(', ') : 'no hosts'}{f.upstream ? ` → ${f.upstream}` : ''}</div>
                  {!f.adoptable && f.reason && <div className="text-xs text-muted">Not adoptable: {f.reason}.</div>}
                </div>
                {f.adoptable ? <button type="button" className="btn btn-sm btn-primary" disabled={busy} onClick={() => setConfirm(f)}>Adopt</button> : <Badge tone="n">manual</Badge>}
              </li>
            ))}
          </ul>
          {p.adopted.length > 0 && <p className="m-0 px-4 py-3 border-t border-line text-xs text-muted">Already adopted: {p.adopted.join(', ')}</p>}
        </Card>
      )}
      <ConfirmDialog open={!!confirm} title={`Adopt ${confirm?.name ?? ''}?`} confirmLabel="Adopt"
        onClose={() => setConfirm(null)}
        onConfirm={() => { const f = confirm!; setConfirm(null); run({ path: `/servers/${serverId}/proxy/adopt`, body: { file: f.name }, label: `Adopt ${f.name}`, success: 'Adopting; the old file is retired once the site answers' }).then(() => setTimeout(() => page.refetch(), 4000)).catch(() => {}) }}>
        {confirm ? `${confirm.hosts.join(', ')} will be routed by the panel to ${confirm.upstream}, with this organisation's firewall rules. Each host gets a new certificate, which can take a minute.` : ''}
      </ConfirmDialog>
    </div>
  )
}
