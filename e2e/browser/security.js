// Terminal relay + passkeys, end to end: browser (xterm) -> Vite proxy -> dockermgr-relay -> agent (outbound
// control + dial-back) -> Docker exec in the e2e-term container (image e2e/shell:1, static mini shell, uid 1000),
// and WebAuthn with Chromium's virtual authenticator (CDP). Needs the stack from ../run.sh incl. the relay.
const { execFileSync, spawn } = require('child_process')
const fs = require('fs')
const path = require('path')
const { test, run, login, settle, shot, assert, apiCall, apiLogin, WEB, API, OUT } = require('./lib')

const ROOT = path.resolve(__dirname, '..', '..')

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
async function waitFor(fn, ms = 60000, msg = 'condition') {
  const t0 = Date.now()
  for (;;) { const v = await fn(); if (v) return v; if (Date.now() - t0 > ms) throw new Error(`timeout waiting for ${msg}`); await sleep(500) }
}
const tok = {}
async function api(method, p, body, email = 'admin@example.com') { tok[email] ??= await apiLogin(email); return apiCall(method, p, tok[email], body) }
function psql(sql) {
  return execFileSync('psql', ['-h', process.env.DB_HOST || '127.0.0.1', '-U', process.env.DB_USERNAME || 'dmp', '-d', process.env.DB_DATABASE || 'dmp', '-tAc', sql],
    { env: { ...process.env, PGPASSWORD: process.env.DB_PASSWORD || 'dmp' } }).toString().trim()
}
const termText = (page, sel = '[data-testid=terminal]') => page.locator(`${sel} .xterm-rows`).innerText().catch(() => '')

/** Own agent for these tests (heartbeat 2 s), so the suite also runs on a stack without agent.sh. */
let agentProc = null
let serverId = null
async function ensureAgent() {
  if (serverId) return serverId
  const bin = path.join(OUT, 'security-agent', 'dockermgr-agent')
  fs.mkdirSync(path.dirname(bin), { recursive: true })
  execFileSync('go', ['build', '-o', bin, './cmd/agent'], { cwd: path.join(ROOT, 'agent'), env: { ...process.env, CGO_ENABLED: '0' } })
  const name = `e2e-relay-${Date.now() % 100000}`
  const srv = (await api('POST', '/servers', { name, environment: 'development', region: 'eu-central', tags: ['e2e'] })).json.data
  const enr = (await api('POST', '/servers/enrollment', { server_id: srv.id })).json.data
  const dir = fs.mkdtempSync(path.join(OUT, 'security-agent', 'run-'))
  execFileSync(bin, ['enroll', '--url', API, '--token', enr.token, '--allow-http', '--config', `${dir}/agent.json`, '--state-dir', `${dir}/state`, '--hostname', name])
  const cfg = JSON.parse(fs.readFileSync(`${dir}/agent.json`))
  assert(cfg.cert_file && fs.existsSync(cfg.cert_file), 'enrolment stored an mTLS client certificate')
  Object.assign(cfg, { heartbeat_interval: 2, policy_path: `${dir}/policy.json` })
  fs.writeFileSync(`${dir}/agent.json`, JSON.stringify(cfg), { mode: 0o600 })
  fs.writeFileSync(`${dir}/policy.json`, '{"disabled_types":[],"maintenance_mode":false}')
  agentProc = spawn(bin, ['run', '--config', `${dir}/agent.json`], { stdio: ['ignore', fs.openSync(`${dir}/agent.log`, 'a'), fs.openSync(`${dir}/agent.log`, 'a')] })
  process.on('exit', () => { try { agentProc.kill() } catch { /* gone */ } })
  await waitFor(async () => (await api('GET', `/servers/${srv.id}`)).json.data.status === 'online', 30000, 'agent online')
  // The heartbeat advertises the relay; the agent keeps its outbound control connection there.
  await waitFor(async () => fs.readFileSync(`${dir}/agent.log`, 'utf8').includes('relay control connection established'), 20000, 'agent relay control connection')
  serverId = srv.id
  return serverId
}

/** Creates (once) the e2e-term container through the API and the real agent. */
async function ensureTermContainer() {
  const sid = await ensureAgent()
  const found = (await api('GET', `/containers?search=e2e-term&server_id=${sid}`)).json.data.find((c) => c.name === 'e2e-term' && c.state === 'running' && c.docker_id)
  if (found) return found
  try { execFileSync('docker', ['rm', '-f', 'e2e-term'], { stdio: 'ignore' }) } catch { /* not there */ }
  const r = await api('POST', '/containers', { server_id: sid, name: 'e2e-term', image: 'e2e/shell:1' })
  assert(r.status === 202, `create e2e-term: ${r.status} ${JSON.stringify(r.json)}`)
  const job = await waitFor(async () => { const j = (await api('GET', `/jobs/${r.json.data.job_id}`)).json.data; return ['succeeded', 'failed'].includes(j.status) ? j : null }, 90000, 'create job')
  assert(job.status === 'succeeded', `create job ${job.status}: ${job.error}`)
  return waitFor(async () => (await api('GET', `/containers?search=e2e-term&server_id=${sid}`)).json.data.find((c) => c.name === 'e2e-term' && c.state === 'running' && c.docker_id), 30000, 'e2e-term in inventory')
}

