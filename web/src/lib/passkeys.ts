import { browserSupportsWebAuthn, startAuthentication, startRegistration } from '@simplewebauthn/browser'
import { api } from '../api/client'
import { unwrap } from './format'

/** Passkeys (WebAuthn) against the API's /auth/passkey/* and /me/passkeys/* endpoints. */
export interface Passkey { id: string; name: string; aaguid?: string | null; transports: string[]; backup_eligible: boolean; created_at: string; last_used_at?: string | null }
interface Challenge { challenge_id: string; options: Parameters<typeof startRegistration>[0]['optionsJSON'] & Parameters<typeof startAuthentication>[0]['optionsJSON'] }

export const passkeysSupported = () => { try { return browserSupportsWebAuthn() } catch { return false } }

/** Registers a new passkey for the signed-in user. */
export async function registerPasskey(name: string): Promise<Passkey> {
  const c = unwrap<Challenge>(await api.post('/me/passkeys/options'))
  const credential = await startRegistration({ optionsJSON: c.options })
  return unwrap<Passkey>(await api.post('/me/passkeys', { challenge_id: c.challenge_id, name, credential }))
}

/** Gets an assertion; with an email only that account's passkeys are offered (second factor). */
export async function passkeyAssertion(email?: string): Promise<{ challenge_id: string; credential: unknown }> {
  const c = unwrap<Challenge>(await api.post('/auth/passkey/options', email ? { email } : {}, { retries: 0 }))
  const credential = await startAuthentication({ optionsJSON: c.options })
  return { challenge_id: c.challenge_id, credential }
}

/** User-facing message for a WebAuthn browser error (cancelled, timed out, not allowed). */
export function passkeyError(e: unknown): string {
  const name = (e as { name?: string })?.name
  if (name === 'NotAllowedError' || name === 'AbortError') return 'The passkey request was cancelled or timed out.'
  if (name === 'InvalidStateError') return 'This device already has a passkey for your account.'
  return (e as Error)?.message || 'The passkey could not be used.'
}
