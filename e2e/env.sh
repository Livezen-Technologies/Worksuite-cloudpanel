# Environment overrides for the e2e stack (real env vars win over api/.env).
export APP_ENV=local APP_DEBUG=true APP_URL=http://localhost:8000 PANEL_URL=http://localhost:8000
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=${DB_DATABASE:-dmp} DB_USERNAME=${DB_USERNAME:-dmp} DB_PASSWORD=${DB_PASSWORD:-dmp}
export QUEUE_CONNECTION=database CACHE_STORE=database SESSION_DRIVER=database
export DNS_DRIVER=fake SOURCE_VALIDATOR=fake CERT_DRIVER=fake SCANNER_DRIVER=fake PAYMENT_DRIVER=fake
export E2E_SCRATCH=${E2E_SCRATCH:-${TMPDIR:-/tmp}/dmp-e2e}
# Backups: the agent uploads exports to the API directly; local repositories live in the scratch dir.
export AGENT_API_URL=http://127.0.0.1:8000 BACKUP_LOCAL_ROOT=${E2E_SCRATCH}/backup-repos
# Terminal relay (agent/cmd/relay on :8090, proxied by Vite at /relay); the agent learns RELAY_AGENT_URL from heartbeats.
export RELAY_SECRET=${RELAY_SECRET:-e2e-relay-secret-0123456789abcdef} RELAY_AGENT_URL=${RELAY_AGENT_URL:-ws://127.0.0.1:8090/relay}
export RELAY_LISTEN=${RELAY_LISTEN:-127.0.0.1:8090} RELAY_ALLOWED_ORIGINS=${RELAY_ALLOWED_ORIGINS:-http://localhost:5173}
