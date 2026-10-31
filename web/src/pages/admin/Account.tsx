import { useEffect, useState, type FormEvent } from 'react'
import { api, isApiError } from '../../api/client'
import { useAction, useOne } from '../../api/hooks'
import { Card, Field, PageHeader } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { PasskeyManager } from '../../components/Passkeys'
import { useToast } from '../../components/Toasts'
import { useAuth } from '../../lib/auth'
import { ago, unwrap } from '../../lib/format'
import type { R } from '../../lib/util'

/** Your profile, password, two-factor, passkeys and sessions (admin panel). */
export function AdminAccount() {
  const { me, refresh } = useAuth()
  const u = me?.user
  return (
    <>
      <PageHeader title="Account" subtitle="Your profile and how you sign in" />
      <div className="grid gap-4 items-start [grid-template-columns:repeat(auto-fit,minmax(min(100%,380px),1fr))]">
        <Profile name={String(u?.name ?? '')} email={String(u?.email ?? '')} role={String(me?.role ?? '')} verified={!!u?.email_verified_at || u?.email_verified !== false} onSaved={refresh} />
        <ChangePassword />
        <TwoFactor on={!!u?.mfa_enabled} onChange={refresh} />
        <PasskeyManager />
        <Sessions />
      </div>
    </>
  )
}

function Profile({ name, email, role, verified, onSaved }: { name: string; email: string; role: string; verified: boolean; onSaved: () => unknown }) {
  const { run, busy } = useAction()
  const [f, setF] = useState({ name, email, current_password: '' })
  useEffect(() => setF({ name, email, current_password: '' }), [name, email])
  const emailChanged = f.email.trim().toLowerCase() !== email.toLowerCase()
  const submit = (e: FormEvent) => {
    e.preventDefault()
    const body: R = { name: f.name }
    if (emailChanged) Object.assign(body, { email: f.email, current_password: f.current_password })
    run({ method: 'PATCH', path: '/me/profile', body, label: 'Save profile', success: emailChanged ? 'Saved. Check your new inbox to verify the address.' : 'Profile saved' }).then(() => onSaved()).catch(() => {})
  }
  const initials = (f.name || email).split(/\s+/).map((x) => x[0]).join('').slice(0, 2).toUpperCase()
  return (
    <Card title="Profile">
      <form className="flex flex-col gap-4" onSubmit={submit}>
        <div className="flex items-center gap-3">
          <span aria-hidden="true" className="w-12 h-12 rounded-full bg-accent text-white font-bold flex items-center justify-center flex-none">{initials}</span>
          <div className="min-w-0 text-sm"><div className="font-semibold truncate">{name}</div><div className="text-muted flex flex-wrap items-center gap-2">{role.replace(/_/g, ' ')}{!verified && <Badge tone="y">email not verified</Badge>}</div></div>
        </div>
        <Field label="Full name"><input className="input" required maxLength={120} autoComplete="name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="Email" hint={emailChanged ? 'You will need to verify the new address.' : undefined}><input className="input" type="email" required autoComplete="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        {emailChanged && <Field label="Current password" hint="Needed to change your email."><input className="input" type="password" required autoComplete="current-password" value={f.current_password} onChange={(e) => setF({ ...f, current_password: e.target.value })} /></Field>}
        <button className="btn btn-primary self-start" disabled={busy || (f.name === name && !emailChanged)}>Save</button>
      </form>
    </Card>
  )
}

function ChangePassword() {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const [f, setF] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [err, setErr] = useState<Record<string, string>>({})
  const mismatch = f.password_confirmation !== '' && f.password !== f.password_confirmation
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true); setErr({})
    try {
      const r = unwrap<R>(await api.post('/me/password', f))
      toast.success(r.other_sessions_signed_out ? `Password changed. ${r.other_sessions_signed_out} other session(s) signed out.` : 'Password changed')
      setF({ current_password: '', password: '', password_confirmation: '' })
    } catch (e2) {
      const fields = isApiError(e2) ? e2.fields : {}
      setErr(Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, v[0]])))
      if (!Object.keys(fields).length) toast.error(e2, 'Could not change the password')
    } finally { setBusy(false) }
  }
  return (
    <Card title="Password" subtitle="Changing it signs out your other sessions">
      <form className="flex flex-col gap-4" onSubmit={submit}>
        <input type="text" name="username" autoComplete="username" className="sr-only" tabIndex={-1} aria-hidden="true" readOnly value="" />
        <Field label="Current password" error={err.current_password}><input className="input" type="password" required autoComplete="current-password" value={f.current_password} onChange={(e) => setF({ ...f, current_password: e.target.value })} /></Field>
        <Field label="New password" hint="At least 10 characters." error={err.password}><input className="input" type="password" required minLength={10} autoComplete="new-password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} /></Field>
        <Field label="Repeat the new password" error={mismatch ? 'The passwords do not match.' : undefined}><input className="input" type="password" required autoComplete="new-password" value={f.password_confirmation} onChange={(e) => setF({ ...f, password_confirmation: e.target.value })} /></Field>
        <button className="btn btn-primary self-start" disabled={busy || mismatch || f.password.length < 10}>Change password</button>
      </form>
    </Card>
  )
}

