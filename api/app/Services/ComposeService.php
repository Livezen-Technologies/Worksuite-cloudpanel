<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\ComposeProject;
use App\Models\ComposeRevision;
use App\Models\ComposeService as ComposeServiceRow;
use App\Models\Job;
use App\Support\Tenant;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Compose stacks (CMP-001..005): validate, diff, revisions, deploy, rollback. */
class ComposeService implements HandlesCommandResults
{
    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    public function validate(string $yaml): array
    {
        $errors = [];
        $warnings = [];
        $services = [];
        try {
            $doc = Yaml::parse($yaml);
        } catch (ParseException $e) {
            return ['valid' => false, 'errors' => [['line' => $e->getParsedLine(), 'message' => $e->getMessage()]], 'warnings' => [], 'services' => []];
        }
        if (! is_array($doc) || ! isset($doc['services']) || ! is_array($doc['services']) || $doc['services'] === []) {
            $errors[] = ['line' => null, 'message' => 'A Compose file needs a non-empty "services" map.'];
        } else {
            $allowHost = (bool) Tenant::current()?->setting('security.allow_host_network');
            foreach ($doc['services'] as $name => $svc) {
                if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', (string) $name)) {
                    $errors[] = ['line' => null, 'message' => "Service name \"{$name}\" is not valid."];
                }
                if (! is_array($svc)) {
                    $errors[] = ['line' => null, 'message' => "Service \"{$name}\" must be a map."];

                    continue;
                }
                if (empty($svc['image']) && empty($svc['build'])) {
                    $errors[] = ['line' => null, 'message' => "Service \"{$name}\" needs an image or a build section."];
                }
                if (! empty($svc['privileged'])) {
                    $errors[] = ['line' => null, 'message' => "Service \"{$name}\": privileged mode is not allowed."];
                }
                if (($svc['network_mode'] ?? null) === 'host' && ! $allowHost) {
                    $errors[] = ['line' => null, 'message' => "Service \"{$name}\": host networking needs approval (disabled in security settings)."];
                }
                foreach ((array) ($svc['cap_add'] ?? []) as $cap) {
                    if (! in_array(strtoupper($cap), ContainerSpec::SAFE_CAPS, true)) {
                        $errors[] = ['line' => null, 'message' => "Service \"{$name}\": capability {$cap} is not allowed."];
                    }
                }
                if (in_array($svc['user'] ?? null, ['root', '0'], true)) {
                    $warnings[] = "Service \"{$name}\" runs as root; the agent will refuse it.";
                }
                foreach ((array) ($svc['volumes'] ?? []) as $v) {
                    $source = is_array($v) ? ($v['source'] ?? '') : explode(':', (string) $v)[0];
                    if (str_starts_with($source, '/') && ! str_starts_with($source, '/srv/dockermgr') && ! str_starts_with($source, '/var/lib/dockermgr/data')) {
                        $errors[] = ['line' => null, 'message' => "Service \"{$name}\": bind mount {$source} is outside the allowed paths (/srv/dockermgr)."];
                    }
                }
                $depends = $svc['depends_on'] ?? [];
                $depends = array_is_list((array) $depends) ? (array) $depends : array_keys($depends);
                foreach ($depends as $dep) {
                    if (! isset($doc['services'][$dep])) {
                        $errors[] = ['line' => null, 'message' => "Service \"{$name}\" depends on unknown service \"{$dep}\"."];
                    }
                }
                $services[] = ['name' => (string) $name, 'image' => $svc['image'] ?? null, 'depends_on' => array_values($depends), 'ports' => array_values((array) ($svc['ports'] ?? []))];
            }
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'warnings' => $warnings, 'services' => $services];
    }

    public function assertValid(string $yaml): array
    {
        $result = $this->validate($yaml);
        if (! $result['valid']) {
            throw new ApiException('validation_failed', 'The Compose file is not valid.', 422, ['yaml' => array_column($result['errors'], 'message')]);
        }

        return $result;
    }

    public function addRevision(ComposeProject $project, string $yaml, ?array $env = null): ComposeRevision
    {
        $result = $this->assertValid($yaml);
        $latest = $project->revisions()->first();
        if ($latest && $latest->yaml === $yaml && $env === null) {
            return $latest;
        }
        $revision = ComposeRevision::create([
            'compose_project_id' => $project->id,
            'number' => ($latest?->number ?? 0) + 1,
            'yaml' => $yaml,
            'env' => $env ?? $latest?->env,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
        $project->update(['current_revision_id' => $revision->id]);
        ComposeServiceRow::where('compose_project_id', $project->id)->delete();
        foreach ($result['services'] as $svc) {
            ComposeServiceRow::create(['compose_project_id' => $project->id, 'name' => $svc['name'], 'image' => $svc['image'], 'depends_on' => $svc['depends_on']]);
        }

        return $revision;
    }

    /** Line diff of a YAML candidate (or the current revision) against the running revision. */
    public function diff(ComposeProject $project, ?string $yaml): array
    {
        $running = $project->runningRevision;
        $candidate = $yaml ?? $project->currentRevision?->yaml ?? '';
        $from = $running ? explode("\n", rtrim($running->yaml, "\n")) : [];
        $to = explode("\n", rtrim($candidate, "\n"));
        $lines = self::lineDiff($from, $to);

        return [
            'from_revision' => $running?->number,
            'to_revision' => $yaml === null ? $project->currentRevision?->number : null,
            'added' => count(array_filter($lines, fn ($l) => $l['op'] === '+')),
            'removed' => count(array_filter($lines, fn ($l) => $l['op'] === '-')),
            'lines' => $lines,
            'unified' => implode("\n", array_map(fn ($l) => $l['op'].' '.$l['text'], $lines)),
        ];
    }

    /** LCS line diff. */
    public static function lineDiff(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['op' => ' ', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['op' => '-', 'text' => $a[$i++]];
            } else {
                $out[] = ['op' => '+', 'text' => $b[$j++]];
            }
        }
        while ($i < $n) {
            $out[] = ['op' => '-', 'text' => $a[$i++]];
        }
        while ($j < $m) {
            $out[] = ['op' => '+', 'text' => $b[$j++]];
        }

        return $out;
    }

    /** $pull forces `--pull always`; otherwise Compose pulls only missing images (local images keep working). */
    public function deploy(ComposeProject $project, ?ComposeRevision $revision = null, bool $pull = false): Job
    {
        $revision ??= $project->currentRevision;
        if (! $revision) {
            throw ApiException::conflict('Save a Compose revision before deploying.');
        }
        $server = $project->server;
        if (! $server) {
            throw ApiException::validation(['server_id' => ['Choose a target server.']]);
        }
        $job = $this->jobs->create('compose.deploy', ['validate', 'deploy', 'complete'], $project, ['revision_id' => $revision->id]);
        $this->jobs->stage($job, 'validate', 'succeeded', 'Revision '.$revision->number.' is valid');
        $this->jobs->stage($job, 'deploy', 'running');
        $this->bus->dispatch($server, 'compose.up', array_filter([
            'project' => $this->projectName($project),
            'compose_yaml' => $revision->yaml,
            'env' => $revision->env ? (object) $revision->env : null,
            'pull' => $pull ?: null,
        ], fn ($v) => $v !== null), $job);
        $project->update(['status' => 'deploying']);

        return $job;
    }

    public function action(ComposeProject $project, string $action): Job
    {
        $job = $this->jobs->create("compose.{$action}", ['execute'], $project);
        [$type, $payload] = match ($action) {
            'stop' => ['compose.down', ['project' => $this->projectName($project), 'remove_volumes' => false]],
            'restart' => ['compose.restart', ['project' => $this->projectName($project)]],
            'remove' => ['compose.down', ['project' => $this->projectName($project), 'remove_volumes' => false]],
        };
        $this->bus->dispatch($project->server, $type, $payload, $job);
        $project->update(['status' => match ($action) { 'stop' => 'stopped', 'remove' => 'removed', default => $project->status }]);

        return $job;
    }

    public function rollback(ComposeProject $project, ComposeRevision $target): Job
    {
        $revision = ComposeRevision::create([
            'compose_project_id' => $project->id,
            'number' => (int) ComposeRevision::where('compose_project_id', $project->id)->max('number') + 1,
            'yaml' => $target->yaml,
            'env' => $target->env,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
        $project->update(['current_revision_id' => $revision->id]);

        return $this->deploy($project, $revision);
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $project = ComposeProject::find($job->resource_id);
        if ($command->status === 'succeeded') {
            $project?->update(['status' => 'running', 'running_revision_id' => $job->payload['revision_id'] ?? null]);
            $this->jobs->succeed($job, ['revision_id' => $job->payload['revision_id'] ?? null]);
        } else {
            $project?->update(['status' => 'failed']);
            $this->jobs->fail($job, $command->message ?: 'docker compose up failed', 'deploy');
        }
    }

    public function projectName(ComposeProject $project): string
    {
        return preg_replace('/[^a-z0-9_-]/', '-', strtolower($project->name));
    }
}
