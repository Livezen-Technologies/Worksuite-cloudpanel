import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router'
import { isApiError } from '../../api/client'
import { homeFor, useAuth } from '../../lib/auth'
import { passkeyError } from '../../lib/passkeys'
import { Brand } from '../../components/Shell'
import { Field } from '../../components/Card'

function AuthCard({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
  return (
    <main className="min-h-screen flex items-center justify-center p-4 bg-bg">
      <div className="card w-full max-w-[420px] p-6 sm:p-8 flex flex-col gap-5">
        <Brand title="Docker Manager" subtitle="Portal" />
        <div><h1 className="m-0 text-2xl font-bold">{title}</h1><p className="m-0 mt-1 text-sm text-muted">{subtitle}</p></div>
        {children}
      </div>
    </main>
  )
}

export function Login() {
  const { login, loginPasskey, me } = useAuth()
  const nav = useNavigate()
  const loc = useLocation() as { state?: { from?: string } }
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [otp, setOtp] = useState('')
  const [needOtp, setNeedOtp] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [fields, setFields] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  if (me) return <Navigate to={loc.state?.from ?? homeFor(me)} replace />
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setErr(null); setFields({})
    try {
      const m = await login(email, password, otp || undefined)
      nav(loc.state?.from ?? homeFor(m), { replace: true })
    } catch (ex) {
      if (isApiError(ex)) {
        if (ex.fields.otp || /otp|two-factor|mfa/i.test(ex.message)) setNeedOtp(true)
        setFields(Object.fromEntries(Object.entries(ex.fields).map(([k, v]) => [k, v[0]])))
        setErr(ex.status === 401 || ex.status === 422 ? (ex.fields.otp ? 'Enter the 6-digit code from your authenticator app.' : ex.message) : ex.message)
      } else setErr('Could not sign in. Try again.')
    } finally { setBusy(false) }
  }
  const withPasskey = async (secondFactor: boolean) => {
    setBusy(true); setErr(null)
    try {
      const m = secondFactor ? await loginPasskey(email, password) : await loginPasskey()
      nav(loc.state?.from ?? homeFor(m), { replace: true })
    } catch (ex) {
      setErr(isApiError(ex) ? ex.message : passkeyError(ex))
    } finally { setBusy(false) }
  }
  return (
    <AuthCard title="Sign in" subtitle="Use your work email and password, or a passkey.">
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Email" error={fields.email}><input className="input" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
        <Field label="Password" error={fields.password}><input className="input" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} /></Field>
        <Field label={needOtp ? 'Authentication code' : 'Authentication code (if two-factor is on)'} error={fields.otp}>
          <input className="input font-mono tracking-widest" inputMode="numeric" autoComplete="one-time-code" pattern="\d{6}" maxLength={6} value={otp} onChange={(e) => setOtp(e.target.value.replace(/\D/g, ''))} placeholder="123456" />
        </Field>
        {err && <div role="alert" className="text-sm font-semibold text-danger">{err}</div>}
        <button className="btn btn-primary min-h-11" disabled={busy}>{busy ? 'Signing in…' : 'Sign in'}</button>
        {needOtp && <button type="button" className="btn min-h-11" disabled={busy} onClick={() => withPasskey(true)}>Use a passkey instead of a code</button>}
      </form>
      <div className="flex items-center gap-3 text-xs text-muted" aria-hidden="true"><span className="flex-1 border-t border-line" />or<span className="flex-1 border-t border-line" /></div>
      <button type="button" className="btn min-h-11" disabled={busy} onClick={() => withPasskey(false)}>Sign in with a passkey</button>
      <p className="m-0 text-sm text-muted">New here? <Link to="/register" className="font-semibold">Create an account</Link></p>
    </AuthCard>
  )
}

export function Register() {
  const { register, me } = useAuth()
  const nav = useNavigate()
  const [f, setF] = useState({ name: '', email: '', password: '', organization: '' })
  const [fields, setFields] = useState<Record<string, string>>({})
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  if (me) return <Navigate to={homeFor(me)} replace />
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value })
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setErr(null)
    const local: Record<string, string> = {}
    if (!f.name.trim()) local.name = 'Enter your name.'
    if (!/^\S+@\S+\.\S+$/.test(f.email)) local.email = 'Enter a valid email address.'
    if (f.password.length < 8) local.password = 'Use at least 8 characters.'
    if (!f.organization.trim()) local.organization = 'Enter your organisation or company name.'
    setFields(local)
    if (Object.keys(local).length) { setBusy(false); return }
    try {
      const m = await register(f)
      nav(homeFor(m), { replace: true })
    } catch (ex) {
      if (isApiError(ex)) { setFields(Object.fromEntries(Object.entries(ex.fields).map(([k, v]) => [k, v[0]]))); setErr(ex.message) } else setErr('Could not create the account.')
    } finally { setBusy(false) }
  }
  return (
    <AuthCard title="Create your account" subtitle="Start hosting in a few minutes.">
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Full name" error={fields.name}><input className="input" autoComplete="name" value={f.name} onChange={set('name')} /></Field>
        <Field label="Email" error={fields.email}><input className="input" type="email" autoComplete="email" value={f.email} onChange={set('email')} /></Field>
        <Field label="Password" error={fields.password} hint="At least 8 characters"><input className="input" type="password" autoComplete="new-password" value={f.password} onChange={set('password')} /></Field>
        <Field label="Organisation" error={fields.organization}><input className="input" autoComplete="organization" value={f.organization} onChange={set('organization')} /></Field>
        {err && <div role="alert" className="text-sm font-semibold text-danger">{err}</div>}
        <button className="btn btn-primary min-h-11" disabled={busy}>{busy ? 'Creating…' : 'Create account'}</button>
      </form>
      <p className="m-0 text-sm text-muted">Already have an account? <Link to="/login" className="font-semibold">Sign in</Link></p>
    </AuthCard>
  )
}
