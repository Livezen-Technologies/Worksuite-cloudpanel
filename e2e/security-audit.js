// Runtime security checks against the running stack (docs/security-audit.md, phases 2-4, 6, 7, 14).
// Non-destructive: it reads, attempts denied writes, and logs in/out. Run after api-checks.js.
//   node e2e/security-audit.js
const { apiCall, apiLogin, API } = require('./browser/lib')

let pass = 0
let fail = 0
const ok = (cond, name, detail = '') => {
  if (cond) { pass++; console.log(`PASS  ${name}`) } else { fail++; console.log(`FAIL  ${name}${detail ? `\n    ${detail}` : ''}`) }
}
const raw = (method, path, opts = {}) => fetch(API + path, { method, redirect: 'manual', ...opts })

;(async () => {
  // --- Phase 3: role x endpoint matrix (expectations from config/rbac.php) ---
  const tokens = {}
  for (const [role, email] of [['viewer', 'viewer@example.com'], ['developer', 'dev@example.com'], ['billing', 'billing@example.com']]) tokens[role] = await apiLogin(email)
  const matrix = [
    // [role, method, path, body, allowed]
    ['viewer', 'POST', '/servers', { name: 'sec-x', ip: '203.0.113.9' }, false],
    ['viewer', 'POST', '/users/invite', { email: 'x@example.com', role: 'owner' }, false],
    ['viewer', 'PATCH', '/settings', { org_name: 'pwned' }, false],
    ['viewer', 'GET', '/servers', undefined, true],
    ['viewer', 'GET', '/audit-logs', undefined, true],
    ['developer', 'POST', '/servers', { name: 'sec-x', ip: '203.0.113.9' }, false],
    ['developer', 'POST', '/users/invite', { email: 'x@example.com', role: 'owner' }, false],
    ['developer', 'PATCH', '/settings', { org_name: 'pwned' }, false],
    ['developer', 'GET', '/audit-logs', undefined, false],
    ['billing', 'POST', '/servers', { name: 'sec-x', ip: '203.0.113.9' }, false],
    ['billing', 'POST', '/users/invite', { email: 'x@example.com', role: 'owner' }, false],
    ['billing', 'GET', '/servers', undefined, false],
  ]
  for (const [role, method, path, body, allowed] of matrix) {
    const r = await apiCall(method, path, tokens[role], body)
    ok(allowed ? r.status === 200 : r.status === 403, `rbac: ${role} ${method} ${path} -> ${allowed ? 'allowed' : '403'}`, `got ${r.status}`)
  }

  // --- Phase 2: authentication and sessions ---
  ok((await apiCall('GET', '/servers')).status === 401, 'auth: no token -> 401')
  ok((await apiCall('GET', '/servers', '1|not-a-real-token')).status === 401, 'auth: forged token -> 401')
  const t = await apiLogin('viewer@example.com')
  ok((await apiCall('GET', '/me', t)).status === 200, 'auth: fresh token works')
  await apiCall('POST', '/auth/logout', t)
  ok((await apiCall('GET', '/me', t)).status === 401, 'auth: token is dead after logout (server-side revocation)')

  const unknown = await apiCall('POST', '/auth/login', null, { email: 'nobody-here@example.com', password: 'wrong-password-1' })
  const wrong = await apiCall('POST', '/auth/login', null, { email: 'viewer@example.com', password: 'wrong-password-1' })
  ok(unknown.status === wrong.status && unknown.json?.error?.message === wrong.json?.error?.message,
    'auth: unknown email and wrong password answer the same (no user enumeration)', `${unknown.status} "${unknown.json?.error?.message}" vs ${wrong.status} "${wrong.json?.error?.message}"`)
  const fp1 = await apiCall('POST', '/auth/forgot-password', null, { email: 'nobody-here@example.com' })
  const fp2 = await apiCall('POST', '/auth/forgot-password', null, { email: 'viewer@example.com' })
  ok(fp1.status === fp2.status && JSON.stringify(fp1.json) === JSON.stringify(fp2.json), 'auth: password reset does not reveal whether an account exists', `${fp1.status} vs ${fp2.status}`)

  // --- Phase 4: mass assignment, injection probes, pagination ---
  const dev = await apiLogin('dev@example.com')
  const before = (await apiCall('GET', '/me', dev)).json?.data
  for (const path of ['/me', '/me/profile', '/account']) await apiCall('PATCH', path, dev, { role: 'super_admin', is_super_admin: true, organization_id: '00000000-0000-0000-0000-000000000000' })
  const after = (await apiCall('GET', '/me', dev)).json?.data
  ok(JSON.stringify(before?.role ?? before?.membership?.role) === JSON.stringify(after?.role ?? after?.membership?.role) && before?.organization?.id === after?.organization?.id,
    'api: role / organisation cannot be set through a profile update (mass assignment)')
  const admin = await apiLogin('admin@example.com')
  for (const q of ["' OR 1=1--", "1;DROP TABLE servers", '"><script>alert(1)</script>']) {
    const r = await apiCall('GET', `/servers?search=${encodeURIComponent(q)}`, admin)
    ok(r.status === 200 && (r.json?.data ?? []).length === 0, `api: injection probe in search is inert: ${q}`, `got ${r.status} with ${(r.json?.data ?? []).length} rows`)
  }
  const s = await apiCall('GET', `/servers?sort=${encodeURIComponent('name;DROP TABLE servers')}`, admin)
  ok(s.status < 500, 'api: injection probe in sort does not error', `got ${s.status}`)
  const big = await apiCall('GET', '/servers?per_page=100000', admin)
  ok(big.status === 200 && (big.json?.meta?.per_page ?? 0) <= 200, 'api: per_page is capped (<= 200)', `per_page=${big.json?.meta?.per_page}`)

  // --- Phase 4 / 7: other entry points need their own credentials ---
  ok((await raw('POST', '/agent/v1/heartbeat', { headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: '{}' })).status === 401, 'agent: heartbeat without a secret -> 401')
  ok((await raw('POST', '/agent/v1/heartbeat', { headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: 'Bearer forged' }, body: '{}' })).status === 401, 'agent: heartbeat with a forged secret -> 401')
  const hook = await raw('POST', '/api/v1/hooks/git/00000000-0000-0000-0000-000000000000', { headers: { 'Content-Type': 'application/json', 'X-Hub-Signature-256': 'sha256=00' }, body: '{}' })
  ok([401, 404].includes(hook.status), 'webhook: unsigned or unknown git hook is rejected', `got ${hook.status}`)

  // --- Phase 7: files that must never be served ---
  for (const p of ['/.env', '/.git/config', '/storage/logs/laravel.log', '/composer.json', '/../.env', '/phpinfo.php']) {
    const r = await raw('GET', p)
    const body = r.status === 200 ? await r.text() : ''
    ok(r.status !== 200 || !/APP_KEY|DB_PASSWORD|\[core\]|"require"|phpinfo\(\)/.test(body), `files: ${p} is not exposed`, `got ${r.status}`)
  }

  // --- Phase 4: CORS ---
  const cors = await raw('OPTIONS', '/api/v1/servers', { headers: { Origin: 'https://evil.example', 'Access-Control-Request-Method': 'GET', 'Access-Control-Request-Headers': 'authorization' } })
  const acao = cors.headers.get('access-control-allow-origin')
  ok(acao !== 'https://evil.example' && !(acao === '*' && cors.headers.get('access-control-allow-credentials') === 'true'), 'cors: a foreign origin is not reflected / no wildcard with credentials', `allow-origin=${acao}`)

  // --- Phase 6: headers on live responses ---
  const me = await raw('GET', '/api/v1/me', { headers: { Accept: 'application/json', Authorization: `Bearer ${admin}` } })
  for (const [h, v] of [['x-content-type-options', 'nosniff'], ['x-frame-options', 'DENY'], ['referrer-policy', null], ['content-security-policy', null], ['permissions-policy', null]]) {
    const got = me.headers.get(h)
    ok(got && (!v || got === v), `headers: ${h}${v ? ` = ${v}` : ' present'}`, `got ${got}`)
  }
  ok(!me.headers.get('x-powered-by'), 'headers: no X-Powered-By')

  // --- Phase 6: errors do not leak internals (only meaningful with APP_DEBUG=false) ---
  const err = await raw('GET', '/api/v1/servers/not-a-uuid', { headers: { Accept: 'application/json', Authorization: `Bearer ${admin}` } })
  const errText = await err.text()
  if (process.env.APP_DEBUG === 'false') ok(!/vendor\/laravel|Stack trace|#0 \//.test(errText), 'errors: no stack trace in error responses')
  else console.log('SKIP  errors: no stack trace (the e2e stack runs with APP_DEBUG=true; .env.example ships false)')

  // Last: it can rate-limit this client's logins for a minute.
  let limited = false
  for (let i = 0; i < 15 && !limited; i++) limited = (await apiCall('POST', '/auth/login', null, { email: 'ratelimit@example.com', password: 'x'.repeat(10) })).status === 429
  ok(limited, 'auth: repeated failed logins are rate limited (429)')

  console.log(`\nsecurity audit: ${pass} passed, ${fail} failed`)
  process.exit(fail ? 1 : 0)
})().catch((e) => { console.error(e); process.exit(1) })
