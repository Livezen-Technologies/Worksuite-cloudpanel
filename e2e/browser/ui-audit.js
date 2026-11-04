// UI audit: every route, light + dark, desktop + phone. Records errors, layout overflow and basic
// accessibility problems per page, and a full-page screenshot of each. Writes ui-audit.json to $E2E_SCRATCH.
//   node e2e/browser/ui-audit.js            # all routes
//   ONLY=admin/servers node e2e/browser/ui-audit.js
const fs = require('fs')
const path = require('path')
const { chromium } = require('playwright')
const { apiLogin, apiCall, WEB, OUT } = require('./lib')

const ADMIN = ['', 'servers', 'containers', 'firewall', 'antivirus', 'dns', 'wordpress', 'migrations', 'mail', 'public-pages', 'cron',
  'applications', 'catalog', 'projects', 'private-network', 'packages', 'customers', 'compose', 'images', 'networks', 'volumes', 'files',
  'domains', 'databases', 'backups', 'monitoring', 'logs', 'automation', 'users', 'settings', 'account']
const APP = ['', 'host', 'new-site', 'files', 'domains', 'backups', 'email', 'security', 'firewall', 'cron', 'move', 'billing', 'account']
const PUBLIC = ['login', 'register', 'forgot-password']
const VIEWPORTS = { desktop: { width: 1440, height: 900 }, phone: { width: 390, height: 844 } }

// Runs in the page: layout and accessibility checks on what is rendered.
function inspect() {
  const out = { overflowX: 0, offscreen: [], clipped: [], unnamed: [], unlabeled: [], noAlt: [], smallTargets: [] }
  const vw = document.documentElement.clientWidth
  out.overflowX = Math.max(0, document.documentElement.scrollWidth - vw)
  const desc = (e) => (e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.getAttribute('aria-label') ? `[${e.getAttribute('aria-label')}]` : '') + ' "' + (e.textContent || '').trim().slice(0, 40) + '"')
  const visible = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' }
  const inScroller = (e) => { for (let p = e.parentElement; p; p = p.parentElement) { const o = getComputedStyle(p).overflowX; if (o === 'auto' || o === 'scroll' || o === 'hidden') return true } return false }
  for (const e of document.querySelectorAll('body *')) {
    if (!visible(e)) continue
    const r = e.getBoundingClientRect()
    if (r.right > vw + 1 && !inScroller(e) && e.children.length === 0) out.offscreen.push(desc(e))
    const s = getComputedStyle(e)
    if ((e.tagName === 'BUTTON' || e.classList.contains('btn')) && e.scrollWidth > e.clientWidth + 1 && s.overflow !== 'visible') out.clipped.push(desc(e))
  }
  for (const e of document.querySelectorAll('button, a[href], [role=button], [role=tab], [role=menuitem]')) {
    if (!visible(e)) continue
    const name = (e.getAttribute('aria-label') || e.getAttribute('title') || e.textContent || '').trim() || [...e.querySelectorAll('img[alt]')].map((i) => i.alt).join('')
    const labelled = e.getAttribute('aria-labelledby')
    if (!name && !labelled) out.unnamed.push(e.outerHTML.slice(0, 120))
    const r = e.getBoundingClientRect()
    // WCAG 2.5.8: inline links in text and visually hidden (sr-only) elements are exempt.
    const inline = getComputedStyle(e).display === 'inline' && e.tagName === 'A'
    if (vw < 500 && r.width > 1 && (r.height < 24 || r.width < 24) && !inline && !e.closest('p')) out.smallTargets.push(desc(e) + ` ${Math.round(r.width)}x${Math.round(r.height)}`)
  }
  for (const e of document.querySelectorAll('input:not([type=hidden]), select, textarea')) {
    if (!visible(e)) continue
    const ok = e.getAttribute('aria-label') || e.getAttribute('aria-labelledby') || e.closest('label') || (e.id && document.querySelector(`label[for="${CSS.escape(e.id)}"]`)) || e.getAttribute('title')
    if (!ok) out.unlabeled.push(e.outerHTML.slice(0, 120))
  }
  for (const e of document.querySelectorAll('img')) if (visible(e) && !e.hasAttribute('alt')) out.noAlt.push(e.src.slice(-60))
  for (const k of Object.keys(out)) if (Array.isArray(out[k])) out[k] = [...new Set(out[k])].slice(0, 12)
  return out
}

