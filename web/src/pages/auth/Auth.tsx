import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, Navigate, useLocation, useNavigate, useParams, useSearchParams } from 'react-router'
import { api, isApiError } from '../../api/client'
import { homeFor, useAuth } from '../../lib/auth'
import { passkeyError } from '../../lib/passkeys'
import { Field } from '../../components/Card'
import { OtpInput } from '../../components/TwoFactorSetup'

type Side = 'login' | 'signup' | 'forgot' | 'reset' | 'invite' | 'verify'
const SIDES: Record<Side, [string, string, string, [string, string][]]> = {
  login: ['Welcome back', 'Your apps, sites and servers in one place', 'Deploy from Git, manage domains and email, and watch every site from one dashboard.', [['24/7', 'monitoring'], ['Free', 'SSL on every site'], ['2FA', 'and passkeys']]],
  signup: ['Get started', 'Host your apps in minutes', 'Free HTTPS, backups and a WordPress toolkit on every plan.', [['2 min', 'to first deploy'], ['Free', 'SSL'], ['0', 'setup fees']]],
  forgot: ['Account recovery', 'We’ll get you back in', 'Reset links expire after 60 minutes and can be used once.', [['60 min', 'link expiry'], ['1', 'use per link'], ['24/7', 'support']]],
  reset: ['Account recovery', 'Choose a new password', 'Every device is signed out once you change it, so only you get back in.', [['10+', 'characters'], ['All', 'devices signed out'], ['1', 'use per link']]],
  invite: ['Invitation', 'Join your team', 'Accept the invitation to start working with your organisation.', [['14 d', 'link valid'], ['2FA', 'recommended'], ['1', 'use per link']]],
  verify: ['Almost there', 'Confirm your email', 'We use it for sign-in, alerts and invoices.', [['1', 'click'], ['Secure', 'link'], ['24/7', 'support']]],
}

/** Split sign-in layout from the design: a brand panel on the left, the form on the right. */
function AuthCard({ title, subtitle, children, footer, side = 'login' }: { title: string; subtitle: string; children: ReactNode; footer?: ReactNode; side?: Side }) {
  const [eyebrow, headline, body, stats] = SIDES[side]
  return (
    <div className="min-h-screen flex flex-wrap bg-bg text-fg">
      <aside className="hidden lg:flex flex-[1_1_520px] min-h-screen flex-col justify-between p-12 text-white"
        style={{ background: 'radial-gradient(1200px 600px at 10% 0%, #1E40AF 0%, transparent 60%), radial-gradient(900px 500px at 100% 100%, #0EA5E9 0%, transparent 55%), #0B1120' }}>
        <span className="flex items-center gap-2.5">
          <img src="/worksuite-logo-dark.png" alt="WorkSuite" className="h-8 w-auto" />
          <span className="font-mono text-[11px] tracking-[.1em] text-[#93C5FD]">CLOUD</span>
        </span>
        <div className="max-w-[440px]">
          <div className="font-mono text-xs tracking-[.14em] uppercase text-[#93C5FD]">{eyebrow}</div>
          <h2 className="m-0 mt-3 text-4xl leading-[1.12] font-bold tracking-[-0.03em]">{headline}</h2>
          <p className="m-0 mt-3.5 text-[15px] leading-relaxed text-[#CBD5E1]">{body}</p>
          <div className="mt-7 grid grid-cols-3 gap-2.5">
            {stats.map(([v, k]) => (
              <div key={k} className="p-3 rounded-[10px] bg-white/[.06] shadow-[inset_0_0_0_1px_rgba(255,255,255,.1)]">
                <div className="text-xl font-bold">{v}</div><div className="text-[11.5px] text-[#94A3B8] mt-0.5">{k}</div>
              </div>
            ))}
          </div>
        </div>
        <div className="text-xs text-[#64748B]">© {new Date().getFullYear()} Livezen Technologies (Pvt) Ltd</div>
      </aside>
      <main className="flex-[1_1_480px] min-h-screen flex items-center justify-center px-6 py-12">
        <div className="w-full max-w-[400px] flex flex-col gap-[22px]">
          <span className="flex lg:hidden">
            <img src="/worksuite-logo-light.png" alt="WorkSuite" className="h-8 w-auto dark:hidden" />
            <img src="/worksuite-logo-dark.png" alt="" className="hidden h-8 w-auto dark:block" />
          </span>
          <div>
            <h1 className="m-0 text-[26px] font-bold tracking-[-0.02em]">{title}</h1>
            {subtitle && <p className="m-0 mt-1.5 text-sm text-muted">{subtitle}</p>}
          </div>
          {children}
          {footer && <p className="m-0 text-sm text-center">{footer}</p>}
        </div>
      </main>
    </div>
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
    <AuthCard title="Sign in to WorkSuite Cloud" subtitle="Manage your apps, domains, email and servers." footer={<>New here? <Link to="/register" className="font-semibold no-underline">Create an account</Link></>}>
      {loc.state?.reset && <p role="status" className="m-0 -mt-2 text-sm text-[var(--g-fg)]">Your password is changed. Sign in with the new one.</p>}
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {err && <div role="alert" className="px-3 py-2.5 rounded-lg bg-r-bg text-r-fg text-[13px]">{err}</div>}
        <Field label="Email" error={fields.email}><input className="input min-h-11" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
        <div className="flex flex-col gap-1.5">
          <div className="flex justify-between text-[13px] font-semibold"><label htmlFor="login-pw">Password</label><Link to="/forgot-password" className="font-medium no-underline">Forgot password?</Link></div>
          <input id="login-pw" className="input min-h-11" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} aria-invalid={!!fields.password || undefined} />
          {fields.password && <span className="text-xs text-danger">{fields.password}</span>}
        </div>
        {needOtp && (
          <div className="flex flex-col gap-2">
            <span className="text-[13px] font-semibold">Two-step verification</span>
            <span className="text-[13px] text-muted -mt-1">Enter the 6-digit code from your authenticator app.</span>
            <OtpInput value={otp} onChange={setOtp} invalid={!!fields.otp} />
          </div>
        )}
        <button className="btn btn-primary min-h-11" disabled={busy || (needOtp && otp.length !== 6)}>{busy ? 'Signing in…' : needOtp ? 'Verify and sign in' : 'Sign in'}</button>
        {needOtp && <button type="button" className="btn min-h-11" disabled={busy} onClick={() => withPasskey(true)}>Use a passkey instead of a code</button>}
      </form>
      <div className="flex items-center gap-3 text-xs text-muted" aria-hidden="true"><span className="flex-1 border-t border-line" />or<span className="flex-1 border-t border-line" /></div>
      <button type="button" className="btn min-h-11" disabled={busy} onClick={() => withPasskey(false)}>Sign in with a passkey</button>
      <p className="m-0 text-xs text-muted text-center">Protected by rate limiting. Sign-ins are logged.</p>
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
    <AuthCard side="signup" title="Create your account" subtitle="Start hosting in a few minutes." footer={<>Already have an account? <Link to="/login" className="font-medium">Sign in</Link></>}>
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
    <AuthCard side="forgot" title="Forgot your password?" subtitle="We'll email you a link to choose a new one." footer={<>Remembered it? <Link to="/login" className="font-medium">Sign in</Link></>}>
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
    <AuthCard side="reset" title="Choose a new password" subtitle={email ? `For ${email}. Every device is signed out afterwards.` : 'This link is missing its details.'} footer={<><Link to="/forgot-password" className="font-medium">Send a new link</Link></>}>
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="New password" hint="At least 8 characters"><input className="input" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} /></Field>
        <Field label="Repeat it" error={mismatch ? 'The passwords do not match.' : undefined}><input className="input" type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} /></Field>
        {err && <div role="alert" className="text-sm font-semibold text-danger">{err}</div>}
        <button className="btn btn-primary min-h-11" disabled={busy || !email || !token || password.length < 8 || confirm !== password}>{busy ? 'Saving…' : 'Set new password'}</button>
      </form>
    </AuthCard>
  )
}

