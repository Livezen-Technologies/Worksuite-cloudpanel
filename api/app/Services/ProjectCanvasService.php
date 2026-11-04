<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\EnvVar;
use App\Models\Project;
use Illuminate\Support\Collection;

/** UPRJ / PRJ: the parts of the project canvas staff and customers share. */
class ProjectCanvasService
{
    /** A host-like mention of `$name` in a variable value: http://api:8080, api.internal, user@api. */
    public static function mentions(string $value, string $name): bool
    {
        return (bool) preg_match('/(^|[\/@:=\s,])'.preg_quote($name, '/').'(?=$|[:\/\s,.])/i', $value);
    }

    /** A line wherever one app's variable names another app. Values never leave the server. */
    public function links(Collection $apps): array
    {
        $names = $apps->pluck('id', 'name');
        $links = [];
        $vars = EnvVar::whereIn('application_id', $apps->pluck('id'))->get()->groupBy('application_id');
        foreach ($apps as $a) {
            foreach ($vars->get($a->id, collect()) as $var) {
                foreach ($names as $name => $targetId) {
                    if ($targetId !== $a->id && self::mentions((string) $var->value, (string) $name)) {
                        $links[$a->id.'>'.$targetId] = ['from' => $a->id, 'to' => $targetId, 'via' => $var->key];
                    }
                }
            }
        }

        return array_values($links);
    }

    public function layout(Project $p, Collection $apps): object
    {
        return (object) collect($p->canvas_layout ?? [])->only($apps->pluck('id'))->all();
    }

    /** @param array<string, array{x:int,y:int}> $positions */
    public function saveLayout(Project $p, Collection $apps, array $positions): void
    {
        if (array_diff(array_keys($positions), $apps->pluck('id')->all())) {
            throw ApiException::validation(['positions' => ['Only apps in this project can be placed on its canvas.']]);
        }
        $p->update(['canvas_layout' => $positions ? collect($positions)->map(fn ($xy) => ['x' => (int) $xy['x'], 'y' => (int) $xy['y']])->all() : null]);
    }

    public static function layoutRules(): array
    {
        return ['positions' => 'present|array|max:500', 'positions.*.x' => 'required|integer|min:0|max:20000', 'positions.*.y' => 'required|integer|min:0|max:20000'];
    }
}
