import { Component, type ErrorInfo, type ReactNode } from 'react'
import { Link } from 'react-router'
import { Icon } from './Icon'
import { useDocumentTitle } from '../lib/useDocumentTitle'

function Shell({ icon, tone, code, title, body, actions, children }: { icon: string; tone: 'b' | 'r'; code?: string; title: string; body: ReactNode; actions: ReactNode; children?: ReactNode }) {
  return (
    <div className="flex flex-1 items-center justify-center py-16 px-4">
      <div className="flex max-w-md flex-col items-center gap-3 text-center">
        <span aria-hidden="true" className={`grid h-14 w-14 place-items-center rounded-2xl ${tone === 'r' ? 'bg-r-bg text-r-fg' : 'bg-b-bg text-b-fg'}`}><Icon name={icon} size={26} /></span>
        {code && <div className="font-mono text-xs font-semibold tracking-widest text-muted">{code}</div>}
        <h1 className="m-0 text-2xl font-semibold tracking-tight">{title}</h1>
        <p className="m-0 text-sm text-muted">{body}</p>
        <div className="mt-2 flex flex-wrap justify-center gap-2">{actions}</div>
        {children}
      </div>
    </div>
  )
}

/** Shown for any address the app does not know. */
export function NotFound() {
  useDocumentTitle('Page not found')
  return (
    <Shell icon="search" tone="b" code="404" title="Page not found"
      body="The address may be mistyped, or the page was moved. Nothing has changed in your account."
      actions={<>
        <button type="button" className="btn" onClick={() => window.history.back()}>Go back</button>
        <Link to="/" className="btn btn-primary no-underline">Go to the dashboard</Link>
      </>} />
  )
}

/**
 * Catches a page that fails while drawing, so one broken screen never blanks the whole panel.
 * Keyed by the address by its parent, so moving to another page clears it.
 */
export class PageErrorBoundary extends Component<{ children: ReactNode }, { error: Error | null }> {
  state = { error: null as Error | null }
  static getDerivedStateFromError(error: Error) { return { error } }
  componentDidCatch(error: Error, info: ErrorInfo) { console.error('Page failed to render', error, info.componentStack) }
  render() {
    const { error } = this.state
    if (!error) return this.props.children
    return (
      <Shell icon="warn" tone="r" title="This page could not be shown"
        body="Something went wrong while loading it. Your data is safe; reloading usually fixes it. If it keeps happening, send the details below to support."
        actions={<>
          <button type="button" className="btn" onClick={() => this.setState({ error: null })}>Try again</button>
          <button type="button" className="btn btn-primary" onClick={() => window.location.reload()}>Reload the page</button>
        </>}>
        <details className="mt-3 w-full text-left text-xs">
          <summary className="cursor-pointer text-muted">Technical details</summary>
          <pre className="mt-2 max-h-48 overflow-auto whitespace-pre-wrap rounded-lg bg-head p-3 font-mono">{error.message}</pre>
        </details>
      </Shell>
    )
  }
}

/** MFA-POL: the organisation requires two-step verification and this person has none yet. */
export function MfaRequired({ accountPath }: { accountPath: string }) {
  useDocumentTitle('Set up two-step verification')
  return (
    <Shell icon="shield" tone="b" title="Set up two-step verification"
      body="Your organisation asks every member to use an authenticator app or a passkey when signing in. It takes about a minute, and then everything opens up again."
      actions={<Link to={accountPath} className="btn btn-primary no-underline">Set it up now</Link>} />
  )
}
