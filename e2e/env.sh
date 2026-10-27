# Environment overrides for the e2e stack (real env vars win over api/.env).
export APP_ENV=local APP_DEBUG=true APP_URL=http://localhost:8000 PANEL_URL=http://localhost:8000
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=${DB_DATABASE:-dmp} DB_USERNAME=${DB_USERNAME:-dmp} DB_PASSWORD=${DB_PASSWORD:-dmp}
export QUEUE_CONNECTION=database CACHE_STORE=database SESSION_DRIVER=database
export DNS_DRIVER=fake SOURCE_VALIDATOR=fake CERT_DRIVER=fake SCANNER_DRIVER=fake PAYMENT_DRIVER=fake
export E2E_SCRATCH=${E2E_SCRATCH:-${TMPDIR:-/tmp}/dmp-e2e}
