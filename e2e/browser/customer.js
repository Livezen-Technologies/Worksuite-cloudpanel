// Customer panel flows (owner@example.com). Needs the stack from ../run.sh with the agent online.
const { test, run, login, settle, shot, mainText, dashCount, assert, expectText, apiCall, apiLogin, WEB, choose } = require('./lib')

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
async function waitFor(fn, ms = 60000, msg = 'condition') {
  const t0 = Date.now()
  for (;;) { const v = await fn(); if (v) return v; if (Date.now() - t0 > ms) throw new Error(`timeout waiting for ${msg}`); await sleep(1000) }
}
let TOKEN
async function api(method, p, body) { TOKEN ??= await apiLogin('owner@example.com'); return apiCall(method, p, TOKEN, body) }
const APP = `e2e-cust-${Date.now() % 100000}`

test('customer: login lands on My apps with real app cards', async ({ page }) => {
  await login(page, 'owner@example.com', { ui: true })
  assert(/\/app$/.test(page.url()), `landed on ${page.url()}`)
  await page.getByText('blog', { exact: true }).waitFor()
  await expectText(page, /blog\.livezen\.app/)
  await expectText(page, /CPU \d+%/)
  assert(await dashCount(page) === 0, 'placeholder dashes on My apps')
  await shot(page, 'customer-01-my-apps')
})

test('customer: Host an app in 4 steps until live (real agent deploy)', async ({ page }) => {
  await login(page, 'owner@example.com')
  await page.goto(WEB + '/app/host'); await settle(page)
  await page.getByRole('textbox', { name: /^Image/ }).fill('e2e/hello:1')
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.getByLabel('App name').waitFor()
  await page.getByLabel('App name').fill(APP)
  await page.getByLabel('Port your app listens on').fill('8080')
  await shot(page, 'customer-02-host-configure')
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.getByRole('radio', { name: /Small/ }).click()
  await expectText(page, /€5\.00 \/ mo/, 'size price from plans (price_cents)')
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.getByRole('button', { name: 'Deploy my app' }).click()
  await page.getByText('Your app is live at').waitFor({ timeout: 120000 })
  await expectText(page, new RegExp(`${APP}\\.livezen\\.app`))
  assert(!(await mainText(page)).includes('https://https'), 'double scheme in address')
  const [fg, bg] = await page.getByRole('link', { name: /Connect your own domain/ }).evaluate((e) => [getComputedStyle(e).color, getComputedStyle(e).backgroundColor])
  assert(fg !== bg, `primary link-button text is invisible (${fg} on ${bg})`)
  await shot(page, 'customer-03-host-live')
})

test('customer: quota 402 shows the upgrade prompt', async ({ page, w }) => {
  w.allow(402, /\/app\/apps$/)
  await login(page, 'owner@example.com')
  await page.goto(WEB + '/app/host'); await settle(page)
  await page.getByRole('textbox', { name: /^Image/ }).fill('e2e/hello:1')
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.getByLabel('App name').fill(`${APP}-big`)
  await page.getByLabel('Port your app listens on').fill('8080')
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.getByRole('radio', { name: /Pro/ }).click()
  await page.getByRole('button', { name: 'Continue' }).click()
  const resp = page.waitForResponse((r) => r.url().endsWith('/api/v1/app/apps') && r.request().method() === 'POST')
  await page.getByRole('button', { name: 'Deploy my app' }).click()
  const r402 = await resp
  assert(r402.status() === 402, 'expected 402')
  const err = (await r402.json()).error
  assert(err.code === 'quota_exceeded' && err.upgrade_to, `402 body: ${JSON.stringify(err)}`)
  await page.getByText('This app does not fit in your current plan').waitFor()
  await page.locator('main').getByRole('link', { name: 'Upgrade plan' }).waitFor()
  await shot(page, 'customer-04-quota-402')
})

