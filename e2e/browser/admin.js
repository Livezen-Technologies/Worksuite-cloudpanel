// Admin portal flows (admin@example.com). Needs the stack from ../run.sh (API, queue, web, agent with e2e-web).
const { execFileSync, spawn } = require('child_process')
const fs = require('fs')
const path = require('path')
const { test, run, login, settle, shot, mainText, dashCount, assert, expectText, apiCall, apiLogin, WEB, API, OUT, choose } = require('./lib')

const AGENT_BIN = process.env.AGENT_BIN || path.join(OUT, '..', 'agent', 'dockermgr-agent')
const started = (name) => execFileSync('docker', ['inspect', '-f', '{{.State.StartedAt}}', name]).toString().trim()
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
async function waitFor(fn, ms = 60000, msg = 'condition') {
  const t0 = Date.now()
  for (;;) { const v = await fn(); if (v) return v; if (Date.now() - t0 > ms) throw new Error(`timeout waiting for ${msg}`); await sleep(1000) }
}
let TOKEN
async function api(method, p, body) { TOKEN ??= await apiLogin('admin@example.com'); return apiCall(method, p, TOKEN, body) }
// NB: lib.login caches its own token; this one is for direct API assertions.
async function jobDone(id, ms = 90000) {
  return waitFor(async () => { const j = (await api('GET', `/jobs/${id}`)).json?.data; return j && ['succeeded', 'failed', 'cancelled'].includes(j.status) ? j : null }, ms, `job ${id}`)
}

test('admin: login and Overview shows real KPI numbers', async ({ page }) => {
  await login(page, 'admin@example.com', { ui: true })
  assert(page.url().endsWith('/admin'), `landed on ${page.url()}`)
  await page.getByText('Running containers').first().waitFor()
  const k = (await api('GET', '/dashboard?range=6h')).json.data.kpis
  const text = await mainText(page)
  assert(new RegExp(`Servers\\s+${k.servers_total}\\b`).test(text) && new RegExp(`Online\\s+${k.servers_online}\\b`).test(text), `servers tiles != ${k.servers_online} online of ${k.servers_total}`)
  assert(new RegExp(`Running containers\\s+${k.containers_running}\\b`).test(text), `running containers tile != ${k.containers_running}`)
  assert(new RegExp(`CPU usage\\s+${Math.round(k.cpu_pct)}%`).test(text), 'cpu tile')
  for (const s of ['Backups (24 h)', 'Certificates', 'Image security', 'Recent deployments', 'Top resource consumers', 'Alex Admin']) assert(text.includes(s), `missing "${s}"`)
  assert(await dashCount(page) === 0, `${await dashCount(page)} placeholder dashes on Overview`)
  await shot(page, 'admin-01-overview')
})

test('admin: Servers list and add-server wizard reaches install command, real agent connects', async ({ page }) => {
  await login(page, 'admin@example.com')
  await page.goto(WEB + '/admin/servers'); await settle(page)
  const card = page.locator('main').getByText('e2e-local').first(); await card.waitFor()
  await expectText(page, /e2e-local[\s\S]*online/, 'e2e-local online card')
  await shot(page, 'admin-02-servers')
  await page.getByRole('button', { name: 'Add server' }).click()
  const name = `e2e-wizard-${Date.now() % 100000}`
  await page.getByLabel('Server name', { exact: true }).fill(name)
  await page.getByLabel('IP address').fill('127.0.0.1')
  await choose(page.getByRole('dialog').getByLabel('Environment'), 'development')
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.getByRole('button', { name: 'Continue' }).click()
  const pre = page.locator('pre', { hasText: '--token' }); await pre.waitFor({ timeout: 15000 })
  const cmd = await pre.innerText()
  assert(/install\.sh/.test(cmd) && /--url/.test(cmd), `install command looks wrong: ${cmd}`)
  await shot(page, 'admin-03-add-server-install-command')
  const token = /--token\s+(\S+)/.exec(cmd)[1]
  // Run the printed enrolment with the real agent binary (dev panel is plain http).
  const dir = fs.mkdtempSync(path.join(OUT, 'wizard-agent-'))
  execFileSync(AGENT_BIN, ['enroll', '--url', API, '--token', token, '--allow-http', '--config', `${dir}/agent.json`, '--state-dir', `${dir}/state`, '--hostname', name])
  const cfg = JSON.parse(fs.readFileSync(`${dir}/agent.json`)); cfg.heartbeat_interval = 2; cfg.policy_path = `${dir}/policy.json`
  fs.writeFileSync(`${dir}/agent.json`, JSON.stringify(cfg), { mode: 0o600 })
  const agent = spawn(AGENT_BIN, ['run', '--config', `${dir}/agent.json`], { stdio: 'ignore' })
  try {
    await page.getByText('Agent heartbeat received').waitFor({ timeout: 30000 })
    await page.getByRole('button', { name: 'Continue' }).click()
    await page.getByText(/Verification|checks/i).first().waitFor()
    await settle(page); await page.waitForTimeout(1500)
    await shot(page, 'admin-04-add-server-verification')
  } finally {
    agent.kill()
    // The wizard agent shares this Docker host; drop its server so inventory is not reported twice.
    const s = (await api('GET', `/servers?search=${name}`)).json.data[0]
    if (s) await api('DELETE', `/servers/${s.id}`)
  }
})

