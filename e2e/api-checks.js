// API-level checks: organisation isolation and the tamper-evident audit log.
// Usage: node api-checks.js   (API_URL, PGHOST/PGUSER/PGPASSWORD/PGDATABASE for the tamper step)
const { execFileSync } = require('child_process')
const API = (process.env.API_URL || 'http://127.0.0.1:8000') + '/api/v1'
let pass = 0, fail = 0
const ok = (name, cond, extra = '') => { console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${cond ? '' : ' ' + extra}`); cond ? pass++ : fail++ }
async function call(method, path, token, body) {
  const r = await fetch(API + path, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined })
  let json = null; try { json = await r.json() } catch { /* 204 */ }
  return { status: r.status, json }
}
const login = async (email, password = 'password') => (await call('POST', '/auth/login', null, { email, password })).json.data.token
const psql = (sql) => execFileSync('psql', ['-h', process.env.DB_HOST || '127.0.0.1', '-U', process.env.DB_USERNAME || 'dmp', '-d', process.env.DB_DATABASE || 'dmp', '-tAc', sql], { env: { ...process.env, PGPASSWORD: process.env.DB_PASSWORD || 'dmp' } }).toString().trim()

;(async () => {
  // --- Organisation isolation -------------------------------------------------------------
  const admin = await login('admin@example.com')
  const owner = await login('owner@example.com')
  const suffix = Date.now() % 1e6
  const reg = await call('POST', '/auth/register', null, { name: 'Bea B', email: `bea-${suffix}@example.com`, password: 'password-b-123', organization: `Org B ${suffix}` })
  ok('register org B', reg.status === 201 || reg.status === 200, JSON.stringify(reg.json))
  const b = reg.json.data.token
  const first = async (path, token = admin) => (await call('GET', path, token)).json?.data?.[0]?.id
  const ids = {
    server: await first('/servers'), container: await first('/containers'), application: await first('/applications'),
    domain: await first('/domains'), image: await first('/images'), volume: await first('/volumes'), backupPolicy: await first('/backup-policies'),
    app: await first('/app/apps', owner), appDomain: await first('/app/domains', owner), appBackup: await first('/app/backups', owner),
  }
  ok('org A has resources to probe', Object.values(ids).every(Boolean), JSON.stringify(ids))
  const probes = [
    ['GET', `/servers/${ids.server}`], ['PATCH', `/servers/${ids.server}`, { name: 'pwned' }], ['DELETE', `/servers/${ids.server}`],
    ['GET', `/containers/${ids.container}`], ['POST', `/containers/${ids.container}/actions`, { action: 'stop' }], ['GET', `/containers/${ids.container}/logs`],
    ['GET', `/applications/${ids.application}`], ['POST', '/deployments', { application_id: ids.application }],
    ['GET', `/domains/${ids.domain}`], ['GET', `/volumes/${ids.volume}`], ['GET', `/backup-policies/${ids.backupPolicy}`],
    ['POST', '/servers/enrollment', { server_id: ids.server }],
    ['GET', `/app/apps/${ids.app}`], ['PATCH', `/app/apps/${ids.app}`, { auto_restart: false }], ['DELETE', `/app/apps/${ids.app}`], ['POST', `/app/apps/${ids.app}/restart`],
    ['POST', `/app/domains/${ids.appDomain}/verify`], ['POST', `/app/backups/${ids.appBackup}/restore`], ['GET', `/app/backups/${ids.appBackup}/download`],
    ['POST', '/app/domains', { domain: `x${suffix}.example.net`, app_id: ids.app }],
  ]
  for (const [m, p, body] of probes) {
    const r = await call(m, p, b, body)
    ok(`org B ${m} ${p.replace(/[0-9a-f-]{36}/g, '<A-id>')} -> ${r.status}`, [403, 404].includes(r.status), JSON.stringify(r.json).slice(0, 200))
  }
  for (const p of ['/servers', '/containers', '/applications', '/domains', '/app/apps', '/app/backups', '/audit-logs']) {
    const r = await call('GET', p, b)
    const leaked = JSON.stringify(r.json?.data ?? []).match(new RegExp(Object.values(ids).join('|'), 'g'))
    ok(`org B list ${p} has no org A ids`, r.status === 403 || (r.status === 200 && !leaked), `${r.status} ${leaked}`)
  }
  const still = await call('GET', `/servers/${ids.server}`, admin)
  ok('org A server untouched', still.status === 200 && still.json.data.name !== 'pwned')

  // --- Audit log --------------------------------------------------------------------------
  let v = await call('GET', '/audit-logs/verify', admin)
  ok('audit chain valid', v.status === 200 && v.json.data.valid === true && v.json.data.checked > 0, JSON.stringify(v.json))
  const logs = await call('GET', '/audit-logs?per_page=100', admin)
  const actions = new Set((logs.json.data ?? []).map((x) => x.action))
  ok('sensitive operations audited (login + a mutation)', actions.has('auth.login') && [...actions].some((a) => !a.startsWith('auth.')), [...actions].join(','))
  if (process.env.SKIP_TAMPER !== '1') {
    // The chain is per organisation: tamper with a row of org A (the admin's organisation).
    const orgA = (await call('GET', '/me', admin)).json.data.organization.id
    const row = psql(`select id||'|'||action from audit_logs where organization_id='${orgA}' order by id desc offset 3 limit 1`)
    const [id, action] = row.split('|')
    try {
      psql(`update audit_logs set action='tampered' where id=${Number(id)}`)
      v = await call('GET', '/audit-logs/verify', admin)
      ok('tampered audit row detected', v.json.data.valid === false && v.json.data.broken_at != null, JSON.stringify(v.json))
    } finally { psql(`update audit_logs set action='${action.replace(/'/g, "''")}' where id=${Number(id)}`) }
    v = await call('GET', '/audit-logs/verify', admin)
    ok('audit chain valid again after restore', v.json.data.valid === true)
  }
  console.log(`\napi checks: ${pass} passed, ${fail} failed`)
  process.exit(fail ? 1 : 0)
})().catch((e) => { console.error(e); process.exit(1) })
