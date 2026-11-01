import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'
import { api, isApiError } from '../../api/client'
import type { R } from '../../lib/util'

/** UAI: a person approves an AI client (Claude, Cursor…) asking to use this account through MCP. */
export default function OAuthConsent() {
  const [q] = useSearchParams()
  const [info, setInfo] = useState<R | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [write, setWrite] = useState(false)
  const [busy, setBusy] = useState(false)
  const params = { client_id: q.get('client_id') ?? '', redirect_uri: q.get('redirect_uri') ?? '' }

  useEffect(() => {
    api.get<R>('/app/oauth/consent', params).then((r) => setInfo((r as R).data as R)).catch((e) => setErr(isApiError(e) ? e.message : 'This sign-in request is not valid.'))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q])

  const answer = async (approve: boolean) => {
    setBusy(true)
    try {
      const r = await api.post<R>('/app/oauth/consent', { ...params, code_challenge: q.get('code_challenge') ?? '', state: q.get('state') ?? undefined, approve, write })
      const to = String(((r as R).data as R).redirect ?? '')
      if (to) window.location.assign(to)
    } catch (e) {
      setErr(isApiError(e) ? e.message : 'Could not finish the sign-in.')
      setBusy(false)
    }
  }

  return (
    <main id="main" className="min-h-screen grid place-items-center p-4">
      <div className="card p-6 w-full max-w-md flex flex-col gap-4">
        {err ? <><h1 className="m-0 text-lg font-semibold">Can't connect</h1><p role="alert" className="m-0 text-sm text-danger">{err}</p></> : !info ? <p className="m-0 text-sm text-muted">Loading…</p> : <>
          <h1 className="m-0 text-lg font-semibold">Connect {String(info.name)}?</h1>
          <p className="m-0 text-sm"><span className="font-semibold">{String(info.name)}</span> wants to use the apps in your account through MCP. It will return to <code>{String(info.redirect_host)}</code>.</p>
          <ul className="m-0 pl-5 text-sm text-muted flex flex-col gap-1">
            <li>See your projects, apps and their logs.</li>
            <li>{write ? 'Restart and redeploy apps, within your AI permissions (changes may still need your approval).' : 'No changes: read-only.'}</li>
          </ul>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={write} onChange={(e) => setWrite(e.target.checked)} /> Allow changes</label>
          <div className="flex justify-end gap-2">
            <button type="button" className="btn" disabled={busy} onClick={() => answer(false)}>Deny</button>
            <button type="button" className="btn btn-primary" disabled={busy} onClick={() => answer(true)}>Connect</button>
          </div>
          <p className="m-0 text-xs text-muted">You can disconnect it any time under AI assistant & MCP → Connect AI.</p>
        </>}
      </div>
    </main>
  )
}
