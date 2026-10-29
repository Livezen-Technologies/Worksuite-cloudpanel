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

    private function run(Request $request, string $id, string $operation, array $payload)
    {
        $payload = array_map(fn ($v) => $v ?? '', $payload);
        $job = $this->files->run(Volume::findOrFail($id), $operation, $payload);
        // FileService audits writes with the file path; the generic entry would only repeat it.
        $request->attributes->set('audit_skip', true);

        return $this->accepted($job);
    }
}
