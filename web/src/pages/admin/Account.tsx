import { Card, PageHeader } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { PasskeyManager } from '../../components/Passkeys'
import { useAuth } from '../../lib/auth'

/** Admin panel account page: who you are and how you sign in (passkeys, two-factor). */
export function AdminAccount() {
  const { me } = useAuth()
  const u = me?.user
  return (
    <>
      <PageHeader title="Account" subtitle="Your profile and sign-in security" />
      <div className="flex flex-wrap gap-4 items-start">
        <Card className="flex-[1_1_320px]" title="Profile">
          <dl className="m-0 grid grid-cols-[max-content_1fr] gap-x-4 gap-y-2 text-sm">
            <dt className="text-muted">Name</dt><dd className="m-0 font-semibold">{u?.name}</dd>
            <dt className="text-muted">Email</dt><dd className="m-0">{u?.email}</dd>
            <dt className="text-muted">Role</dt><dd className="m-0"><Badge tone="n">{String(me?.role ?? '')}</Badge></dd>
            <dt className="text-muted">Two-factor</dt><dd className="m-0">{u?.mfa_enabled ? <Badge tone="g">on</Badge> : <Badge tone="n">off</Badge>}</dd>
          </dl>
        </Card>
        <div className="flex-[1_1_320px] min-w-0"><PasskeyManager /></div>
      </div>
    </>
  )
}
