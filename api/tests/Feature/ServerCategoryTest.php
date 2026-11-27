<?php

namespace Tests\Feature;

use Tests\TestCase;

/** A server's category: defaults to hosting, takes only the seven names, and filters the list. */
class ServerCategoryTest extends TestCase
{
    public function test_category_defaults_validates_and_filters(): void
    {
        [, $org, $token] = $this->org();
        $web = $this->server($org);
        $edge = $this->server($org);

        $this->assertSame('hosting', $this->api($token)->getJson("/api/v1/servers/{$web->id}")->assertOk()->json('data.category'));

        $this->api($token)->patchJson("/api/v1/servers/{$edge->id}", ['category' => 'dns'])->assertOk();
        $this->api($token)->patchJson("/api/v1/servers/{$edge->id}", ['category' => 'mail'])->assertStatus(422);
        $this->assertSame('dns', $edge->fresh()->category);

        $ids = collect($this->api($token)->getJson('/api/v1/servers?category=dns')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$edge->id], $ids);
    }
}
