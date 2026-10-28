<?php

namespace App\Http\Controllers;

use App\Models\BackupArtifact;
use App\Models\Invoice;
use App\Services\BackupService;
use App\Services\InvoiceService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Files served through signed, expiring URLs (no bearer token: the signature is the capability).
 * Routes carry the `signed` middleware; ids are looked up across tenants because the signature
 * was issued for that exact resource.
 */
class SignedFileController extends Controller
{
    /** UBAK-002: the exported tar.gz of a snapshot (local destinations). */
    public function backupDownload(string $id)
    {
        $artifact = BackupArtifact::allTenants()->findOrFail($id);
        abort_unless($artifact->export_status === 'ready' && $artifact->export_disk === 'local', 404);
        $disk = Storage::disk(config('backups.export_disk'));
        abort_unless($disk->exists($artifact->export_path), 404);
        $name = 'backup-'.$artifact->created_at->format('Ymd-His').'-'.substr($artifact->id, 0, 8).'.tar.gz';

        return $disk->download($artifact->export_path, $name, ['Content-Type' => 'application/gzip', 'X-Content-SHA256' => (string) $artifact->export_sha256]);
    }

    /** Agent upload target for backup.export (signed relative URL, PUT body = tar.gz). */
    public function backupUpload(Request $request, string $id, BackupService $backups)
    {
        $artifact = BackupArtifact::allTenants()->findOrFail($id);
        abort_unless($artifact->export_status === 'exporting', 409);
        app(Tenant::class)->as($artifact->organization_id, fn () => $backups->storeUpload($artifact, $request->getContent(true), $request->header('X-Content-SHA256')));

        return response()->json(['data' => ['stored' => true]], 201);
    }

    /** UBIL-004: invoice PDF. */
    public function invoicePdf(string $id, InvoiceService $invoices)
    {
        $invoice = Invoice::allTenants()->findOrFail($id);

        return app(Tenant::class)->as($invoice->organization_id, fn () => response($invoices->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
        ]));
    }
}
