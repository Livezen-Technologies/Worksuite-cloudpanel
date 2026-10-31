import { useEffect, useState, type FormEvent } from 'react'
import { api, isApiError } from '../../api/client'
import { useAction, useOne } from '../../api/hooks'
import { Card, Field, PageHeader, FormActions } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { PasskeyManager } from '../../components/Passkeys'
import { Tabs } from '../../components/Tabs'
import { Icon } from '../../components/Icon'
import { OtpInput, TwoFactorSetupModal } from '../../components/TwoFactorSetup'
import { useToast } from '../../components/Toasts'
import { useAuth } from '../../lib/auth'
import { ago, unwrap } from '../../lib/format'
import type { R } from '../../lib/util'

type AccountTab = 'profile' | 'security' | 'sessions'

/** Your profile, password, two-factor, passkeys and sessions (admin panel), in tabs. */
export function AdminAccount() {
  const { me, refresh } = useAuth()
  const u = me?.user
  const [tab, setTab] = useState<AccountTab>(() => (['profile', 'security', 'sessions'].includes(location.hash.slice(1)) ? location.hash.slice(1) : 'profile') as AccountTab)
  useEffect(() => { history.replaceState(null, '', `#${tab}`) }, [tab])
  const name = String(u?.name ?? '')
  const email = String(u?.email ?? '')
  const initials = (name || email).split(/\s+/).map((x) => x[0]).join('').slice(0, 2).toUpperCase()
  const mfa = !!u?.mfa_enabled
  return (
    <>
      <PageHeader title="Account" subtitle="Your profile and how you sign in" />
      <div className="card p-5 flex flex-wrap items-center gap-4">
        <span aria-hidden="true" className="w-14 h-14 rounded-full bg-accent text-white text-lg font-bold grid place-items-center flex-none">{initials}</span>
        <div className="min-w-0 flex-1">
          <div className="text-lg font-semibold truncate">{name}</div>
          <div className="text-sm text-muted truncate">{email}</div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Badge tone="b">{String(me?.role ?? '').replace(/_/g, ' ')}</Badge>
          <Badge tone={mfa ? 'g' : 'y'}>{mfa ? 'two-factor on' : 'two-factor off'}</Badge>
          {u?.email_verified === false && <Badge tone="y">email not verified</Badge>}
        </div>
      </div>
      <Tabs label="Account sections" value={tab} onChange={setTab} tabs={[{ id: 'profile', label: 'Profile' }, { id: 'security', label: 'Security' }, { id: 'sessions', label: 'Sessions' }]} />
      <div className="-mt-1 grid gap-4 lg:grid-cols-2 items-start">
        {tab === 'profile' && <Profile name={name} email={email} onSaved={refresh} />}
        {tab === 'security' && <>
          <ChangePassword />
          <TwoFactor on={mfa} onChange={refresh} />
          <PasskeyManager />
        </>}
        {tab === 'sessions' && <Sessions />}
      </div>
    </>
  )
}

function Profile({ name, email, onSaved }: { name: string; email: string; onSaved: () => unknown }) {
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
  return (
    <Card title="Profile" subtitle="Your name and the address we email">
      <form className="flex flex-col gap-4" onSubmit={submit}>
        <Field label="Full name"><input className="input" required maxLength={120} autoComplete="name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="Email" hint={emailChanged ? 'You will need to verify the new address.' : undefined}><input className="input" type="email" required autoComplete="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        {emailChanged && <Field label="Current password" hint="Needed to change your email."><input className="input" type="password" required autoComplete="current-password" value={f.current_password} onChange={(e) => setF({ ...f, current_password: e.target.value })} /></Field>}
        <FormActions><button className="btn btn-primary" disabled={busy || (f.name === name && !emailChanged)}>Save</button></FormActions>
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
        <FormActions><button className="btn btn-primary" disabled={busy || mismatch || f.password.length < 10}>Change password</button></FormActions>
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
  const confirm = async (code: string) => {
    try { await api.post('/me/mfa/confirm', { otp: code }); toast.success('Two-factor is on'); setSetup(null); onChange(); return true } catch { return false }
  }
  const turnOff = async () => {
    try { await api.post('/me/mfa/disable', { otp }); toast.success('Two-factor is off'); setOff(false); setOtp(''); onChange() }
    catch (e) { toast.error(e, 'That code did not work. Check your authenticator app and try again.') }
  }
  return (
    <Card title="Two-factor authentication" actions={on ? <Badge tone="g">on</Badge> : <Badge tone="n">off</Badge>}>
      <div className="flex items-start gap-3 mb-4">
        <span aria-hidden="true" className={`grid place-items-center w-10 h-10 rounded-xl flex-none ${on ? 'bg-g-bg text-g-fg' : 'bg-head text-muted'}`}><Icon name="shield" size={18} /></span>
        <p className="m-0 text-sm text-muted">{on ? 'Every sign-in asks for a code from your authenticator app.' : 'Protect your account with a code from an authenticator app on your phone, as well as your password.'}</p>
      </div>
      {off ? (
        <div className="flex flex-col gap-3">
          <span className="text-sm">Enter a code from your app to turn two-factor off.</span>
          <OtpInput value={otp} onChange={setOtp} />
          <div className="flex gap-2"><button type="button" className="btn btn-danger" disabled={otp.length !== 6} onClick={turnOff}>Turn off</button>
            <button type="button" className="btn btn-ghost" onClick={() => { setOff(false); setOtp('') }}>Cancel</button></div>
        </div>
      ) : on ? <button type="button" className="btn" onClick={() => setOff(true)}>Turn off…</button> : <button type="button" className="btn btn-primary" onClick={start}>Set up two-factor</button>}
      <TwoFactorSetupModal setup={setup} onClose={() => setSetup(null)} onConfirm={confirm} />
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
