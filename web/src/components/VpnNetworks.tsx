import { useEffect, useState } from 'react'
import { useAction, useList, useOne } from '../api/hooks'
import type { Server } from '../api/types'
import { num, type R } from '../lib/util'
import { Badge } from './Badge'
import { Card, Empty, Field } from './Card'
import { DataTable } from './DataTable'
import { ConfirmDialog } from './Overlay'
import { WizardChecks, WizardChoice, WizardSection, WizardShell, type ShellStep } from './WizardShell'

const STEPS: readonly ShellStep[] = [
  { id: 'basics', label: 'Network', hint: 'Name and protocol' },
  { id: 'gateway', label: 'Gateway', hint: 'Server that serves it' },
  { id: 'addressing', label: 'Addressing', hint: 'Range, port and routing' },
  { id: 'review', label: 'Review', hint: 'Check and save' },
]
const PROTOCOLS = {
  wireguard: { label: 'WireGuard', text: 'Lightweight, fast encrypted tunnels over UDP. Recommended.', port: 51821 },
  openvpn: { label: 'OpenVPN', text: 'Certificate-based, works over UDP or TCP for restrictive networks.', port: 1194 },
} as const
const statusTone = (s: string) => (s === 'active' ? 'g' : s === 'failed' ? 'r' : s === 'deploying' ? 'y' : 'n') as 'g' | 'r' | 'y' | 'n'

/** VPN phase 1: client-access VPN networks (records; deploying to the gateway comes next). */
export function VpnNetworks() {
  const { rows, isLoading } = useList<R>('/vpn/networks', { per_page: 200 })
  const { run, busy } = useAction()
  const [create, setCreate] = useState(false)
  const [del, setDel] = useState<R | null>(null)
  const [deploy, setDeploy] = useState<R | null>(null)
  return (
    <>
      <Card title="VPN networks" subtitle="Private networks people connect to with a VPN client, separate from the server mesh"
        actions={<button type="button" className="btn btn-primary" onClick={() => setCreate(true)}>Create VPN network</button>}>
        {!isLoading && rows.length === 0 ? <Empty icon="shield" action={<button type="button" className="btn btn-primary" onClick={() => setCreate(true)}>Create VPN network</button>}>No VPN networks yet.</Empty> : (
          <DataTable label="VPN networks" tableId="vpn-networks" rows={rows} loading={isLoading} rowKey={(n) => String(n.id)} rowLabel={(n) => String(n.name)}
            actions={(n) => [
              ...(n.status === 'active' || n.status === 'deploying'
                ? [{ label: 'Refresh status', icon: 'refresh', disabled: busy, onClick: () => { run({ path: `/vpn/networks/${n.id}/status`, label: `Check ${n.name}` }).catch(() => {}) } },
                   { label: 'Stop on gateway', icon: 'stop', menuOnly: true, disabled: busy, confirm: `Stop ${n.name}? Connected devices lose access until it is deployed again.`, onClick: () => { run({ path: `/vpn/networks/${n.id}/stop`, label: `Stop ${n.name}`, success: `${n.name} stopping` }).catch(() => {}) } }]
                : [{ label: 'Deploy to gateway', icon: 'play', disabled: busy || n.protocol !== 'wireguard', onClick: () => setDeploy(n) }]),
              { label: 'Delete', icon: 'trash', danger: true, confirm: false, disabled: !['draft', 'failed', 'stopped'].includes(String(n.status)), onClick: () => setDel(n) },
            ]}
            columns={[
              { key: 'name', header: 'Network', value: (n) => String(n.name), render: (n) => <span className="font-semibold">{String(n.name)}</span> },
              { key: 'protocol', header: 'Protocol', value: (n) => String(n.protocol), render: (n) => PROTOCOLS[n.protocol as keyof typeof PROTOCOLS]?.label ?? String(n.protocol) },
              { key: 'cidr', header: 'Range', value: (n) => String(n.cidr), render: (n) => <span className="font-mono text-xs">{String(n.cidr)}</span> },
              { key: 'gateway', header: 'Gateway', value: (n) => String(n.gateway_name ?? '') },
              { key: 'port', header: 'Port', value: (n) => `${n.transport}/${n.port}`, render: (n) => <span className="font-mono text-xs">{String(n.transport).toUpperCase()} {String(n.port)}</span> },
              { key: 'routing', header: 'Routing', value: (n) => String(n.routing), render: (n) => (n.routing === 'full' ? 'Full tunnel' : 'Split tunnel') },
              { key: 'status', header: 'Status', value: (n) => String(n.status), render: (n) => (
                <span className="flex flex-col gap-0.5">
                  <Badge tone={statusTone(String(n.status))}>{n.status === 'draft' ? 'not deployed' : String(n.status)}</Badge>
                  {n.status === 'failed' && n.last_error ? <span className="text-xs text-[var(--r-fg)] max-w-[260px] truncate" title={String(n.last_error)}>{String(n.last_error)}</span> : null}
                </span>) },
              { key: 'devices', header: 'Devices online', kind: 'number', value: (n) => ((n.gateway_status as R | null)?.peers as R[] | undefined)?.filter((p) => p.last_handshake).length ?? null,
                render: (n) => { const peers = ((n.gateway_status as R | null)?.peers as R[] | undefined); return peers ? `${peers.filter((p) => p.last_handshake).length} / ${peers.length}` : <span className="text-muted">—</span> } },
            ]} />
        )}
      </Card>
      <CreateVpnNetworkWizard open={create} onClose={() => setCreate(false)} />
      <ConfirmDialog open={!!deploy} onClose={() => setDeploy(null)} title={`Deploy ${deploy?.name}?`} confirmLabel="Deploy" busy={busy}
        onConfirm={() => run({ path: `/vpn/networks/${deploy!.id}/deploy`, label: `Deploy ${deploy!.name}`, success: `${deploy!.name} is deploying` }).then(() => setDeploy(null)).catch(() => {})}>
        <p className="m-0">The agent on <b>{String(deploy?.gateway_name ?? 'the gateway')}</b> sets up WireGuard on UDP {String(deploy?.port)}: its own key, IP forwarding and a firewall that lets VPN devices reach only the private network{deploy?.routing === 'full' ? ' and, through NAT, the internet' : ''}. If any step fails it puts everything back as it was.</p>
        <p className="m-0 mt-2 text-sm text-muted">The gateway needs wireguard-tools and nftables, and an agent new enough to know VPN networks. No devices can connect yet: adding users and devices is the next phase.</p>
      </ConfirmDialog>
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} danger title={`Delete ${del?.name}?`} confirmLabel="Delete network" busy={busy}
        onConfirm={() => run({ method: 'DELETE', path: `/vpn/networks/${del!.id}`, label: `Delete ${del!.name}`, success: `${del!.name} deleted` }).then(() => setDel(null)).catch(() => {})}>
        <p className="m-0">It is not running on its gateway, so nothing changes there.</p>
      </ConfirmDialog>
    </>
  )
}

