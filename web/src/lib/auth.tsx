import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, onUnauthenticated, tokenStore } from '../api/client'
import type { Me, User } from '../api/types'
import { unwrap } from './format'
import { passkeyAssertion } from './passkeys'

interface AuthCtx {
  me: Me | null
  loading: boolean
  login: (email: string, password: string, otp?: string) => Promise<Me | null>
  /** Passwordless passkey sign-in, or (with email + password) a passkey as the second factor. */
  loginPasskey: (email?: string, password?: string) => Promise<Me | null>
  register: (b: { name: string; email: string; password: string; organization: string }) => Promise<Me | null>
  logout: () => Promise<void>
  refresh: () => Promise<Me | null>
}
const Ctx = createContext<AuthCtx | null>(null)
export const CUSTOMER_ROLES = ['owner', 'developer', 'billing']
export const homeFor = (me: Me | null) => (me && CUSTOMER_ROLES.includes(String(me.role).toLowerCase()) ? '/app' : '/admin')

export function AuthProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null)
  const [loading, setLoading] = useState(!!tokenStore.get())
  const qc = useQueryClient()

  const refresh = useCallback(async () => {
    if (!tokenStore.get()) { setMe(null); setLoading(false); return null }
    try {
      const m = unwrap<Me>(await api.get('/me'))
      setMe(m); return m
    } catch { setMe(null); return null } finally { setLoading(false) }
  }, [])

  useEffect(() => { refresh() }, [refresh])
  useEffect(() => onUnauthenticated(() => { setMe(null); qc.clear() }), [qc])

  const accept = useCallback(async (res: unknown) => {
    const r = unwrap<{ token: string; user: User }>(res)
    tokenStore.set(r.token)
    return refresh()
  }, [refresh])

  const login = useCallback(async (email: string, password: string, otp?: string) =>
    accept(await api.post('/auth/login', { email, password, ...(otp ? { otp } : {}) }, { retries: 0 })), [accept])
  const loginPasskey = useCallback(async (email?: string, password?: string) => {
    if (email && password) {
      const passkey = await passkeyAssertion(email)
      return accept(await api.post('/auth/login', { email, password, passkey }, { retries: 0 }))
    }
    const a = await passkeyAssertion()
    return accept(await api.post('/auth/passkey/login', a, { retries: 0 }))
  }, [accept])
  const register = useCallback(async (b: { name: string; email: string; password: string; organization: string }) =>
    accept(await api.post('/auth/register', b, { retries: 0 })), [accept])
  const logout = useCallback(async () => {
    try { await api.post('/auth/logout') } catch { /* already gone */ }
    tokenStore.clear(); setMe(null); qc.clear()
  }, [qc])

  return <Ctx.Provider value={{ me, loading, login, loginPasskey, register, logout, refresh }}>{children}</Ctx.Provider>
}
export function useAuth() {
  const c = useContext(Ctx)
  if (!c) throw new Error('useAuth outside AuthProvider')
  return c
}
