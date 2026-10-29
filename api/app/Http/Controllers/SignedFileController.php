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

    /** FM-003: what a finished download job left behind, served once its signed URL is opened. */
    public function fileDownload(string $id)
    {
        $job = \App\Models\Job::allTenants()->where('type', 'files')->where('status', 'succeeded')->findOrFail($id);
        abort_unless(($job->payload['operation'] ?? null) === 'download', 404);
        $disk = Storage::disk('local');
        $path = \App\Services\FileService::downloadPath($job->id);
        abort_unless($disk->exists($path), 404);
        $name = str_replace(['/', '\\', '"'], '_', (string) ($job->result['name'] ?? 'download'));

        return $disk->download($path, $name, ['Content-Type' => ($job->result['format'] ?? '') === 'zip' ? 'application/zip' : 'application/octet-stream']);
    }

    /** FM-003: the agent sends a download here (signed relative URL, PUT body = the file or ZIP). */
    public function fileTransferPut(Request $request, string $id)
    {
        $job = \App\Models\Job::allTenants()->where('type', 'files')->findOrFail($id);
        abort_if($job->isFinished() || ($job->payload['operation'] ?? null) !== 'download', 409);
        Storage::disk('local')->put(\App\Services\FileService::downloadPath($job->id), $request->getContent(true));

        return response()->json(['data' => ['stored' => true]], 201);
    }

    /** FM-002: the agent fetches an uploaded file from here (signed relative URL). */
    public function fileTransferGet(string $id, string $n)
    {
        $path = \App\Services\FileService::TRANSFER_DIR.'/'.$id.'/'.$n;
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->download($path, $n, ['Content-Type' => 'application/octet-stream']);
    }
}
