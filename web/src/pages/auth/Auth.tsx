import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, Navigate, useLocation, useNavigate, useSearchParams } from 'react-router'
import { api, isApiError } from '../../api/client'
import { homeFor, useAuth } from '../../lib/auth'
import { passkeyError } from '../../lib/passkeys'
import { Field } from '../../components/Card'

function AuthCard({ title, subtitle, children, footer }: { title: string; subtitle: string; children: ReactNode; footer?: ReactNode }) {
  return (
    <main className="relative min-h-screen overflow-hidden bg-bg">
      {/* Ambient gradient wash */}
      <div aria-hidden="true" className="pointer-events-none absolute inset-0">
        <div className="absolute -top-44 -right-28 h-60 w-[36rem] -rotate-[10deg] rounded-full blur-3xl opacity-70 dark:opacity-30 bg-[linear-gradient(115deg,#fff1be_28%,#ee87cb_70%,#b060ff)]" />
        <div className="absolute -bottom-52 -left-40 h-60 w-[32rem] rotate-[8deg] rounded-full blur-3xl opacity-50 dark:opacity-20 bg-[linear-gradient(115deg,#b060ff,#ee87cb_40%,#fff1be)]" />
      </div>

      <div className="relative isolate flex min-h-screen items-center justify-center p-6 lg:p-8">
        <div className="w-full max-w-md rounded-2xl bg-card shadow-xl ring-1 ring-black/5 dark:ring-white/10">
          <div className="flex flex-col gap-6 p-7 sm:p-10">
            <span className="flex px-2 py-1">
              <img src="/worksuite-logo-light.png" alt="WorkSuite" className="h-8 w-auto dark:hidden" />
              <img src="/worksuite-logo-dark.png" alt="WorkSuite" className="hidden h-8 w-auto dark:block" />
            </span>
            <div>
              <h1 className="m-0 text-xl font-semibold tracking-tight">{title}</h1>
              <p className="m-0 mt-1.5 text-sm/5 text-muted">{subtitle}</p>
            </div>
            {children}
          </div>
          {footer && (
            <div className="m-1.5 rounded-xl bg-bg py-4 text-center text-sm/5 ring-1 ring-black/5 dark:ring-white/10">
              {footer}
            </div>
          )}
        </div>
      </div>
    </main>
  )
}

export function Login() {
  const { login, loginPasskey, me } = useAuth()
  const nav = useNavigate()
  const loc = useLocation() as { state?: { from?: string; reset?: boolean } }
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
    <AuthCard title="Welcome back" subtitle="Sign in to your account to continue." footer={<>Not a member? <Link to="/register" className="font-medium">Create an account</Link></>}>
      {loc.state?.reset && <p role="status" className="m-0 -mt-2 text-sm text-[var(--g-fg)]">Your password is changed. Sign in with the new one.</p>}
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Email" error={fields.email}><input className="input" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
        <Field label="Password" error={fields.password}><input className="input" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} /></Field>
        <Link to="/forgot-password" className="link-action -mt-2 self-end font-medium">Forgot password?</Link>
        <Field label={needOtp ? 'Authentication code' : 'Authentication code (if two-factor is on)'} error={fields.otp}>
          <input className="input font-mono tracking-widest" inputMode="numeric" autoComplete="one-time-code" pattern="\d{6}" maxLength={6} value={otp} onChange={(e) => setOtp(e.target.value.replace(/\D/g, ''))} placeholder="123456" />
        </Field>
        {err && <div role="alert" className="text-sm font-semibold text-danger">{err}</div>}
        <button className="btn btn-primary min-h-11" disabled={busy}>{busy ? 'Signing in…' : 'Sign in'}</button>
        {needOtp && <button type="button" className="btn min-h-11" disabled={busy} onClick={() => withPasskey(true)}>Use a passkey instead of a code</button>}
      </form>
      <div className="flex items-center gap-3 text-xs text-muted" aria-hidden="true"><span className="flex-1 border-t border-line" />or<span className="flex-1 border-t border-line" /></div>
      <button type="button" className="btn min-h-11" disabled={busy} onClick={() => withPasskey(false)}>Sign in with a passkey</button>
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
    <AuthCard title="Create your account" subtitle="Start hosting in a few minutes." footer={<>Already have an account? <Link to="/login" className="font-medium">Sign in</Link></>}>
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Full name" error={fields.name}><input className="input" autoComplete="name" value={f.name} onChange={set('name')} /></Field>
        <Field label="Email" error={fields.email}><input className="input" type="email" autoComplete="email" value={f.email} onChange={set('email')} /></Field>
        <Field label="Password" error={fields.password} hint="At least 8 characters"><input className="input" type="password" autoComplete="new-password" value={f.password} onChange={set('password')} /></Field>
        <Field label="Organisation" error={fields.organization}><input className="input" autoComplete="organization" value={f.organization} onChange={set('organization')} /></Field>
        {err && <div role="alert" className="text-sm font-semibold text-danger">{err}</div>}
        <button className="btn btn-primary min-h-11" disabled={busy}>{busy ? 'Creating…' : 'Create account'}</button>
      </form>
    </AuthCard>
  )
}

