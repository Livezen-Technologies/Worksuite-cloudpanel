<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DNS zone management (SRS 4.18). The provider is the source of truth; zones and
 * records are mirrored here so the panel can list them quickly and report drift.
 */
return new class extends Migration
{
    private function tenant(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
    }

    public function up(): void
    {
        Schema::create('dns_providers', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('type');                       // cloudflare (powerdns next)
            $t->text('credentials');                  // encrypted JSON: {token, account_id} for Cloudflare
            $t->string('account_label')->nullable();
            $t->timestamps();
        });

        Schema::create('dns_zones', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('dns_provider_id')->constrained('dns_providers')->cascadeOnDelete();
            $t->string('name');
            $t->string('remote_id');
            $t->string('status')->default('pending');     // pending (nameservers not delegated) | active
            $t->json('nameservers')->nullable();
            $t->unsignedInteger('records_count')->default(0);
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'name']);
        });

        Schema::create('dns_records', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('dns_zone_id')->constrained('dns_zones')->cascadeOnDelete();
            $t->string('remote_id');
            $t->string('type', 10);
            $t->string('name');
            $t->text('content');
            $t->unsignedInteger('ttl')->default(1);       // 1 = automatic
            $t->unsignedSmallInteger('priority')->nullable();
            $t->boolean('proxied')->default(false);
            $t->timestamps();
            $t->index(['dns_zone_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
        Schema::dropIfExists('dns_zones');
        Schema::dropIfExists('dns_providers');
    }
};
