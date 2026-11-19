<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Host firewall per server (nftables table "dockermgr", applied by the agent with auto-rollback). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_firewalls', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id')->index();
            $t->uuid('server_id')->unique();
            $t->boolean('enabled')->default(false);
            $t->string('mode', 16)->default('block');   // block | allow_only
            $t->json('block')->nullable();
            $t->json('allow')->nullable();
            $t->json('ports')->nullable();
            $t->string('status', 20)->default('not_applied'); // not_applied | applying | applied | failed | disabled
            $t->text('message')->nullable();
            $t->longText('ruleset')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->uuid('applied_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_firewalls');
    }
};