/** The link in an invitation email: set a name and password (new people), or just join (existing accounts). */
export function InviteAccept() {
  const { token = '' } = useParams()
  const nav = useNavigate()
  const [info, setInfo] = useState<{ email: string; name: string; organization: string; role: string; has_account: boolean } | null>(null)
  const [bad, setBad] = useState<string | null>(null)
  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    api.get<{ data: { email: string; name: string; organization: string; role: string; has_account: boolean } }>(`/auth/invite/${encodeURIComponent(token)}`)
      .then((r) => { setInfo(r.data); setName(r.data.name ?? '') })
      .catch((ex) => setBad(isApiError(ex) ? (ex.field('token') ?? ex.message) : 'This invitation could not be opened.'))
  }, [token])
  const mismatch = confirm.length > 0 && confirm !== password
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setErr(null)
    try {
      await api.post(`/auth/invite/${encodeURIComponent(token)}`, info?.has_account ? {} : { name, password, password_confirmation: confirm })
      nav('/login', { replace: true, state: { email: info?.email } })
    } catch (ex) { setErr(isApiError(ex) ? (ex.field('password') ?? ex.field('name') ?? ex.field('token') ?? ex.message) : 'Could not accept the invitation.') } finally { setBusy(false) }
  }
  if (bad) return <AuthCard side="invite" title="Invitation" subtitle={bad} footer={<Link to="/login" className="font-medium">Go to sign in</Link>}>{null}</AuthCard>
  if (!info) return <AuthCard side="invite" title="Invitation" subtitle="Opening your invitation…">{null}</AuthCard>
  return (
    <AuthCard side="invite" title={`Join ${info.organization}`} subtitle={`You were invited as ${info.role.replace(/_/g, ' ')} with ${info.email}.`}>
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {info.has_account ? <p className="m-0 text-sm">You already have an account. Accept, then sign in with your usual password.</p> : <>
          <Field label="Your name"><input className="input" autoComplete="name" value={name} onChange={(e) => setName(e.target.value)} /></Field>
          <input type="email" className="sr-only" tabIndex={-1} aria-hidden="true" autoComplete="username" value={info.email} readOnly />
          <Field label="Choose a password" hint="At least 10 characters"><input className="input" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} /></Field>
          <Field label="Repeat it" error={mismatch ? 'The passwords do not match.' : undefined}><input className="input" type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} /></Field>
        </>}
        {err && <div role="alert" className="text-sm font-semibold text-danger">{err}</div>}
        <button className="btn btn-primary min-h-11" disabled={busy || (!info.has_account && (!name.trim() || password.length < 10 || confirm !== password))}>{busy ? 'Saving…' : 'Accept invitation'}</button>
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
    <AuthCard side="verify" title="Confirm your email" subtitle="" footer={<Link to="/login" className="font-medium">Continue to sign in</Link>}>
      <p role={state.ok === false ? 'alert' : 'status'} className={`m-0 text-sm ${state.ok === false ? 'text-danger font-semibold' : ''}`}>{state.message}</p>
    </AuthCard>
  )
}
