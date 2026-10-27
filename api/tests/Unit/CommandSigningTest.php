<?php

namespace Tests\Unit;

use App\Support\CanonicalJson;
use App\Support\CommandSigner;
use PHPUnit\Framework\TestCase;

class CommandSigningTest extends TestCase
{
    /** Shared vector with the Go agent (agent/internal/signing). */
    public function test_fixed_vector_matches_agent(): void
    {
        $payload = ['b' => 1, 'a' => 'x'];
        $this->assertSame('1.container.start.{"a":"x","b":1}.2026-01-01T00:00:00Z', CommandSigner::message('1', 'container.start', $payload, '2026-01-01T00:00:00Z'));
        $signature = CommandSigner::sign('k', '1', 'container.start', $payload, '2026-01-01T00:00:00Z');
        fwrite(STDERR, "\nHMAC vector: {$signature}\n");
        $this->assertSame('b013b8a8ddc4621ea9b7b0cbe6d76235de16cab53337892b0bb475561dd09596', $signature);
    }

    public function test_canonical_json_sorts_recursively_without_whitespace(): void
    {
        $this->assertSame('{"a":[{"x":1,"y":2},3],"b":{"c":"/path","d":"é"}}', CanonicalJson::encode(['b' => ['d' => 'é', 'c' => '/path'], 'a' => [['y' => 2, 'x' => 1], 3]]));
        $this->assertSame('{}', CanonicalJson::encode([]));
        $this->assertSame('{"env":{},"list":[]}', CanonicalJson::encode(['list' => [], 'env' => new \stdClass]));
    }
}
