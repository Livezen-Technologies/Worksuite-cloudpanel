import { api, tokenStore } from '../api/client'

/** CUS-3: the admin's own token waits here while they are signed in as a customer. */
const STAFF_KEY = 'dmp.staff-token'
const RETURN_KEY = 'dmp.staff-return'

export async function startImpersonation(customerId: string, reason: string) {
  const res = await api.post<{ data: { token: string } }>(`/customers/${customerId}/impersonate`, { reason })
  const staff = tokenStore.get()
  try {
    if (staff) sessionStorage.setItem(STAFF_KEY, staff)
    sessionStorage.setItem(RETURN_KEY, `/admin/customers/${customerId}`)
  } catch { /* storage unavailable: ending the session signs out instead */ }
  tokenStore.set(res.data.token)
  window.location.assign('/app')
}

export async function endImpersonation() {
  try { await api.post('/auth/logout') } catch { /* the token may already have expired */ }
  let staff: string | null = null, back = '/admin'
  try { staff = sessionStorage.getItem(STAFF_KEY); back = sessionStorage.getItem(RETURN_KEY) ?? back; sessionStorage.removeItem(STAFF_KEY); sessionStorage.removeItem(RETURN_KEY) } catch { /* ignore */ }
  if (staff) tokenStore.set(staff); else tokenStore.clear()
  window.location.assign(staff ? back : '/login')
}
