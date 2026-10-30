<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Volume;
use App\Services\FileService;
use Illuminate\Http\Request;

/** FM: browse and edit a volume's files. Every call returns a job to poll. */
class FileController extends Controller
{
    private const NAME = ['required', 'string', 'max:255', 'not_in:.,..', 'regex:/^[^\/\x00]+$/'];

    private const PATH = 'nullable|string|max:4096';

    public function __construct(private FileService $files) {}

    public function index(Request $request, string $id)
    {
        return $this->run($request, $id, 'list', $request->validate(['path' => self::PATH]));
    }

    public function show(Request $request, string $id)
    {
        return $this->run($request, $id, 'read', $request->validate(['path' => 'required|string|max:4096']));
    }

    public function write(Request $request, string $id)
    {
        // The content is never written to the audit log; FileService records its size.
        return $this->run($request, $id, 'write', $request->validate([
            'path' => 'required|string|max:4096', 'content' => 'present|nullable|string|max:1048576', 'create' => 'sometimes|boolean',
        ]) + ['content' => '']);
    }

    public function mkdir(Request $request, string $id)
    {
        return $this->run($request, $id, 'mkdir', $request->validate(['path' => 'required|string|max:4096']));
    }

    public function rename(Request $request, string $id)
    {
        return $this->run($request, $id, 'rename', $request->validate(['path' => 'required|string|max:4096', 'new_name' => self::NAME]));
    }

    public function destroy(Request $request, string $id)
    {
        return $this->run($request, $id, 'delete', $request->validate(['path' => 'required|string|max:4096', 'recursive' => 'sometimes|boolean']));
    }

    public function upload(Request $request, string $id)
    {
        $data = $request->validate([
            'path' => self::PATH, 'files' => 'required|array|min:1|max:500',
            'files.*' => 'file|max:'.config('files.max_upload_kb'),
            'relative_paths' => 'sometimes|array', 'relative_paths.*' => 'nullable|string|max:4096',
            'overwrite' => 'sometimes|boolean',
        ]);
        $job = $this->files->upload(Volume::findOrFail($id), (string) ($data['path'] ?? ''), $data['files'], $data['relative_paths'] ?? [], (bool) ($data['overwrite'] ?? false));
        $request->attributes->set('audit_skip', true);

        return $this->accepted($job);
    }

    public function download(Request $request, string $id)
    {
        $data = $request->validate(['paths' => 'required|array|min:1|max:500', 'paths.*' => 'string|max:4096']);
        $job = $this->files->download(Volume::findOrFail($id), array_values($data['paths']));
        $request->attributes->set('audit_skip', true);

        return $this->accepted($job);
    }

    public function copy(Request $request, string $id)
    {
        return $this->run($request, $id, 'copy', $this->selection($request) + $request->validate(['dest' => self::PATH]));
    }

    public function move(Request $request, string $id)
    {
        return $this->run($request, $id, 'move', $this->selection($request) + $request->validate(['dest' => self::PATH]));
    }

    public function compress(Request $request, string $id)
    {
        return $this->run($request, $id, 'compress', $this->selection($request) + $request->validate([
            'dest' => self::PATH, 'format' => 'required|in:zip,tar,tar.gz', 'name' => ['nullable', 'string', 'max:255', 'regex:/^[^\/\x00]+$/'],
        ]));
    }

    public function extract(Request $request, string $id)
    {
        return $this->run($request, $id, 'extract', $request->validate(['path' => 'required|string|max:4096', 'dest' => self::PATH, 'overwrite' => 'sometimes|boolean']));
    }

    public function trash(Request $request, string $id)
    {
        return $this->run($request, $id, 'trash', $this->selection($request));
    }

    public function trashIndex(Request $request, string $id)
    {
        return $this->run($request, $id, 'trash_list', []);
    }

    public function restore(Request $request, string $id)
    {
        return $this->run($request, $id, 'trash_restore', $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'string|max:64|regex:/^[A-Za-z0-9-]+$/']));
    }

    public function purge(Request $request, string $id)
    {
        return $this->run($request, $id, 'trash_purge', $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'string|max:64|regex:/^[A-Za-z0-9-]+$/']));
    }

    /** FM-009: three octal digits; setuid, setgid and sticky are refused. */
    public function chmod(Request $request, string $id)
    {
        return $this->run($request, $id, 'chmod', $this->selection($request) + $request->validate([
            'mode' => ['required', 'string', 'regex:/^0?[0-7]{3}$/'], 'recursive' => 'sometimes|boolean',
        ]));
    }

    /** FM-010: platform staff only; a customer could otherwise hand files to another tenant's user. */
    public function chown(Request $request, string $id)
    {
        abort_unless($this->seesAllTenants($request), 403, 'Only platform administrators can change file ownership.');
        $data = $request->validate([
            'uid' => 'nullable|integer|min:0|max:2147483646|required_without:gid', 'gid' => 'nullable|integer|min:0|max:2147483646',
            'recursive' => 'sometimes|boolean',
        ]);

        return $this->run($request, $id, 'chown', $this->selection($request) + array_filter($data, fn ($v) => $v !== null));
    }

    /** FM-011: by name, extension, size and modified date, from a folder down. */
    public function search(Request $request, string $id)
    {
        $data = $request->validate([
            'path' => self::PATH, 'name' => 'nullable|string|max:255', 'extensions' => 'sometimes|array|max:20', 'extensions.*' => 'string|max:20|regex:/^\.?[A-Za-z0-9]+$/',
            'min_size' => 'nullable|integer|min:0', 'max_size' => 'nullable|integer|min:0',
            'modified_after' => 'nullable|date_format:Y-m-d', 'modified_before' => 'nullable|date_format:Y-m-d', 'type' => 'nullable|in:file,dir',
        ]);
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
        if (count(array_diff_key($data, ['path' => 1])) === 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name' => 'Give at least one search condition.']);
        }

        return $this->run($request, $id, 'search', $data);
    }

    /** FM-012: prepares a signed, inline link for images, PDFs, audio and video. */
    public function preview(Request $request, string $id)
    {
        $data = $request->validate(['path' => 'required|string|max:4096']);
        if (! \App\Services\FileService::previewType($data['path'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['path' => 'This type is previewed as text, or not at all.']);
        }
        $job = $this->files->download(Volume::findOrFail($id), [$data['path']], inline: true);
        $request->attributes->set('audit_skip', true);

        return $this->accepted($job);
    }

    private function selection(Request $request): array
    {
        return $request->validate(['paths' => 'required|array|min:1|max:500', 'paths.*' => 'string|max:4096']);
    }

    private function run(Request $request, string $id, string $operation, array $payload)
    {
        $payload = array_map(fn ($v) => $v ?? '', $payload);
        if (isset($payload['paths'])) {
            $payload['paths'] = array_values($payload['paths']);
        }
        if (isset($payload['ids'])) {
            $payload['ids'] = array_values($payload['ids']);
        }
        $job = $this->files->run(Volume::findOrFail($id), $operation, $payload);
        // FileService audits writes with the file path; the generic entry would only repeat it.
        $request->attributes->set('audit_skip', true);

        return $this->accepted($job);
    }
}
