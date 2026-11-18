<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Models\BackupStorageServer;
use App\Services\BackupPoolService;
use App\Support\SshKeys;
use Illuminate\Database\Eloquent\Model;

/** Backup manager → Storage pool: SFTP servers that hold backups. */
class BackupStorageServerController extends CrudController
{
    protected string $model = BackupStorageServer::class;

    protected array $searchable = ['name', 'host'];

    protected array $filters = ['enabled', 'status'];

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:120",
            'host' => [$req, 'string', 'max:255', 'regex:/^[A-Za-z0-9.:-]+$/'],
            'port' => 'sometimes|integer|min:1|max:65535',
            'username' => [$req, 'string', 'max:64', 'regex:/^[a-z_][a-z0-9_.-]*$/i'],
            'path' => ['sometimes', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]+$/', 'not_regex:/\.\./'],
            'private_key' => ['sometimes', 'nullable', 'string', 'max:16000', 'regex:/^-----BEGIN [A-Z ]*PRIVATE KEY-----/'],
            'enabled' => 'sometimes|boolean',
        ];
    }

    protected function prepare(array $data, ?Model $model = null): array
    {
        if (! $model && empty($data['private_key'])) {
            // No key given: the panel makes one; its public half is shown to paste into authorized_keys.
            $key = SshKeys::generate('wscp-backup-pool');
            $data['private_key'] = $key['private'];
            $data['public_key'] = $key['public'];
        } elseif (! empty($data['private_key'])) {
            $data['public_key'] = $data['public_key'] ?? '(key supplied by an administrator)';
        } else {
            unset($data['private_key']);
        }
        if ($model && (isset($data['host']) && $data['host'] !== $model->host || isset($data['port']) && (int) $data['port'] !== $model->port)) {
            $data['host_keys'] = null; // a different server: pin its key again
            $data['status'] = 'untested';
        }

        return $data;
    }

    protected function present(Model $model): array
    {
        $a = $model->toArray();
        $a['usage_percent'] = $model->total_bytes ? round($model->used_bytes / $model->total_bytes * 100, 1) : null;

        return $a;
    }

    public function check(BackupPoolService $pool, string $id)
    {
        return $this->data($this->present($pool->check($this->find($id))));
    }
}