test('admin: Containers list shows the agent container; Restart produces a job that restarts it', async ({ page }) => {
  await login(page, 'admin@example.com')
  await page.goto(WEB + '/admin/containers'); await settle(page)
  const sid = (await api('GET', '/servers?search=e2e-local')).json.data.find((s) => s.status === 'online').id
  await choose(page.getByLabel('Server', { exact: true }), sid)
  await page.getByPlaceholder(/Filter by name/).fill('e2e-web')
  const row = page.locator('tr', { hasText: 'e2e-web' }).first(); await row.waitFor()
  const rowText = await row.innerText()
  assert(/e2e-local/.test(rowText) && /running/.test(rowText) && /e2e\/hello:1/.test(rowText), `row: ${rowText}`)
  assert(!/[0-9a-f]{8}-[0-9a-f]{4}-/.test(rowText), 'server column shows a raw UUID')
  await shot(page, 'admin-05-containers')
  const before = started('e2e-web')
  await row.getByText('e2e-web').click()
  const drawer = page.getByRole('dialog'); await drawer.getByRole('button', { name: 'Restart' }).click()
  await page.getByText(/Activity \(\d+\)/).waitFor()
  await waitFor(async () => started('e2e-web') !== before, 60000, 'container restart on Docker')
  await page.waitForTimeout(500)
  await shot(page, 'admin-06-container-restart-job')
})