export function CreateVpnNetworkWizard({ open, onClose }: { open: boolean; onClose: () => void }) {
  const blank = { name: '', protocol: 'wireguard' as keyof typeof PROTOCOLS, gateway_server_id: '', cidr: '', port: '51821', transport: 'udp', routing: 'split', private_dns: true }
  const [f, setF] = useState(blank)
  const [step, setStep] = useState(0)
  const [reach, setReach] = useState(0)
  const [err, setErr] = useState<string | null>(null)
  const { run, busy } = useAction()
  const servers = useList<Server>(open ? '/servers' : null, { per_page: 200 })
  const suggest = useOne<{ cidr: string | null; mesh: string }>(open ? '/vpn/networks/suggest' : null)

  useEffect(() => { if (!open) { setF(blank); setStep(0); setReach(0); setErr(null) } }, [open]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (open && !f.cidr && suggest.item?.cidr) setF((v) => ({ ...v, cidr: suggest.item!.cidr! })) }, [open, suggest.item?.cidr]) // eslint-disable-line react-hooks/exhaustive-deps

  // DNS and proxy servers first: they are the ones meant to face the internet.
  const ordered = [...servers.rows].sort((a, b) => Number(b.category === 'dns') - Number(a.category === 'dns') || a.name.localeCompare(b.name))
  const gw = servers.rows.find((s) => s.id === f.gateway_server_id)
  const proto = PROTOCOLS[f.protocol]
  const cidrOk = /^\d{1,3}(\.\d{1,3}){3}\/([89]|[12]\d|30)$/.test(f.cidr.trim())
  const hosts = cidrOk ? Math.max(0, 2 ** (32 - Number(f.cidr.split('/')[1])) - 3) : 0

  const validate = (i: number) => {
    if (i === 0 && !f.name.trim()) return 'Give the network a name.'
    if (i === 1 && !f.gateway_server_id) return 'Choose the gateway server.'
    if (i === 2 && !cidrOk) return 'Use an IPv4 range like 10.90.0.0/24.'
    if (i === 2 && !(num(f.port) >= 1 && num(f.port) <= 65535)) return 'Use a port between 1 and 65535.'
    return null
  }
  const go = (i: number) => {
    if (i > step) { const e = validate(step); if (e) { setErr(e); return } }
    setErr(null); setStep(i); setReach((r) => Math.max(r, i))
  }
  const save = () => {
    run({ path: '/vpn/networks', label: `Create ${f.name}`, success: `${f.name} saved; it is not deployed yet`,
      body: { ...f, name: f.name.trim(), cidr: f.cidr.trim(), port: num(f.port), transport: f.protocol === 'wireguard' ? 'udp' : f.transport } })
      .then(onClose).catch((e) => setErr(e instanceof Error ? e.message : 'Could not save the network'))
  }

  const summary: [string, string][] = [
    ['Name', f.name || '—'], ['Protocol', proto.label], ['Gateway', gw?.name ?? '—'],
    ['Range', f.cidr || '—'], ['Port', `${(f.protocol === 'wireguard' ? 'udp' : f.transport).toUpperCase()} ${f.port}`],
    ['Routing', f.routing === 'full' ? 'Full tunnel' : 'Split tunnel'], ['Private DNS', f.private_dns ? 'On' : 'Off'],
  ]

  return (
    <WizardShell open={open} onClose={onClose} title="Create VPN network" subtitle="A private network users reach with WireGuard or OpenVPN"
      steps={STEPS} step={step} reach={reach} onStep={go} summary={summary} error={err} busy={busy}
      next={step < STEPS.length - 1 ? { label: 'Continue', onClick: () => go(step + 1) } : { label: busy ? 'Saving…' : 'Save network', onClick: save }}>
      {step === 0 && (
        <div className="flex flex-col gap-6">
          <WizardSection title="Name" hint="Shown to users in their VPN portal.">
            <Field label="VPN network name"><input className="input" aria-label="VPN network name" autoFocus value={f.name} placeholder="Livezen Private Network" onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
          </WizardSection>
          <WizardSection title="Protocol">
            <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
              {(Object.keys(PROTOCOLS) as (keyof typeof PROTOCOLS)[]).map((k) => (
                <WizardChoice key={k} on={f.protocol === k} icon="shield" title={PROTOCOLS[k].label} text={PROTOCOLS[k].text}
                  onClick={() => setF({ ...f, protocol: k, port: String(PROTOCOLS[k].port), transport: 'udp' })} />
              ))}
            </div>
          </WizardSection>
        </div>
      )}
      {step === 1 && (
        <WizardSection title="Gateway server" hint="Users connect to this server's public address. DNS and proxy servers come first.">
          <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">
            {ordered.map((s) => (
              <WizardChoice key={s.id} on={f.gateway_server_id === s.id} icon="server" title={s.name} disabled={s.status === 'offline'}
                text={`${s.ip} · ${s.status}${s.category === 'dns' ? ' · DNS & proxy' : ''}`} onClick={() => setF({ ...f, gateway_server_id: s.id })} />
            ))}
          </div>
        </WizardSection>
      )}
      {step === 2 && (
        <div className="flex flex-col gap-6">
          <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
            <Field label="Private range (CIDR)" hint={cidrOk ? `${hosts} device addresses` : `Must not overlap the mesh (${suggest.item?.mesh ?? '10.88.0.0/16'})`}>
              <input className="input font-mono" aria-label="Private range" value={f.cidr} onChange={(e) => setF({ ...f, cidr: e.target.value })} placeholder="10.90.0.0/24" /></Field>
            <Field label="Port"><input className="input font-mono" aria-label="Port" inputMode="numeric" value={f.port} onChange={(e) => setF({ ...f, port: e.target.value })} /></Field>
            {f.protocol === 'openvpn' && (
              <Field label="Transport"><div className="flex gap-2" role="group" aria-label="Transport">
                {['udp', 'tcp'].map((t) => <button key={t} type="button" aria-pressed={f.transport === t} className={`btn btn-sm ${f.transport === t ? '!border-[var(--accent)] !bg-sel text-accent' : ''}`} onClick={() => setF({ ...f, transport: t })}>{t.toUpperCase()}</button>)}
              </div></Field>
            )}
          </div>
          <WizardSection title="Traffic routing">
            <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
              <WizardChoice on={f.routing === 'split'} title="Split tunnel" text="Only private application traffic goes through the VPN." onClick={() => setF({ ...f, routing: 'split' })} />
              <WizardChoice on={f.routing === 'full'} title="Full tunnel" text="All of a device's traffic goes through the gateway." onClick={() => setF({ ...f, routing: 'full' })} />
            </div>
          </WizardSection>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.private_dns} onChange={(e) => setF({ ...f, private_dns: e.target.checked })} /> Resolve private <span className="font-mono">.internal</span> names over the VPN</label>
        </div>
      )}
      {step === 3 && (
        <WizardSection title="Ready to save" hint="Saving records the network. Deploying it to the gateway is a separate step, coming next.">
          <WizardChecks label="Checks" checks={[
            { key: 'gw', status: gw?.status === 'online' ? 'pass' : 'warn', label: 'Gateway', message: gw ? `${gw.name} (${gw.ip}) is ${gw.status}` : '—' },
            { key: 'range', status: 'pass', label: 'Range', message: `${f.cidr} — the panel rejects it if it overlaps the mesh, another VPN or a Docker network` },
            { key: 'port', status: 'warn', label: 'Firewall', message: `Allow ${(f.protocol === 'wireguard' ? 'udp' : f.transport).toUpperCase()} ${f.port} inbound on ${gw?.name ?? 'the gateway'} before deploying` },
          ]} />
        </WizardSection>
      )}
    </WizardShell>
  )
}