/** Opens the terminal dialog from Logs & Terminal, passing the approval gate with a second owner. */
async function openTerminal(page) {
  const c = await ensureTermContainer()
  await page.goto(WEB + '/admin/logs'); await settle(page)
  await page.getByRole('tab', { name: 'Terminal' }).click()
  await page.getByLabel('Container').first().selectOption(c.id)
  await page.getByRole('button', { name: 'Open terminal in e2e-term' }).click()
  const dialog = page.getByRole('dialog')
  const gated = await Promise.race([
    dialog.getByRole('button', { name: 'Open with approval' }).waitFor({ timeout: 15000 }).then(() => true),
    dialog.getByText(/Attached/).waitFor({ timeout: 15000 }).then(() => false),
  ])
  if (gated) {
    // Second person (owner@example.com, same organisation) approves the pending terminal request.
    const pending = await waitFor(async () => (await api('GET', '/approvals', undefined, 'owner@example.com')).json.data
      .find((a) => a.action === 'terminal.open' && ['pending', 'pending_approval'].includes(a.status)), 15000, 'pending terminal approval')
    const ap = await api('POST', `/approvals/${pending.id}/approve`, {}, 'owner@example.com')
    assert(ap.status === 200, `approve: ${ap.status} ${JSON.stringify(ap.json)}`)
    await dialog.getByRole('button', { name: 'Open with approval' }).click()
  }
  await dialog.getByText(/Attached/).waitFor({ timeout: 40000 })
  return { dialog, container: c }
}

async function type(page, line) {
  await page.locator('[data-testid=terminal] .xterm-helper-textarea').focus()
  await page.keyboard.type(line)
  await page.keyboard.press('Enter')
}

test('terminal: exec into e2e-term via relay, type, see output, expiry closes it, recording replays', async ({ page }) => {
  await login(page, 'admin@example.com')
  const { dialog, container } = await openTerminal(page)
  await waitFor(async () => /\$/.test(await termText(page)), 15000, 'shell prompt')
  await type(page, 'echo hello-from-e2e')
  await waitFor(async () => ((await termText(page)).match(/hello-from-e2e/g) || []).length >= 2, 15000, 'echo output')
  await type(page, 'id')
  await waitFor(async () => /uid=1000\(app\)/.test(await termText(page)), 15000, 'non-root id output')
  // Resize: the dialog re-fits; the relay forwards it to Docker exec and records it.
  await page.setViewportSize({ width: 1000, height: 760 }); await sleep(800)
  await shot(page, 'security-01-terminal')

  // The session is listed as active; shorten its expiry in the DB: the relay closes it at expires_at.
  const sess = await waitFor(async () => (await api('GET', `/terminal-sessions?container_id=${container.id}`)).json.data.find((s) => s.status === 'active'), 10000, 'active session')
  psql(`UPDATE terminal_sessions SET expires_at = now() + interval '2 seconds' WHERE id = '${sess.id}'`)
  await dialog.getByText(/Session ended: expired/).waitFor({ timeout: 20000 })
  const closed = await waitFor(async () => (await api('GET', `/terminal-sessions?container_id=${container.id}`)).json.data.find((s) => s.id === sess.id && s.status !== 'active'), 10000, 'session closed in API')
  assert(closed.close_reason === 'expired', `close_reason ${closed.close_reason}`)
  // The exec'd shell is gone from the container (only the --serve keeper remains).
  const shells = () => execFileSync('docker', ['top', 'e2e-term', '-eo', 'pid,args']).toString().split('\n').filter((l) => /\/bin\/sh/.test(l) && !/--serve/.test(l)).length
  await waitFor(async () => shells() === 0, 15000, 'exec shell exited')
  await shot(page, 'security-02-terminal-expired')
  await dialog.getByRole('button', { name: 'End session' }).click()

  // Recording is listed (Logs & Terminal) and replays the typed command's output.
  await waitFor(async () => (await api('GET', `/terminal-sessions?container_id=${container.id}`)).json.data.find((s) => s.id === sess.id && s.recording), 15000, 'recording stored')
  await page.reload(); await settle(page)
  await page.getByRole('tab', { name: 'Terminal' }).click()
  const row = page.getByRole('row', { name: /e2e-term/ }).filter({ hasText: 'expired' }).first()
  await row.getByRole('button', { name: /Play/ }).click()
  await waitFor(async () => /hello-from-e2e/.test(await termText(page, '[data-testid=recording]')), 15000, 'recording replay')
  const cast = (await api('GET', `/terminal-sessions/${sess.id}/recording`)).json.data.cast
  assert(/"version":2/.test(cast.split('\n')[0]) && /uid=1000\(app\)/.test(cast), 'asciicast content')
  await shot(page, 'security-03-recording')
  const actions = (await api('GET', '/audit-logs?per_page=200')).json.data.map((a) => a.action)
  for (const a of ['terminal.opened', 'terminal.attached', 'terminal.closed']) assert(actions.includes(a), `audit ${a}`)
})

