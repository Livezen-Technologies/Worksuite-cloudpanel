<?php

namespace Tests\Feature;

use Tests\TestCase;

/** TBL-4: personal and shared saved views, defaults and permissions. */
class TableViewsTest extends TestCase
{
    public function test_personal_and_shared_views_with_defaults_and_permissions(): void
    {
        [, $org, $owner] = $this->org();
        [, $dev] = $this->member($org, 'developer');
        $cfg = ['columns' => ['name', 'state'], 'hidden' => ['ports'], 'widths' => ['name' => 220], 'sort' => [['key' => 'cpu', 'dir' => -1]],
            'conditions' => [['col' => 'state', 'op' => 'equals', 'value' => 'running']], 'density' => 'compact', 'pageSize' => 25, 'layout' => 'table'];

        $mine = $this->api($dev)->postJson('/api/v1/table-views', ['table' => 'containers', 'name' => 'Busy', 'config' => $cfg, 'is_default' => true])->assertCreated()->json('data');
        $this->assertTrue($mine['is_default']);
        $this->api($dev)->postJson('/api/v1/table-views', ['table' => 'containers', 'name' => 'Team', 'config' => $cfg, 'shared' => true])->assertForbidden();
        $this->api($dev)->postJson('/api/v1/table-views', ['table' => 'containers', 'name' => 'Bad', 'config' => ['density' => 'huge']])->assertStatus(422);
        $this->api($dev)->postJson('/api/v1/table-views', ['table' => 'Bad Key!', 'name' => 'x', 'config' => []])->assertStatus(422);

        $team = $this->api($owner)->postJson('/api/v1/table-views', ['table' => 'containers', 'name' => 'Team', 'config' => $cfg, 'shared' => true])->assertCreated()->json('data');
        $this->api($owner)->postJson('/api/v1/table-views', ['table' => 'containers', 'name' => 'Owner only', 'config' => $cfg])->assertCreated();

        $list = collect($this->api($dev)->getJson('/api/v1/table-views?table=containers')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(['Busy', 'Team'], $list->pluck('name')->all(), 'personal views of others stay private');
        $this->assertFalse($list->firstWhere('name', 'Team')['can_edit']);
        $this->api($dev)->patchJson("/api/v1/table-views/{$team['id']}", ['name' => 'Mine now'])->assertForbidden();
        $this->api($dev)->deleteJson("/api/v1/table-views/{$team['id']}")->assertNotFound();

        $second = $this->api($dev)->postJson('/api/v1/table-views', ['table' => 'containers', 'name' => 'Quiet', 'config' => $cfg, 'is_default' => true])->assertCreated()->json('data');
        $list = collect($this->api($dev)->getJson('/api/v1/table-views?table=containers')->json('data'));
        $this->assertFalse($list->firstWhere('id', $mine['id'])['is_default'], 'one personal default per table');
        $this->assertTrue($list->firstWhere('id', $second['id'])['is_default']);

        $this->api($dev)->patchJson("/api/v1/table-views/{$mine['id']}", ['name' => 'Renamed', 'config' => $cfg + ['q' => 'web']])->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->api($dev)->deleteJson("/api/v1/table-views/{$mine['id']}")->assertOk();

        [, , $stranger] = $this->org();
        $this->assertCount(0, $this->api($stranger)->getJson('/api/v1/table-views?table=containers')->json('data'), 'views never cross organisations');
    }
}
