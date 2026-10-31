import { useState } from 'react'
import { useAction, useOne } from '../../api/hooks'
import { Card, Empty, Field, PageHeader } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import { ConfirmDialog, Modal } from '../../components/Overlay'
import Select from '../../components/Select'
import { ago } from '../../lib/format'
import { type R } from '../../lib/util'

type Member = { id: string; name: string; ip: string; mesh_ip: string; public_key: string | null; joined_at: string; online: boolean; status: { up?: boolean; error?: string; dns_error?: string; peers?: R[]; checked_at?: string } | null }
type Page = { subnet: string; port: number; zone: string; members: Member[]; candidates: { id: string; name: string; ip: string | null }[]; records: { name: string; ip: string }[]; vpn_peers: R[] }

/** PNET-1..5: WireGuard mesh between servers, *.internal names and admin VPN devices. */
export default function PrivateNetwork() {
  const page = useOne<Page>('/mesh', undefined, { refetchInterval: 15000 })
  const { run, busy } = useAction()
  const [join, setJoin] = useState('')
  const [deviceName, setDeviceName] = useState('')
  const [config, setConfig] = useState<string | null>(null)
  const [leave, setLeave] = useState<Member | null>(null)
  const p = page.item
  if (!p) return <><PageHeader title="Private network" /><Card>Loading…</Card></>

  const state = (m: Member) => !m.public_key ? <Badge tone="y">waiting for key</Badge> : m.status?.error ? <Badge tone="r">error</Badge> : m.status?.up ? <Badge tone="g">up</Badge> : <Badge tone="n">unknown</Badge>
  const handshakes = (m: Member) => (m.status?.peers ?? []).filter((x) => x.last_handshake).length

  return (
    <>
      <PageHeader title="Private network" subtitle={`WireGuard mesh ${p.subnet} on UDP ${p.port}; names under .${p.zone}`}
        actions={<button type="button" className="btn" disabled={busy || p.members.length === 0} onClick={() => run({ path: '/mesh/apply', label: 'Re-apply mesh', success: 'Mesh configuration sent' }).catch(() => {})}>Re-apply</button>} />
      <Card title="Servers" subtitle="Each server keeps its own private key; allow UDP on the mesh port in its firewall">
        {p.members.length === 0 ? <Empty>No servers on the private network yet.</Empty> : (
          <DataTable label="Mesh members" rows={p.members} rowKey={(m) => m.id} columns={[
            { key: 'name', header: 'Server' },
            { key: 'mesh_ip', header: 'Mesh address', render: (m) => <span className="font-mono text-xs">{m.mesh_ip}</span> },
            { key: 'ip', header: 'Endpoint', render: (m) => <span className="font-mono text-xs">{m.ip}:{p.port}</span> },
            { key: 'state', header: 'State', render: (m) => state(m) },
            { key: 'peers', header: 'Handshakes', render: (m) => `${handshakes(m)} / ${(m.status?.peers ?? []).length}` },
            { key: 'checked', header: 'Checked', render: (m) => m.status?.checked_at ? ago(m.status.checked_at) : '—' },
            { key: 'actions', header: '', render: (m) => <button type="button" className="btn btn-sm" onClick={() => setLeave(m)}>Remove</button> },
          ]} />
        )}
        {p.members.some((m) => m.status?.error || m.status?.dns_error) && (
          <ul className="list-none m-0 mt-3 p-0 text-xs text-danger">{p.members.filter((m) => m.status?.error || m.status?.dns_error).map((m) => <li key={m.id}>{m.name}: {m.status?.error ?? `DNS: ${m.status?.dns_error}`}</li>)}</ul>
        )}
        {p.candidates.length > 0 && (
          <div className="flex flex-wrap items-end gap-3 mt-4">
            <Field label="Add a server"><Select className="input" value={join} onChange={(e) => setJoin(e.target.value)}><option value="">Choose…</option>{p.candidates.map((c) => <option key={c.id} value={c.id} disabled={!c.ip}>{c.name}{c.ip ? '' : ' (no public IP)'}</option>)}</Select></Field>
            <button type="button" className="btn btn-primary" disabled={busy || !join} onClick={() => run({ path: `/mesh/servers/${join}`, label: 'Join mesh', success: 'Server joining the private network' }).then(() => setJoin('')).catch(() => {})}>Add to network</button>
          </div>
        )}
      </Card>
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(320px,1fr))]">
        <Card title="Internal names" subtitle="Served by CoreDNS on every server's mesh address">
          {p.records.length === 0 ? <Empty>Names appear once servers join.</Empty> : (
            <ul className="list-none m-0 p-0">{p.records.map((r) => <li key={r.name} className="flex justify-between py-1.5 border-t border-line text-sm font-mono"><span>{r.name}</span><span className="text-muted">{r.ip}</span></li>)}</ul>
          )}
        </Card>
        <Card title="Admin devices" subtitle="WireGuard configs for laptops and phones; reach servers and .internal names">
          <ul className="list-none m-0 p-0">
            {p.vpn_peers.map((v) => (
              <li key={v.id} className="flex flex-wrap items-center gap-2 py-2 border-t border-line text-sm">
                <span className="font-semibold">{v.name}</span><span className="font-mono text-xs text-muted">{v.address}</span>
                <span className="text-xs text-muted ml-auto">{v.last_handshake_at ? `seen ${ago(v.last_handshake_at)}` : 'never connected'}</span>
                <button type="button" className="btn btn-sm btn-danger" disabled={busy} onClick={() => run({ path: `/mesh/vpn-peers/${v.id}`, method: 'DELETE', label: `Revoke ${v.name}`, success: 'Device revoked' }).catch(() => {})}>Revoke</button>
              </li>
            ))}
          </ul>
          <div className="flex flex-wrap items-end gap-3 mt-3">
            <Field label="Device name"><input className="input" value={deviceName} onChange={(e) => setDeviceName(e.target.value)} placeholder="my-laptop" /></Field>
            <button type="button" className="btn" disabled={busy || !deviceName} onClick={() => run<R>({ path: '/mesh/vpn-peers', body: { name: deviceName }, label: 'Add device', success: 'Device added' })
              .then((r) => { setConfig((r as R)?.data?.config ?? null); setDeviceName('') }).catch(() => {})}>Create config</button>
          </div>
        </Card>
      </div>
      {config && (
        <Modal open onClose={() => setConfig(null)} title="WireGuard config" width={640}
          footer={<><button type="button" className="btn" onClick={() => { void navigator.clipboard?.writeText(config) }}>Copy</button>
            <a className="btn btn-primary no-underline" download="dmp-vpn.conf" href={`data:text/plain;charset=utf-8,${encodeURIComponent(config)}`}>Download</a></>}>
          <p className="m-0 mb-3 text-sm text-y-fg">This is the only time the private key is shown. Import it into the WireGuard app now.</p>
          <pre className="m-0 p-3 rounded-lg bg-[var(--sel)] text-xs font-mono overflow-auto">{config}</pre>
        </Modal>
      )}
      <ConfirmDialog open={!!leave} onClose={() => setLeave(null)} danger confirmLabel="Remove" title={`Remove ${leave?.name} from the private network?`}
        onConfirm={() => { const m = leave; setLeave(null); if (m) run({ path: `/mesh/servers/${m.id}`, method: 'DELETE', label: 'Leave mesh', success: 'Server removed' }).catch(() => {}) }}>
        Its WireGuard interface and internal DNS are stopped, and the other servers drop it as a peer.
      </ConfirmDialog>
    </>
  )
}