test('terminal: "End session" closes an active session for good', async ({ page }) => {
  await login(page, 'admin@example.com')
  const { dialog, container } = await openTerminal(page)
  await waitFor(async () => /\$/.test(await termText(page)), 15000, 'shell prompt')
  const sess = (await api('GET', `/terminal-sessions?container_id=${container.id}`)).json.data.find((s) => s.status === 'active')
  await dialog.getByRole('button', { name: 'End session' }).click()
  const s = await waitFor(async () => (await api('GET', `/terminal-sessions?container_id=${container.id}`)).json.data.find((x) => x.id === sess.id && x.status === 'ended'), 15000, 'ended')
  assert(/ended by admin@example.com/.test(s.close_reason), `close_reason ${s.close_reason}`)
})

/** Chromium virtual authenticator (internal, resident keys, user verification). */
async function virtualAuthenticator(ctx, page) {
  const cdp = await ctx.newCDPSession(page)
  await cdp.send('WebAuthn.enable')
  const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
    protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } })
  return { cdp, authenticatorId }
}

test('passkeys: register + rename on admin Account, then sign in with the passkey', async ({ page, ctx }) => {
  const { cdp, authenticatorId } = await virtualAuthenticator(ctx, page)
  await login(page, 'admin@example.com', { ui: true })
  await page.goto(WEB + '/admin/account'); await settle(page)
  await page.getByRole('button', { name: 'Add a passkey' }).click()
  await page.getByRole('dialog').getByLabel('Name').fill('E2E virtual key')
  await page.getByRole('dialog').getByRole('button', { name: 'Continue' }).click()
  await page.getByRole('list', { name: 'Passkeys' }).getByText('E2E virtual key').waitFor({ timeout: 15000 })
  const { credentials } = await cdp.send('WebAuthn.getCredentials', { authenticatorId })
  assert(credentials.length === 1 && credentials[0].isResidentCredential, 'resident credential on the virtual authenticator')
  await page.getByRole('button', { name: 'Rename' }).first().click()
  await page.getByRole('dialog').getByLabel('Name').fill('E2E key (renamed)')
  await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click()
  await page.getByRole('list', { name: 'Passkeys' }).getByText('E2E key (renamed)').waitFor()
  await shot(page, 'security-04-passkeys-admin')

  // Sign out and back in with the passkey only.
  await page.evaluate(() => localStorage.removeItem('dmp.token'))
  await page.goto(WEB + '/login'); await settle(page)
  await page.getByRole('button', { name: 'Sign in with a passkey' }).click()
  await page.waitForURL(/\/admin/, { timeout: 15000 })
  const me = await page.evaluate(async () => (await fetch('/api/v1/me', { headers: { Authorization: 'Bearer ' + localStorage.getItem('dmp.token'), Accept: 'application/json' } })).json())
  assert(me.data.user.email === 'admin@example.com', 'signed in as admin via passkey')
  const list = (await api('GET', '/me/passkeys')).json.data
  assert(list.length === 1 && list[0].last_used_at, 'passkey last_used_at set')
  const signIn = (await api('GET', '/audit-logs?per_page=50')).json.data.find((a) => a.action === 'auth.login' && (a.meta?.method ?? a.metadata?.method ?? JSON.stringify(a)).includes('passkey'))
  assert(signIn, 'audit auth.login method passkey')
  await shot(page, 'security-05-passkey-signed-in')
})

test('passkeys: customer Account registers and deletes a passkey', async ({ page, ctx }) => {
  await virtualAuthenticator(ctx, page)
  await login(page, 'owner@example.com', { ui: true })
  await page.goto(WEB + '/app/account'); await settle(page)
  await page.getByRole('button', { name: 'Add a passkey' }).click()
  await page.getByRole('dialog').getByLabel('Name').fill('Owner phone')
  await page.getByRole('dialog').getByRole('button', { name: 'Continue' }).click()
  const list = page.getByRole('list', { name: 'Passkeys' })
  await list.getByText('Owner phone').waitFor({ timeout: 15000 })
  await list.getByRole('button', { name: 'Delete' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Delete' }).click()
  await page.getByText('No passkeys yet.').waitFor({ timeout: 10000 })
  const left = (await api('GET', '/me/passkeys', undefined, 'owner@example.com')).json.data
  assert(left.length === 0, 'passkey deleted')
})

process.on('exit', () => { try { execFileSync('docker', ['rm', '-f', 'e2e-term'], { stdio: 'ignore' }) } catch { /* gone */ } })
run('security')
