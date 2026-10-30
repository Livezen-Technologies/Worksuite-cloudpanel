<?php

namespace App\Http\Controllers;

use App\Services\FileShareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * FM File Sharing, public side. No account is needed: the token in the URL is the
 * capability. A password, when set, is sent as POST so it stays out of URLs and logs.
 */
class ShareController extends Controller
{
    public function __construct(private FileShareService $shares) {}

    public function show(Request $request, string $token)
    {
        $share = $this->shares->open($token, $request->isMethod('post') ? (string) $request->input('password', '') : null);
        $name = str_replace(['/', '\\', '"'], '_', (string) ($share->name ?: 'download'));

        return Storage::disk('local')->download($share->storagePath(), $name, [
            'Content-Type' => $share->format === 'zip' ? 'application/zip' : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
