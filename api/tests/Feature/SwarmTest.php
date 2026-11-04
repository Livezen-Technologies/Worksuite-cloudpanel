<?php

namespace Tests\Feature;

use App\Models\AgentCommand;
use App\Models\Server;
use App\Models\SwarmCluster;
use Tests\TestCase;

/** SWARM: create a cluster, join and drain nodes, scale services, through agent commands. */
class SwarmTest extends TestCase
{
    private string $joinToken;

    protected function setUp(): void
    {
        parent::setUp();
        // A placeholder in the join token's shape, built at run time so secret scanners don't flag it.
        $this->joinToken = 'SWMTKN-1-'.str_repeat('a', 50).'-'.str_repeat('b', 25);
    }

    private function fleet(): array
    {
        [, $org, $token] = $this->org();
        $m = $this->server($org, ['name' => 'm1', 'ip' => '203.0.113.10']);
        [$ms] = $this->enrollAgent($m, $token);
        $this->heartbeat($ms);
        $w = $this->server($org, ['name' => 'w1', 'ip' => '203.0.113.11']);
        [$ws] = $this->enrollAgent($w, $token);
        $this->heartbeat($ws);

        return [$token, $m, $ms, $w, $ws];
    }

    private function last(string $type): AgentCommand
    {
        // Ids are time-ordered (UUIDv7), so they settle ties that created_at can't.
        return AgentCommand::allTenants()->where('type', $type)->orderByDesc('id')->firstOrFail();
    }

    public function test_a_cluster_is_created_joined_inspected_and_operated(): void
    {
        [$token, $m, $ms, $w, $ws] = $this->fleet();
        $c = $this->api($token)->postJson('/api/v1/swarm', ['name' => 'prod', 'manager_server_id' => $m->id])->assertStatus(202)->json('data.cluster');
        $this->assertSame('creating', $c['status']);
        $init = $this->last('swarm.init');
        $this->assertSame('203.0.113.10', $init->payload['advertise_addr']);

        // Nothing runs on a cluster that isn't up yet.
        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/join", ['server_id' => $w->id])->assertStatus(409);
        $this->commandResult($ms, $init->id, 'succeeded', ['output' => ['node_id' => 'node0abcdef1234', 'swarm_id' => 's1', 'worker_token' => $this->joinToken, 'manager_token' => 'SWMTKN-1-mgr']])->assertOk();
        $this->assertSame('manager', Server::allTenants()->find($m->id)->swarm_role);

        $page = $this->api($token)->getJson('/api/v1/swarm')->assertOk()->json('data');
        $this->assertSame('active', $page['clusters'][0]['status']);
        $this->assertStringNotContainsString('SWMTKN', json_encode($page), 'join tokens never leave the panel');
        $this->assertSame(['w1'], array_column($page['available_servers'], 'name'));

        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/join", ['server_id' => $w->id])->assertStatus(202);
        $join = $this->last('swarm.join');
        $this->assertSame($w->id, $join->server_id);
        $this->assertSame(['advertise_addr' => '203.0.113.11', 'manager' => '203.0.113.10:2377', 'token' => $this->joinToken, 'cluster_id' => $c['id']], $join->payload);
        $this->commandResult($ws, $join->id)->assertOk();
        $this->assertSame('worker', Server::allTenants()->find($w->id)->swarm_role);
        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/join", ['server_id' => $w->id])->assertStatus(409);

        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/refresh")->assertStatus(202);
        $this->commandResult($ms, $this->last('swarm.inspect')->id, 'succeeded', ['output' => [
            'nodes' => [['id' => 'node1abcdef1234', 'hostname' => 'w1', 'role' => 'worker', 'availability' => 'active', 'tasks' => 2]],
            'services' => [['id' => 'svc1abcdef1234', 'name' => 'web', 'replicas' => 3, 'running' => 3]],
        ]])->assertOk();
        $cl = $this->api($token)->getJson('/api/v1/swarm')->json('data.clusters.0');
        $this->assertSame('web', $cl['services'][0]['name']);
        $this->assertSame(2, $cl['nodes'][0]['tasks']);

        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/nodes/node1abcdef1234", ['availability' => 'drain'])->assertStatus(202);
        $this->assertSame(['node_id' => 'node1abcdef1234', 'availability' => 'drain'], array_intersect_key($this->last('swarm.node_update')->payload, ['node_id' => 1, 'availability' => 1]));
        $this->assertSame($m->id, $this->last('swarm.node_update')->server_id, 'cluster-wide commands run on the manager');
        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/services/svc1abcdef1234/scale", ['replicas' => 5])->assertStatus(202);
        $this->assertSame(5, $this->last('swarm.service_scale')->payload['replicas']);
        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/services/svc1abcdef1234/scale", ['replicas' => 500])->assertStatus(422);

        // The manager can't leave; the cluster can't be deleted while it has other nodes.
        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/leave", ['server_id' => $m->id])->assertStatus(409);
        $this->api($token)->deleteJson("/api/v1/swarm/{$c['id']}?confirm=prod")->assertStatus(409);
        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/leave", ['server_id' => $w->id])->assertStatus(202);
        $this->commandResult($ws, $this->last('swarm.leave')->id)->assertOk();
        $this->api($token)->deleteJson("/api/v1/swarm/{$c['id']}?confirm=nope")->assertStatus(422);
        $this->api($token)->deleteJson("/api/v1/swarm/{$c['id']}?confirm=prod")->assertStatus(202);
        $this->assertSame(0, SwarmCluster::allTenants()->count());
        $this->assertNull(Server::allTenants()->find($m->id)->swarm_cluster_id);
    }

