<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Admin\FileController as AdminFileController;
use App\Models\Application;
use App\Models\Volume;

/**
 * FM in the customer panel (User · Files): the same file operations as the
 * admin page, addressed by app instead of by volume. An app is found through
 * the tenant scope, so a customer only ever reaches their own apps' storage;
 * the volume itself may belong to the platform (shared-pool servers).
 */
class FileController extends AdminFileController
{
    /** The customer's apps that keep files, and whether their storage is ready. */
    public function apps()
    {
        $apps = Application::query()->whereNotNull('volume_path')->orderBy('name')->get();

        return $this->data($apps->map(fn (Application $app) => [
            'id' => $app->id, 'name' => $app->name, 'volume_path' => $app->volume_path,
            'ready' => $this->storage($app) !== null,
        ])->values());
    }

    protected function volume(string $id): Volume
    {
        $app = Application::findOrFail($id);
        abort_unless($app->volume_path, 404, 'This app does not keep files between restarts.');

        return $this->storage($app) ?? abort(409, 'This app\'s storage has not been reported by its server yet. Try again in a minute.');
    }

    private function storage(Application $app): ?Volume
    {
        return Volume::allTenants()->where('server_id', $app->server_id)->where('name', 'app-'.$app->name.'-data')->first();
    }
}
