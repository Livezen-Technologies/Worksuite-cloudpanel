<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Services\AgentCertificateService;
use Illuminate\Http\Request;

/** SEC-003 certificate rotation and CA distribution. */
class CertificateController extends Controller
{
    public function __construct(private AgentCertificateService $certs) {}

    /** POST /agent/v1/certificate/renew {csr} — the agent calls this at 2/3 of the certificate lifetime. */
    public function renew(Request $request)
    {
        $data = $request->validate(['csr' => 'required|string|max:20000']);
        $row = $this->certs->renew($request->attributes->get('agent'), $data['csr']);

        return response()->json([
            'certificate' => $row->certificate,
            'fingerprint' => $row->fingerprint,
            'not_before' => $row->not_before?->toIso8601ZuluString(),
            'expires_at' => $row->expires_at->toIso8601ZuluString(),
        ]);
    }

    /** GET /agent/v1/ca.crt — the agent CA the proxy uses to verify client certificates. */
    public function ca()
    {
        return response($this->certs->ca()['cert'], 200, ['Content-Type' => 'application/x-pem-file', 'Cache-Control' => 'no-store']);
    }
}
