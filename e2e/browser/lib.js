// Tiny test runner on top of the preinstalled `playwright` library (no @playwright/test).
const { chromium } = require('playwright')
const fs = require('fs')
const path = require('path')

const WEB = process.env.WEB_URL || 'http://localhost:5173'
const API = process.env.API_URL || 'http://127.0.0.1:8000'
const OUT = path.join(process.env.E2E_SCRATCH || '/tmp/dmp-e2e', 'browser')
fs.mkdirSync(OUT, { recursive: true })

const results = []
const tests = []
function test(name, fn) { tests.push({ name, fn }) }

/** Attaches error collectors to a page. `allow(status, url, method)` marks intended 4xx responses. */
function watch(page) {
  const w = { errors: [], allowed: [] }
  page.on('pageerror', (e) => w.errors.push(`pageerror: ${e.message}`))
  page.on('console', (m) => {
    if (m.type() !== 'error') return
    // Chrome echoes every 4xx as "Failed to load resource"; intended statuses are allowed, others are caught by the response hook too.
    const st = /Failed to load resource: the server responded with a status of (\d+)/.exec(m.text())
    if (st && w.allowed.some((a) => a.status === Number(st[1]))) return
    w.errors.push(`console: ${m.text()}`)
  })
  page.on('response', (r) => {
    const u = r.url()
    if (!u.includes('/api/v1/') || r.status() < 400) return
    const key = `${r.request().method()} ${r.status()} ${u.replace(/^https?:\/\/[^/]+/, '')}`
    if (w.allowed.some((a) => a.status === r.status() && a.re.test(u))) return
    w.errors.push(`http: ${key}`)
  })
  page.on('requestfailed', (r) => { if (r.url().includes('/api/v1/') && r.failure()?.errorText !== 'net::ERR_ABORTED') w.errors.push(`requestfailed: ${r.url()} ${r.failure()?.errorText}`) })
  w.allow = (status, re) => w.allowed.push({ status, re })
  return w
}

async function apiCall(method, p, token, body) {
  const res = await fetch(API + '/api/v1' + p, {
    method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  const text = await res.text()
  let json = null; try { json = JSON.parse(text) } catch { /* */ }
  return { status: res.status, json }
}
async function apiLogin(email, password = 'password') {
  const r = await apiCall('POST', '/auth/login', null, { email, password })
  if (r.status !== 200) throw new Error(`login ${email}: ${r.status}`)
  return r.json.data?.token ?? r.json.token
}

const tokens = {}
/** Signs in. Default: reuse one API token per user (the auth limiter allows 10 logins/min/email) by
 *  seeding the web app's token storage; `{ ui: true }` drives the real login form instead. */
async function login(page, email, opts = {}) {
  if (!opts.ui) {
    tokens[email] ??= await apiLogin(email)
    await page.addInitScript(([t]) => { try { localStorage.setItem('dmp.token', t) } catch { /* */ } }, [tokens[email]])
    await page.goto(WEB + '/')
    await page.waitForURL(/\/(admin|app)/, { timeout: 15000 })
    await settle(page)
    return
  }
  await page.goto(WEB + '/login')
  await page.getByLabel(/email/i).fill(email)
  await page.getByLabel(/password/i).fill('password')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await page.waitForURL(/\/(admin|app)/, { timeout: 15000 })
  await settle(page)
}
async function settle(page) {
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {})
  await page.waitForTimeout(300)
}
async function shot(page, name) {
  const file = path.join(OUT, name.replace(/[^a-z0-9-]+/gi, '_') + '.png')
  await page.screenshot({ path: file, fullPage: true })
  return file
}
/** Text of the main region, and count of placeholder dashes. */
async function mainText(page) { return (await page.locator('main').first().innerText().catch(() => page.innerText('body'))) }
/** Number of elements whose whole text is the placeholder dash (an em dash inside a sentence is fine). */
async function dashCount(page) {
  return page.evaluate(() => [...document.querySelectorAll('main *')].filter((e) => e.children.length === 0 && e.textContent.trim() === '—').length)
}

/** Picks an option in a native <select> or in the app's listbox (Select.tsx), by value or by { label }. */
async function choose(locator, value) {
  if (await locator.evaluate((e) => e.tagName === 'SELECT')) return locator.selectOption(value)
  await locator.click()
  const page = locator.page()
  const opt = typeof value === 'object' && value.label
    ? page.getByRole('option', { name: value.label, exact: true })
    : page.locator(`[role=option][data-value="${String(value).replace(/"/g, '\\"')}"]`)
  await opt.first().click()
}

function assert(cond, msg) { if (!cond) throw new Error(msg) }
async function expectText(page, re, msg) {
  const t = await mainText(page)
  assert(re.test(t), msg || `expected ${re} on ${page.url()}`)
}

async function run(title) {
  const browser = await chromium.launch({ args: ['--no-sandbox'] })
  let failed = 0
  const only = process.env.ONLY ? new RegExp(process.env.ONLY) : null
  for (const t of tests.filter((x) => !only || only.test(x.name))) {
    const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 } })
    const page = await ctx.newPage()
    const w = watch(page)
    const t0 = Date.now()
    let err = null
    try { await t.fn({ page, w, ctx }) } catch (e) { err = e }
    await page.waitForTimeout(200)
    if (!err && w.errors.length) err = new Error('unexpected errors:\n    ' + [...new Set(w.errors)].join('\n    '))
    if (err) { failed++; await shot(page, 'FAIL-' + t.name).catch(() => {}) }
    const line = `${err ? 'FAIL' : 'PASS'}  ${t.name} (${((Date.now() - t0) / 1000).toFixed(1)}s)${err ? '\n    ' + String(err.message).split('\n').join('\n    ') : ''}`
    console.log(line); results.push({ name: t.name, ok: !err, error: err?.message })
    await ctx.close()
  }
  await browser.close()
  fs.writeFileSync(path.join(OUT, `${title}-results.json`), JSON.stringify(results, null, 2))
  console.log(`\n${title}: ${results.length - failed} passed, ${failed} failed`)
  process.exit(failed ? 1 : 0)
}

module.exports = { test, run, login, choose, settle, shot, mainText, dashCount, assert, expectText, apiCall, apiLogin, WEB, API, OUT }
