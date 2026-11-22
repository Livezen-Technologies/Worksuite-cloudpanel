<?php

namespace Tests\Feature;

use App\Models\Container;
use App\Models\Database;
use Tests\TestCase;

/** Container rows keep their id across a Docker recreate, and databases repair a stale container_id. */
class ContainerIdStabilityTest extends TestCase
{
    private function report(string $dockerId, string $name, string $state = 'running'): array
    {
        return ['docker_id' => $dockerId, 'name' => '/'.$name, 'image' => 'mariadb:10.11', 'state' => $state];
    }

    public function test_recreated_container_keeps_its_row_id(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);

        $this->heartbeat($secret, ['containers' => [$this->report('aaa111', 'db-shop'), $this->report('bbb222', 'db-other')]]);
        $row = Container::allTenants()->where('server_id', $server->id)->where('name', 'db-shop')->firstOrFail();
        $db = Database::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'container_id' => $row->id, 'name' => 'shop', 'engine' => 'mariadb', 'version' => '10.11']);

        // Docker recreated db-shop: same name, new docker id.
        $this->heartbeat($secret, ['containers' => [$this->report('ccc333', 'db-shop'), $this->report('bbb222', 'db-other')]]);

        $rows = Container::allTenants()->where('server_id', $server->id)->where('name', 'db-shop')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($row->id, $rows[0]->id);
        $this->assertSame('ccc333', $rows[0]->docker_id);
        $this->assertSame($row->id, $db->fresh()->resolveContainer()?->id);
        $this->assertSame(2, Container::allTenants()->where('server_id', $server->id)->count());
    }

    public function test_a_live_row_is_never_taken_over_and_gone_rows_are_removed(): void
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        [$secret] = $this->enrollAgent($server, $token);
        $this->heartbeat($secret, ['containers' => [$this->report('aaa111', 'db-shop')]]);
        $id = Container::allTenants()->where('server_id', $server->id)->value('id');

        $this->heartbeat($secret, ['containers' => [$this->report('aaa111', 'db-shop')]]);
        $this->assertSame($id, Container::allTenants()->where('server_id', $server->id)->value('id'));

        $this->heartbeat($secret, ['containers' => []]);
        $this->assertSame(0, Container::allTenants()->where('server_id', $server->id)->count());
    }

    public function test_database_repairs_a_stale_container_id_on_its_own_server_only(): void
    {
        [, $org] = $this->org();
        $server = $this->server($org);
        $other = $this->server($org);
        $orphan = Container::allTenants()->create(['organization_id' => $org->id, 'server_id' => $other->id, 'name' => 'db-ent-x-db', 'image' => 'mariadb', 'state' => 'running', 'docker_id' => 'o1']);
        $db = Database::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'container_id' => '01a1fc58-ac40-7000-8000-000000000000', 'name' => 'ent-x-db', 'engine' => 'mariadb', 'version' => '10.11']);

        // Only a same-named container on another server: not picked.
        $this->assertNull($db->resolveContainer());
        $this->assertSame('01a1fc58-ac40-7000-8000-000000000000', $db->fresh()->container_id);

        // Stale id pointing at the other server's row is not accepted either.
        $db->update(['container_id' => $orphan->id]);
        $this->assertNull($db->fresh()->resolveContainer());

        $mine = Container::allTenants()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'name' => 'db-ent-x-db', 'image' => 'mariadb', 'state' => 'running', 'docker_id' => 'm1']);
        $this->assertSame($mine->id, $db->fresh()->resolveContainer()?->id);
        $this->assertSame($mine->id, $db->fresh()->container_id);
    }
}
