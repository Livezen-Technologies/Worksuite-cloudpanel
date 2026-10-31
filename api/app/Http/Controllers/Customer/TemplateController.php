<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\TemplateService;

/** TPL / UHOST: single-image templates customers can host, from the synced catalogue. */
class TemplateController extends Controller
{
    public function index(TemplateService $templates)
    {
        $rows = $templates->catalogue()
            ->filter(fn ($t) => $t->type === 'image' && ($t->manifest['customer'] ?? true) !== false)
            ->map(function ($t) use ($templates) {
                $p = $templates->present($t);

                return [
                    'id' => $p['id'], 'name' => $p['name'], 'category' => $p['category'], 'description' => $p['description'], 'accent' => $p['accent'],
                    'image' => $p['image'], 'port' => $p['port'], 'volume_path' => $t->manifest['volume'] ?? null, 'size' => $t->manifest['size'] ?? 'small',
                    'variables' => $p['variables'], 'generates' => count($p['generated']),
                ];
            })->values();

        return $this->data($rows);
    }
}
