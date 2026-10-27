<?php

return [
    // Terminal relay (agent/cmd/relay). The relay calls /internal/relay/* with X-Relay-Secret.
    'relay' => [
        'secret' => env('RELAY_SECRET'),
        // Base the agent dials back to (signed into terminal.open) and keeps its control connection on.
        // Browser-facing base for ws_url (relative URLs resolve against the panel origin).
        'ws_url' => env('TERMINAL_WS_URL', '/relay/terminal'),
        'agent_url' => env('RELAY_AGENT_URL', rtrim(env('PANEL_URL', env('APP_URL', 'http://localhost')), '/').'/relay'),
        'recording_disk' => env('TERMINAL_RECORDING_DISK', 'local'),
        'recording_max_bytes' => (int) env('TERMINAL_RECORDING_MAX_BYTES', 8 * 1024 * 1024),
    ],
    'terminal' => [
        // Fixed shell list (first entry is used); the agent enforces its own list too.
        'shells' => array_values(array_filter(explode(',', env('TERMINAL_SHELLS', '/bin/sh')))),
        'shell_user' => env('TERMINAL_SHELL_USER', 'app'),
        'record' => (bool) env('TERMINAL_RECORD', true),
    ],

    // SEC-003 mutual TLS between agents and the control plane. TLS is terminated by the reverse proxy,
    // which verifies the client certificate against storage/app/private/ca/ca.crt and forwards it.
    'agent_mtls' => [
        'mode' => env('AGENT_MTLS', 'header'), // off | header (verify when forwarded) | required
        'verify_header' => env('AGENT_MTLS_VERIFY_HEADER', 'X-SSL-Client-Verify'),
        'fingerprint_header' => env('AGENT_MTLS_FINGERPRINT_HEADER', 'X-Client-Cert-Fingerprint'),
        'cert_header' => env('AGENT_MTLS_CERT_HEADER', 'X-Client-Cert'),
        'cert_days' => (int) env('AGENT_CERT_DAYS', 90),
    ],

    // Passkeys (WebAuthn). rp_id must be the panel's registrable domain; origins are the web app origins.
    'webauthn' => [
        'rp_name' => env('WEBAUTHN_RP_NAME', env('APP_NAME', 'Docker Manager Portal')),
        'rp_id' => env('WEBAUTHN_RP_ID', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
        'origins' => array_values(array_filter(explode(',', env('WEBAUTHN_ORIGINS', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))))),
        'challenge_ttl' => 300,
    ],
];
