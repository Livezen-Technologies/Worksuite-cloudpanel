import { useEffect, useMemo, useRef, useState } from 'react'
import qrcode from 'qrcode-generator'
import { CopyButton } from './CopyButton'
import { Modal } from './Overlay'

/** QR code for an otpauth:// URL, as crisp SVG rects on a white tile (scanners need the contrast). */
export function QrCode({ value, size = 176 }: { value: string; size?: number }) {
  const cells = useMemo(() => {
    const q = qrcode(0, 'M'); q.addData(value); q.make()
    const n = q.getModuleCount(), out: [number, number][] = []
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (q.isDark(r, c)) out.push([c, r])
    return { n, out }
  }, [value])
  const pad = 2, dim = cells.n + pad * 2
  return (
    <svg width={size} height={size} viewBox={`0 0 ${dim} ${dim}`} role="img" aria-label="QR code for your authenticator app" shapeRendering="crispEdges" className="rounded-xl bg-white ring-1 ring-line p-1.5">
      <rect width={dim} height={dim} fill="#fff" />
      {cells.out.map(([x, y]) => <rect key={`${x}-${y}`} x={x + pad} y={y + pad} width={1} height={1} fill="#0F172A" />)}
    </svg>
  )
}

/** Six single-digit boxes; paste fills them all; calls onComplete when the sixth digit lands. */
export function OtpInput({ value, onChange, onComplete, disabled, invalid }: { value: string; onChange: (v: string) => void; onComplete?: (v: string) => void; disabled?: boolean; invalid?: boolean }) {
  const refs = useRef<(HTMLInputElement | null)[]>([])
  const set = (v: string) => {
    const d = v.replace(/\D/g, '').slice(0, 6)
    onChange(d)
    refs.current[Math.min(d.length, 5)]?.focus()
    if (d.length === 6) onComplete?.(d)
  }
  return (
    <div className="flex gap-2" role="group" aria-label="6-digit code">
      {Array.from({ length: 6 }, (_, i) => (
        <input key={i} ref={(el) => { refs.current[i] = el }} data-autofocus={i === 0 ? '' : undefined}
          className={`input !w-11 !h-12 !px-0 text-center text-lg font-semibold font-mono ${invalid ? '!border-danger' : ''}`} aria-label={`Digit ${i + 1}`}
          inputMode="numeric" autoComplete={i === 0 ? 'one-time-code' : 'off'} maxLength={i === 0 ? 6 : 1} disabled={disabled} value={value[i] ?? ''}
          onChange={(e) => set(value.slice(0, i) + e.target.value.replace(/\D/g, '') + value.slice(i + 1))}
          onKeyDown={(e) => { if (e.key === 'Backspace' && !value[i] && i > 0) { e.preventDefault(); onChange(value.slice(0, i - 1)); refs.current[i - 1]?.focus() } }}
          onPaste={(e) => { e.preventDefault(); set(e.clipboardData.getData('text')) }} />
      ))}
    </div>
  )
}

/** Two steps: scan (or type the key), then enter the code. Confirms on its own when six digits are in. */
export function TwoFactorSetupModal({ setup, onClose, onConfirm }: { setup: { secret: string; otpauth_url: string } | null; onClose: () => void; onConfirm: (otp: string) => Promise<boolean> }) {
  const [step, setStep] = useState<1 | 2>(1)
  const [otp, setOtp] = useState('')
  const [busy, setBusy] = useState(false)
  const [bad, setBad] = useState(false)
  useEffect(() => { if (setup) { setStep(1); setOtp(''); setBad(false) } }, [setup])
  const confirm = async (code = otp) => {
    if (code.length !== 6 || busy) return
    setBusy(true); setBad(false)
    const ok = await onConfirm(code).catch(() => false)
    setBusy(false)
    if (!ok) { setBad(true); setOtp('') }
  }
  const grouped = (setup?.secret ?? '').replace(/(.{4})/g, '$1 ').trim()
  return (
    <Modal open={!!setup} onClose={onClose} title="Turn on two-factor authentication" width={560}
      footer={step === 1
        ? <><button type="button" className="btn" onClick={onClose}>Cancel</button><button type="button" className="btn btn-primary" onClick={() => setStep(2)}>Next: enter code</button></>
        : <><button type="button" className="btn" onClick={() => setStep(1)}>Back</button><button type="button" className="btn btn-primary" disabled={otp.length !== 6 || busy} onClick={() => confirm()}>{busy ? 'Checking…' : 'Turn on'}</button></>}>
      <ol className="m-0 mb-5 p-0 list-none flex items-center gap-2 text-xs font-medium" aria-label="Steps">
        {['Scan', 'Verify'].map((l, i) => (
          <li key={l} className="flex items-center gap-2" aria-current={step === i + 1 ? 'step' : undefined}>
            <span className={`grid place-items-center w-6 h-6 rounded-full ${step > i ? 'bg-accent text-white' : 'bg-head text-muted'}`}>{step > i + 1 ? '✓' : i + 1}</span>
            <span className={step === i + 1 ? 'text-fg' : 'text-muted'}>{l}</span>
            {i === 0 && <span className="w-10 h-px bg-line" />}
          </li>
        ))}
      </ol>
      {step === 1 ? (
        <div className="flex flex-col sm:flex-row gap-5 items-center sm:items-start">
          {setup?.otpauth_url && <QrCode value={setup.otpauth_url} />}
          <div className="flex-1 min-w-0 flex flex-col gap-3">
            <p className="m-0">Scan the code with an authenticator app such as Google Authenticator, 1Password, Authy or Microsoft Authenticator.</p>
            <div>
              <div className="text-xs text-muted mb-1">Can’t scan? Type this key instead</div>
              <div className="flex items-center gap-2"><code className="flex-1 font-mono text-[13px] tracking-wide px-3 py-2 rounded-lg bg-head break-all">{grouped}</code><CopyButton value={setup?.secret ?? ''} label="Copy key" /></div>
            </div>
            {setup?.otpauth_url && <a href={setup.otpauth_url} className="text-sm font-semibold sm:hidden">Open in authenticator app on this phone</a>}
          </div>
        </div>
      ) : (
        <div className="flex flex-col items-center gap-3 py-2 text-center">
          <p className="m-0">Enter the 6-digit code your app shows for this account.</p>
          <OtpInput value={otp} onChange={(v) => { setOtp(v); setBad(false) }} onComplete={(v) => confirm(v)} disabled={busy} invalid={bad} />
          <p className={`m-0 text-xs min-h-4 ${bad ? 'text-[var(--r-fg)]' : 'text-muted'}`} role={bad ? 'alert' : undefined}>
            {bad ? 'That code didn’t match. Codes change every 30 seconds; try the current one.' : busy ? 'Checking…' : 'It turns on as soon as the code matches.'}
          </p>
        </div>
      )}
    </Modal>
  )
}