async function ids(token) {
  const first = async (p) => ((await apiCall('GET', p, token)).json?.data ?? [])[0]?.id
  return { server: await first('/servers'), application: await first('/applications') }
}

;(async () => {
  const only = process.env.ONLY ? new RegExp(process.env.ONLY) : null
  const dir = path.join(OUT, 'ui-audit')
  fs.mkdirSync(dir, { recursive: true })
  const adminToken = await apiLogin('admin@example.com')
  const ownerToken = await apiLogin('owner@example.com')
  const a = await ids(adminToken)
  const myApps = (await apiCall('GET', '/app/apps', ownerToken)).json?.data ?? []
  const routes = [
    ...PUBLIC.map((p) => ({ who: null, url: '/' + p })),
    ...ADMIN.map((p) => ({ who: adminToken, url: '/admin/' + p })),
    ...(a.server ? [{ who: adminToken, url: '/admin/servers/' + a.server }] : []),
    ...(a.application ? [{ who: adminToken, url: '/admin/applications/' + a.application }] : []),
    ...APP.map((p) => ({ who: ownerToken, url: '/app/' + p })),
    ...(myApps[0] ? [{ who: ownerToken, url: '/app/apps/' + myApps[0].id }] : []),
  ].filter((r) => !only || only.test(r.url))

  const browser = await chromium.launch({ args: ['--no-sandbox'] })
  const report = []
  for (const [vpName, viewport] of Object.entries(VIEWPORTS)) {
    for (const theme of ['light', 'dark']) {
      for (const r of routes) {
        const ctx = await browser.newContext({ viewport, colorScheme: theme })
        await ctx.addInitScript(([t, th]) => { try { if (t) localStorage.setItem('dmp.token', t); localStorage.setItem('dmp.theme', th) } catch { /* */ } }, [r.who, theme])
        const page = await ctx.newPage()
        const errors = []
        page.on('pageerror', (e) => errors.push('pageerror: ' + e.message))
        page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text().slice(0, 200)) })
        page.on('response', (res) => { if (res.url().includes('/api/') && res.status() >= 400 && res.status() !== 401) errors.push(`http ${res.status()} ${new URL(res.url()).pathname}`) })
        await page.goto(WEB + r.url)
        await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {})
        await page.waitForTimeout(600)
        const found = await page.evaluate(inspect)
        const name = `${vpName}-${theme}${r.url.replace(/[^a-z0-9]+/gi, '_')}`
        await page.screenshot({ path: path.join(dir, name + '.png'), fullPage: true })
        const entry = { url: r.url, viewport: vpName, theme, errors: [...new Set(errors)], ...found }
        const n = entry.errors.length + (found.overflowX ? 1 : 0) + found.offscreen.length + found.clipped.length + found.unnamed.length + found.unlabeled.length + found.noAlt.length + found.smallTargets.length
        console.log(`${n ? 'ISSUES' : 'ok    '} ${vpName.padEnd(7)} ${theme.padEnd(5)} ${r.url}${n ? `  (${n})` : ''}`)
        report.push(entry)
        await ctx.close()
      }
    }
  }
  await browser.close()
  fs.writeFileSync(path.join(OUT, 'ui-audit.json'), JSON.stringify(report, null, 2))
  const sum = (k) => report.reduce((s, e) => s + (Array.isArray(e[k]) ? e[k].length : e[k] ? 1 : 0), 0)
  console.log('\nTotals:', Object.fromEntries(['errors', 'overflowX', 'offscreen', 'clipped', 'unnamed', 'unlabeled', 'noAlt', 'smallTargets'].map((k) => [k, sum(k)])))
})().catch((e) => { console.error(e); process.exit(1) })
