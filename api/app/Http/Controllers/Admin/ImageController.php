<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\ImageScanner;
use App\Http\Controllers\Controller;
use App\Models\Container;
use App\Models\Image;
use App\Models\Registry;
use App\Models\Server;
use App\Services\CommandBus;
use App\Services\ContainerSpec;
use App\Services\JobService;
use App\Support\Lists;
use Illuminate\Http\Request;

class ImageController extends Controller
{
    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    public function index(Request $request)
    {
        $users = Container::query()->get(['image'])->countBy('image');

        return response()->json(Lists::paginate(Image::query(), $request, ['repository', 'digest'], ['created_at', 'size_mb', 'repository'], ['server_id', 'scan_status'],
            fn (Image $i) => $i->toArray() + ['users' => collect($i->tags ?? [])->sum(fn ($t) => $users[$t] ?? 0), 'dangling' => empty($i->tags)]));
    }

    public function scan(string $id, ImageScanner $scanner)
    {
        $image = Image::findOrFail($id);
        $job = $this->jobs->create('image.scan', ['scan'], $image);
        $result = $scanner->scan($image->tags[0] ?? $image->docker_id, $image->digest);
        $image->update(['scan_status' => 'scanned', 'scan_result' => $result, 'scanned_at' => now()]);
        $this->jobs->succeed($job, $result);

        return $this->accepted($job->fresh(), ['image_id' => $image->id, 'result' => $result]);
    }

    private function pruneCandidates(Request $request)
    {
        $used = Container::query()->pluck('image')->all();

        return Image::query()->when($request->input('server_id'), fn ($q, $s) => $q->where('server_id', $s))->get()
            ->filter(fn ($i) => empty($i->tags) || ($request->boolean('all') && ! array_intersect($i->tags ?? [], $used)));
    }

    public function prunePreview(Request $request)
    {
        $images = $this->pruneCandidates($request);

        return $this->data(['images' => $images->values(), 'count' => $images->count(), 'reclaim_mb' => round($images->sum('size_mb'), 1)]);
    }

    public function prune(Request $request)
    {
        $request->validate(['server_id' => 'nullable|uuid', 'ids' => 'nullable|array', 'ids.*' => 'uuid', 'all' => 'nullable|boolean']);
        $job = $this->jobs->create('image.prune', ['execute']);
        if ($ids = $request->input('ids')) {
            foreach (Image::whereIn('id', $ids)->get() as $image) {
                $this->bus->dispatch($image->server, 'image.remove', ['image' => $image->docker_id, 'force' => false], $job);
            }
        } else {
            $servers = Server::query()->when($request->input('server_id'), fn ($q, $s) => $q->whereKey($s))->get()->filter->isOnline();
            foreach ($servers as $server) {
                $this->bus->dispatch($server, 'image.prune', ['all' => $request->boolean('all')], $job);
            }
        }
        if (! $job->commands()->exists()) {
            $this->jobs->succeed($job, ['message' => 'Nothing to prune.']);
        }

        return $this->accepted($job->fresh());
    }

    public function pull(Request $request)
    {
        $data = $request->validate(['server_id' => 'required|uuid', 'image' => 'required|string|max:255', 'registry_id' => 'nullable|uuid']);
        $server = Server::findOrFail($data['server_id']);
        [$image, $tag] = str_contains(basename($data['image']), ':') ? [substr($data['image'], 0, strrpos($data['image'], ':')), substr($data['image'], strrpos($data['image'], ':') + 1)] : [$data['image'], 'latest'];
        $payload = ['image' => $image, 'tag' => $tag];
        if (! empty($data['registry_id'])) {
            $registry = Registry::findOrFail($data['registry_id']);
            $payload['registry_auth'] = ContainerSpec::registryAuth($registry->url, $registry->username, $registry->credentials);
        }
        $job = $this->jobs->create('image.pull', ['execute'], $server, ['image' => $data['image']]);
        $this->bus->dispatch($server, 'image.pull', $payload, $job);

        return $this->accepted($job);
    }
}