test('customer: app detail tabs render live data', async ({ page }) => {
  await login(page, 'owner@example.com')
  const app = (await api('GET', '/app/apps')).json.data.find((a) => a.name === APP)
  assert(app, `app ${APP} not found`)
  await page.goto(`${WEB}/app/apps/${app.id}`); await settle(page)
  await expectText(page, new RegExp(`${APP}\\.livezen\\.app`), 'free address listed')
  // Stats arrive with the agent's next heartbeat (~15 s); the page polls every 15 s.
  await waitFor(async () => /CPU\s+\d+%/.test(await mainText(page)), 45000, 'cpu stat')
  await expectText(page, /Memory\s+\d+ MB of 512 MB/, 'memory stat')
  await shot(page, 'customer-05-app-overview')
  for (const [tab, re] of [['Logs', /What your app is printing/], ['Environment', /Environment variables/], ['Releases', /live/], ['Settings', /Delete this app/]]) {
    await page.getByRole('tab', { name: tab }).click(); await settle(page)
    await expectText(page, re, `${tab} tab`)
    await shot(page, `customer-06-app-${tab.toLowerCase()}`)
  }
  // Environment: add a variable, Save & restart => one redeploy job.
  await page.getByRole('tab', { name: 'Environment' }).click()
  await page.getByRole('button', { name: '+ Add variable' }).click()
  await page.getByLabel('Variable 1 name').fill('GREETING')
  await page.getByLabel('Variable 1 value').fill('hi')
  const patch = page.waitForResponse((r) => r.url().endsWith(`/app/apps/${app.id}`) && r.request().method() === 'PATCH')
  await page.getByRole('button', { name: 'Save & restart' }).click()
  const body = await (await patch).json()
  assert(body.data?.job_id, 'PATCH returned no job_id')
  await page.getByText(/Activity \(\d+\)/).waitFor()
})

test('customer: Domains add + Check DNS now shows records', async ({ page }) => {
  await login(page, 'owner@example.com')
  await page.goto(WEB + '/app/domains'); await settle(page)
  const domain = `${APP}.example.com`
  await page.getByLabel('Domain').fill(domain)
  await choose(page.locator('main').getByLabel('App'), { label: APP })
  await page.getByRole('button', { name: 'Add domain' }).click()
  await page.getByText(`Add these records at your DNS provider for`).waitFor()
  await page.getByRole('button', { name: 'Check DNS now' }).click()
  await page.locator('main table').first().locator('td', { hasText: /^(A|CNAME|TXT)$/ }).first().waitFor()
  await page.getByText(/last checked/).waitFor()
  const row = page.locator('tr', { hasText: domain }).last(); await row.waitFor()
  await shot(page, 'customer-07-domains')
})

test('customer: Backups list and Restore confirm creates a job', async ({ page }) => {
  await login(page, 'owner@example.com')
  await page.goto(WEB + '/app/backups'); await settle(page)
  const item = page.locator('main li', { hasText: 'blog' }).first(); await item.waitFor()
  await expectText(page, /180 MB/)
  await item.getByRole('button', { name: 'Restore' }).click()
  await page.getByRole('dialog').getByText(/A fresh backup is taken first/).waitFor()
  await shot(page, 'customer-08-backup-restore-confirm')
  const resp = page.waitForResponse((r) => /\/app\/backups\/[^/]+\/restore$/.test(r.url()))
  await page.getByRole('dialog').getByRole('button', { name: 'Restore now' }).click()
  const r = await resp
  assert(r.status() === 202, `restore status ${r.status()}`)
  await page.getByText(/Activity \(\d+\)/).waitFor()
})

test('customer: Billing shows plan, usage, payment method and invoices', async ({ page }) => {
  await login(page, 'owner@example.com')
  await page.goto(WEB + '/app/billing'); await settle(page)
  await expectText(page, /Small · €5\.00 \/ month/)
  await expectText(page, /€\d+\.\d\d/)
  await expectText(page, /Card ending 4242/)
  await expectText(page, /INV-\d{6}-0001[\s\S]*€17\.00/)
  assert(await dashCount(page) === 0, 'placeholder dashes on Billing')
  await page.getByRole('button', { name: 'Compare plans' }).click()
  await page.getByRole('dialog').getByText('current plan').waitFor()
  await shot(page, 'customer-09-billing')
})

test('customer: Account shows profile and team', async ({ page }) => {
  await login(page, 'owner@example.com')
  await page.goto(WEB + '/app/account'); await settle(page)
  assert(await page.getByLabel('Full name').inputValue() === 'Olivia Owner', 'name')
  assert(await page.getByLabel('Organisation').inputValue() === 'Demo Organisation', 'org')
  await expectText(page, /Alex Admin/)
  await shot(page, 'customer-10-account')
})

test('customer: cleanup test app', async () => {
  const app = (await api('GET', '/app/apps')).json.data.find((a) => a.name === APP)
  if (app) assert([200, 202, 204].includes((await api('DELETE', `/app/apps/${app.id}`)).status), 'delete app')
})

run('customer')
