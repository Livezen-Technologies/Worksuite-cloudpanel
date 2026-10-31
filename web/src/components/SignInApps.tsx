import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { Badge } from './Badge'
import { Card, Toggle } from './Card'
import { useToast } from './Toasts'
import { ago, unwrap } from '../lib/format'

export type SignInApp = { key: string; label: string; email: string | null; last_used_at: string | null; blocked: boolean }

/** SSO-ID: the sign-in apps the platform offers, the person's use of each, and a switch to turn one off. */
export function SignInApps() {
  const toast = useToast()
  const [apps, setApps] = useState<SignInApp[] | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  useEffect(() => { api.get('/me/sign-in-apps').then((r) => setApps(unwrap<SignInApp[]>(r))).catch(() => setApps([])) }, [])
  if (!apps || apps.length === 0) return null
  const set = async (a: SignInApp, allowed: boolean) => {
    setBusy(a.key)
    try {
      setApps(unwrap<SignInApp[]>(await api.patch(`/me/sign-in-apps/${a.key}`, { blocked: !allowed })))
      toast.success(allowed ? `${a.label} sign-in is on` : `${a.label} sign-in is off for your account`)
    } catch (e) { toast.error(e, 'Could not change that sign-in app') } finally { setBusy(null) }
  }
  return (
    <Card title="Sign-in apps" subtitle="Sign in with these instead of your password. Turn one off to stop it being used for your account.">
      <ul className="m-0 p-0 list-none">
        {apps.map((a) => (
          <li key={a.key} className="flex items-center gap-3 py-3 border-t border-line first:border-t-0">
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 text-sm font-semibold">{a.label}
                {a.blocked ? <Badge tone="n">off</Badge> : a.last_used_at ? <Badge tone="g">used</Badge> : <Badge tone="n">not used yet</Badge>}
              </div>
              <div className="text-xs text-muted truncate">
                {a.blocked ? 'Signing in with this app is turned off for your account.' : a.last_used_at ? `${a.email} · last used ${ago(a.last_used_at)}` : 'Works with the same email address as this account.'}
              </div>
            </div>
            <Toggle checked={!a.blocked} disabled={busy === a.key} label={`Allow signing in with ${a.label}`} onChange={(v) => set(a, v)} />
          </li>
        ))}
      </ul>
    </Card>
  )
}