export function ForgotPassword() {
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setErr(null)
    try { await api.post('/auth/forgot-password', { email: email.trim() }); setSent(true) } catch (ex) { setErr(isApiError(ex) ? ex.message : 'Could not send the link. Try again.') } finally { setBusy(false) }
  }
  return (
    <AuthCard title="Reset your password" subtitle="We'll email you a link to choose a new one." footer={<>Remembered it? <Link to="/login" className="font-medium">Sign in</Link></>}>
      {sent
        ? <p role="status" className="m-0 text-sm">If an account uses <strong>{email}</strong>, a reset link is on its way. It works for an hour.</p>
        : <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <Field label="Email"><input className="input" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
          {err && <div role="alert" className="text-sm font-semibold text-danger">{err}</div>}
          <button className="btn btn-primary min-h-11" disabled={busy || !/^\S+@\S+\.\S+$/.test(email)}>{busy ? 'Sending…' : 'Send reset link'}</button>
        </form>}
    </AuthCard>
  )
}

export function ResetPassword() {
  const [params] = useSearchParams()
  const nav = useNavigate()
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const email = params.get('email') ?? ''
  const token = params.get('token') ?? ''
  const mismatch = confirm.length > 0 && confirm !== password
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setErr(null)
    try {
      await api.post('/auth/reset-password', { email, token, password })
      nav('/login', { replace: true, state: { reset: true } })
    } catch (ex) { setErr(isApiError(ex) ? (ex.field('token') ?? ex.field('password') ?? ex.message) : 'Could not reset the password.') } finally { setBusy(false) }
  }
  return (
    <AuthCard title="Choose a new password" subtitle={email ? `For ${email}. Every device is signed out afterwards.` : 'This link is missing its details.'} footer={<><Link to="/forgot-password" className="font-medium">Send a new link</Link></>}>
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="New password" hint="At least 8 characters"><input className="input" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} /></Field>
        <Field label="Repeat it" error={mismatch ? 'The passwords do not match.' : undefined}><input className="input" type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} /></Field>
        {err && <div role="alert" className="text-sm font-semibold text-danger">{err}</div>}
        <button className="btn btn-primary min-h-11" disabled={busy || !email || !token || password.length < 8 || confirm !== password}>{busy ? 'Saving…' : 'Set new password'}</button>
      </form>
    </AuthCard>
  )
}

export function VerifyEmail() {
  const [params] = useSearchParams()
  const [state, setState] = useState<{ ok: boolean | null; message: string }>({ ok: null, message: 'Confirming…' })
  const once = useRef(false)
  useEffect(() => {
    if (once.current) return
    once.current = true
    api.post<{ data: { email: string } }>('/auth/verify-email', { id: params.get('id') ?? '', token: params.get('token') ?? '' })
      .then((r) => setState({ ok: true, message: `${r.data.email} is confirmed. Thank you.` }))
      .catch((ex) => setState({ ok: false, message: isApiError(ex) ? (ex.field('token') ?? ex.message) : 'Could not confirm the address.' }))
  }, [params])
  return (
    <AuthCard title="Confirm your email" subtitle="" footer={<Link to="/login" className="font-medium">Continue to sign in</Link>}>
      <p role={state.ok === false ? 'alert' : 'status'} className={`m-0 text-sm ${state.ok === false ? 'text-danger font-semibold' : ''}`}>{state.message}</p>
    </AuthCard>
  )
}
