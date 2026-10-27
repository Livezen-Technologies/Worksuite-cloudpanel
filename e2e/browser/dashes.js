// Print the table cells / lines that contain the placeholder dash on given routes.
const { chromium } = require('playwright')
const { WEB } = require('./lib')
;(async () => {
  const [who, ...routes] = process.argv.slice(2)
  const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1360, height: 900 } })
  await p.goto(WEB + '/login'); await p.getByLabel(/email/i).fill(who); await p.getByLabel(/password/i).fill('password')
  await p.getByRole('button', { name: /sign in|log in/i }).click(); await p.waitForURL(/\/(admin|app)/)
  for (const r of routes) {
    await p.goto(WEB + r); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800)
    const lines = await p.evaluate(() => [...document.querySelectorAll('main td, main div, main span')].filter((e) => e.children.length === 0 && e.textContent.includes('—')).map((e) => {
      const tr = e.closest('tr'); const th = tr ? tr.closest('table')?.querySelectorAll('th')[[...tr.children].indexOf(e.closest('td'))]?.textContent : ''
      return `${th || ''}: ${(tr || e.parentElement).textContent.slice(0, 120)}` }))
    console.log('##', r); console.log([...new Set(lines)].join('\n'))
  }
  await b.close()
})()