    public function test_a_failed_init_is_reported_and_clusters_are_per_account(): void
    {
        [$token, $m, $ms] = $this->fleet();
        $c = $this->api($token)->postJson('/api/v1/swarm', ['name' => 'prod', 'manager_server_id' => $m->id])->json('data.cluster');
        $this->commandResult($ms, $this->last('swarm.init')->id, 'failed', ['message' => 'This node is already part of a swarm.'])->assertOk();
        $this->api($token)->getJson('/api/v1/swarm')->assertJsonPath('data.clusters.0.status', 'failed')->assertJsonPath('data.clusters.0.error', 'This node is already part of a swarm.');
        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/refresh")->assertStatus(409);

        [, , $other] = $this->org();
        $this->api($other)->postJson("/api/v1/swarm/{$c['id']}/refresh")->assertNotFound();
        $this->api($other)->postJson('/api/v1/swarm', ['name' => 'other', 'manager_server_id' => $m->id])->assertNotFound();
        $this->assertSame([], $this->api($other)->getJson('/api/v1/swarm')->json('data.clusters'));
    }

    public function test_services_are_deployed_updated_and_removed(): void
    {
        [$token, $m, $ms] = $this->fleet();
        $c = $this->api($token)->postJson('/api/v1/swarm', ['name' => 'prod', 'manager_server_id' => $m->id])->json('data.cluster');
        $this->commandResult($ms, $this->last('swarm.init')->id, 'succeeded', ['output' => ['node_id' => 'node0abcdef1234', 'worker_token' => $this->joinToken]])->assertOk();

        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/services", ['name' => 'web', 'image' => 'nginx:1.27', 'replicas' => 3,
            'env' => ['MODE' => 'prod'], 'ports' => [['target' => 80, 'published' => 8080]], 'constraints' => ['node.role==worker']])->assertStatus(202);
        $create = $this->last('swarm.service_create');
        $this->assertSame($m->id, $create->server_id);
        $this->assertSame(['name' => 'web', 'image' => 'nginx:1.27', 'replicas' => 3], array_intersect_key($create->payload, ['name' => 1, 'image' => 1, 'replicas' => 1]));
        $this->assertSame(['node.role==worker'], $create->payload['constraints']);

        // A change to services reads the cluster again.
        $before = AgentCommand::allTenants()->where('type', 'swarm.inspect')->count();
        $this->commandResult($ms, $create->id, 'succeeded', ['output' => ['service_id' => 'svcnewabcdef1234', 'name' => 'web']])->assertOk();
        $this->assertSame($before + 1, AgentCommand::allTenants()->where('type', 'swarm.inspect')->count());
        $this->commandResult($ms, $this->last('swarm.inspect')->id, 'succeeded', ['output' => ['nodes' => [], 'services' => [['id' => 'svcnewabcdef1234', 'name' => 'web', 'replicas' => 3, 'running' => 3]]]])->assertOk();

        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/services", ['name' => 'web', 'image' => 'nginx', 'replicas' => 1])->assertStatus(409);
        foreach ([['name' => 'Web App', 'image' => 'nginx', 'replicas' => 1], ['name' => 'api', 'image' => 'nginx', 'replicas' => 1, 'constraints' => ['zone=a; rm']],
            ['name' => 'api', 'image' => 'nginx', 'replicas' => 1, 'env' => ['bad-key' => 'x']]] as $bad) {
            $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/services", $bad)->assertStatus(422);
        }

        $this->api($token)->postJson("/api/v1/swarm/{$c['id']}/services/svcnewabcdef1234/image", ['image' => 'nginx:1.28'])->assertStatus(202);
        $this->assertSame('nginx:1.28', $this->last('swarm.service_image')->payload['image']);
        $this->api($token)->deleteJson("/api/v1/swarm/{$c['id']}/services/svcnewabcdef1234?confirm=nope")->assertStatus(422);
        $this->api($token)->deleteJson("/api/v1/swarm/{$c['id']}/services/svcnewabcdef1234?confirm=web")->assertStatus(202);
        $this->assertSame('svcnewabcdef1234', $this->last('swarm.service_remove')->payload['service_id']);
    }
}