function TwoFactor({ on, onChange }: { on: boolean; onChange: () => unknown }) {
  const toast = useToast()
  const [setup, setSetup] = useState<{ secret: string; otpauth_url: string } | null>(null)
  const [otp, setOtp] = useState('')
  const [off, setOff] = useState(false)
  const start = async () => { try { setSetup(unwrap(await api.post('/me/mfa/enable'))) } catch (e) { toast.error(e, 'Could not start two-factor setup') } }
  const confirm = async () => {
    try { await api.post(off ? '/me/mfa/disable' : '/me/mfa/confirm', { otp }); toast.success(off ? 'Two-factor is off' : 'Two-factor is on'); setSetup(null); setOff(false); setOtp(''); onChange() }
    catch (e) { toast.error(e, 'That code did not work. Check your authenticator app and try again.') }
  }
  return (
    <Card title="Two-factor authentication" actions={on ? <Badge tone="g">on</Badge> : <Badge tone="n">off</Badge>}>
      {!setup && !off && <p className="m-0 mb-3 text-sm text-muted">{on ? 'Sign-in asks for a code from your authenticator app.' : 'Add a code from an authenticator app to every sign-in.'}</p>}
      {setup && (
        <div className="flex flex-col gap-2 text-sm mb-3">
          <p className="m-0">Add this key to your authenticator app, then enter the 6-digit code it shows.</p>
          <code className="font-mono text-xs break-all p-2 rounded bg-head">{setup.secret}</code>
          {setup.otpauth_url && <a href={setup.otpauth_url} className="font-semibold">Open in authenticator app</a>}
        </div>
      )}
      {(setup || off) ? (
        <div className="flex flex-wrap items-end gap-2">
          <Field label={off ? 'Code to turn it off' : 'Code from the app'}><input className="input w-40 font-mono" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={otp} onChange={(e) => setOtp(e.target.value.replace(/\D/g, ''))} /></Field>
          <button type="button" className={off ? 'btn btn-danger' : 'btn btn-primary'} disabled={otp.length !== 6} onClick={confirm}>{off ? 'Turn off' : 'Confirm'}</button>
          <button type="button" className="btn btn-ghost" onClick={() => { setSetup(null); setOff(false); setOtp('') }}>Cancel</button>
        </div>
      ) : on ? <button type="button" className="btn" onClick={() => setOff(true)}>Turn off…</button> : <button type="button" className="btn btn-primary" onClick={start}>Set up two-factor</button>}
    </Card>
  )
}

function Sessions() {
  const list = useOne<R[]>('/me/sessions')
  const { run, busy } = useAction()
  const rows = (list.item ?? []) as R[]
  const others = rows.filter((s) => !s.current).length
  const end = (path: string, label: string) => run({ method: 'DELETE', path, label, success: 'Signed out' }).then(() => list.refetch()).catch(() => {})
  return (
    <Card title="Sessions" subtitle="Where you are signed in" actions={others > 0 ? <button type="button" className="btn btn-sm" disabled={busy} onClick={() => end('/me/sessions', 'Sign out other sessions')}>Sign out the others</button> : undefined}>
      {list.isLoading ? 'Loading…' : (
        <ul className="m-0 p-0 list-none flex flex-col text-sm">
          {rows.map((s) => (
            <li key={String(s.id)} className="flex flex-wrap items-center gap-3 py-2 border-t border-line first:border-t-0">
              <span className="flex-1 min-w-0">Signed in {ago(String(s.created_at))}{s.last_used_at ? ` · active ${ago(String(s.last_used_at))}` : ''}</span>
              {s.current ? <Badge tone="g">this session</Badge> : <button type="button" className="btn btn-sm btn-ghost" disabled={busy} onClick={() => end(`/me/sessions/${s.id}`, 'Sign out session')}>Sign out</button>}
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
