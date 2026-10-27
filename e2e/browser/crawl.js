// Discovery crawl: visit every route, print errors and placeholder-dash counts.
const { test, run, login, settle, shot, dashCount, WEB } = require('./lib')
const admin = ['', 'servers', 'containers', 'applications', 'compose', 'images', 'networks', 'volumes', 'domains', 'databases', 'backups', 'monitoring', 'logs', 'automation', 'users', 'settings']
const app = ['', 'host', 'domains', 'backups', 'billing', 'account']
for (const [who, base, list] of [['admin@example.com', 'admin', admin], ['owner@example.com', 'app', app]]) {
  test(`crawl ${base}`, async ({ page, w }) => {
    await login(page, who)
    const errs = []
    for (const p of list) {
      const n0 = w.errors.length
      await page.goto(`${WEB}/${base}/${p}`); await settle(page); await page.waitForTimeout(700)
      const d = await dashCount(page)
      await shot(page, `crawl-${base}-${p || 'index'}`)
      console.log(`  /${base}/${p}  dashes=${d}  ${w.errors.slice(n0).join(' | ')}`)
    }
  })
}
run('crawl')