test('admin: Applications create (image), deploy twice via agent, roll back', async ({ page }) => {
  await login(page, 'admin@example.com')
  await page.goto(WEB + '/admin/applications'); await settle(page)
  const name = `e2e-app-${Date.now() % 100000}`
  await page.getByRole('button', { name: 'New application' }).click()
  const dlg = page.getByRole('dialog')
  await dlg.getByRole('button', { name: /Docker image/ }).click()
  await dlg.getByLabel('Name').fill(name)
  await dlg.getByLabel('Image').fill('e2e/hello:1')
  await choose(dlg.getByLabel('Environment'), 'staging')
  const sid = (await api('GET', '/servers?search=e2e-local')).json.data.find((s) => s.status === 'online').id
  await choose(dlg.getByLabel('Target server'), sid)
  await dlg.getByRole('button', { name: /Recreate/ }).click()
  await dlg.getByRole('button', { name: 'Create application' }).click()
  const row = page.locator('tr', { hasText: name }).first(); await row.waitFor()
  const app = (await api('GET', `/applications?search=${name}`)).json.data[0]
  assert(app && app.image === 'e2e/hello:1' && app.source_type === 'image', `app not created as image: ${JSON.stringify(app)}`)
  for (let i = 0; i < 2; i++) {
    await row.getByRole('button', { name: 'Deploy' }).click()
    const dep = await waitFor(async () => { const d = (await api('GET', `/deployments?application_id=${app.id}`)).json.data; return d.length > i ? d[0] : null }, 15000, 'deployment')
    const job = await jobDone(dep.job_id, 120000)
    assert(job.status === 'succeeded', `deploy ${i + 1} ${job.status}: ${JSON.stringify(job.stages.filter((s) => s.status === 'failed'))} ${job.error ?? ''}`)
  }
  await settle(page)
  await row.click()
  const rel = page.locator('main').getByText(`Releases · ${name}`); await rel.waitFor()
  await shot(page, 'admin-07-applications-releases')
  await page.locator('li', { has: page.getByRole('button', { name: 'Roll back' }) }).first().getByRole('button', { name: 'Roll back' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Roll back' }).click()
  const rb = await waitFor(async () => (await api('GET', `/deployments?application_id=${app.id}`)).json.data.find((d) => d.rollback_of_release_id || d.source === 'rollback'), 15000, 'rollback deployment')
  const job = await jobDone(rb.job_id, 120000)
  assert(job.status === 'succeeded', `rollback ${job.status} ${job.error ?? ''}`)
  const releases = (await api('GET', `/applications/${app.id}/releases`)).json.data
  assert(releases.length >= 3, `expected 3 releases, got ${releases.length}`)
  await settle(page); await shot(page, 'admin-08-applications-rollback')
  // Leave the host clean.
  await api('DELETE', `/applications/${app.id}`)
})

test('admin: Compose editor loads YAML, Validate (valid + invalid) and Diff render', async ({ page }) => {
  await login(page, 'admin@example.com')
  await page.goto(WEB + '/admin/compose'); await settle(page)
  const editor = page.locator('main textarea').first(); await editor.waitFor()
  await waitFor(async () => (await editor.inputValue()).includes('services:'), 10000, 'yaml loaded in editor')
  await page.getByRole('button', { name: 'Validate' }).click()
  await page.getByRole('status').getByText('valid', { exact: true }).waitFor()
  await shot(page, 'admin-09-compose-valid')
  await editor.fill('services:\n  web:\n    image: e2e/hello:1\n    privileged: true\n')
  await page.getByRole('button', { name: 'Validate' }).click()
  await page.getByRole('status').getByText('invalid', { exact: true }).waitFor()
  await expectText(page, /privileged mode is not allowed/)
  await page.getByRole('button', { name: 'Diff' }).click()
  await page.locator('main pre').filter({ hasText: 'privileged' }).waitFor()
  assert(!(await mainText(page)).includes('[object Object]'), 'diff shows [object Object]')
  await shot(page, 'admin-10-compose-diff')
})

const pages = [
  ['domains', /shop\.example\.com[\s\S]*shop[\s\S]*8080/, ['Valid certificates']],
  ['backups', /Main database nightly[\s\S]*0 3 \* \* \*[\s\S]*30 days/, []],
  ['monitoring', /CPU > 90% on edge-us-01/, ['CPU above 90%']],
  ['users', /Olivia Owner[\s\S]*owner/, ['Alex Admin']],
  ['images', /e2e\/hello[\s\S]*1[\s\S]*e2e-local/, []],
  ['networks', /bridge[\s\S]*172\.\d+\.\d+\.\d+\/\d+/, []],
  ['volumes', /db-main-data/, []],
  ['databases', /Databases/, []],
  ['logs', /Logs/, []],
  ['automation', /Automation|Scheduled/, []],
]
for (const [p, re, more] of pages) {
  test(`admin: ${p} renders real data`, async ({ page }) => {
    await login(page, 'admin@example.com')
    await page.goto(`${WEB}/admin/${p}`); await settle(page); await page.waitForTimeout(800)
    await expectText(page, re, `${p}: expected ${re}`)
    for (const s of more) await expectText(page, new RegExp(s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
    const t = await mainText(page)
    assert(!/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/.test(t), `${p}: raw UUID shown instead of a name`)
    assert(!t.includes('[object Object]') && !t.includes('NaN') && !t.includes('undefined'), `${p}: broken value rendering`)
    await shot(page, `admin-page-${p}`)
  })
}

test('admin: Settings shows org values and saves general + security policies', async ({ page }) => {
  await login(page, 'admin@example.com')
  await page.goto(WEB + '/admin/settings'); await settle(page)
  const name = page.getByLabel('Organisation name')
  await waitFor(async () => (await name.inputValue()) === 'Demo Organisation', 10000, 'org name in form')
  assert(await page.getByLabel('Agent heartbeat (seconds)').inputValue() === '15', 'heartbeat value')
  assert(await page.getByLabel('Dashboard refresh (seconds)').inputValue() === '10', 'refresh value')
  await page.getByLabel('Agent heartbeat (seconds)').fill('20')
  await page.getByRole('button', { name: 'Save changes' }).click()
  await waitFor(async () => (await api('GET', '/settings')).json.data.general.heartbeat_interval === 20, 10000, 'saved heartbeat')
  await shot(page, 'admin-page-settings')
  await page.getByRole('tab', { name: 'Security' }).click()
  const t = page.getByRole('switch', { name: 'Approval for destructive production actions' })
  await t.waitFor()
  assert(await t.getAttribute('aria-checked') === 'true', 'approval policy should show as on (seeded true)')
  await shot(page, 'admin-page-settings-security')
  await api('PATCH', '/settings', { general: { heartbeat_interval: 15 } })
})

run('admin')
