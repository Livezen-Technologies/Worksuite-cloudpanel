<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FW-011. The IP allow list and block list. One row per address or CIDR range;
 * a null domain_id covers every domain in the organisation. An expired row is
 * ignored when the firewall renders and removed by firewall:expire-ips.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_ip_lists', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();
            $t->string('list');                        // allow|block
            $t->string('cidr', 64);
            $t->string('note')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'list', 'cidr']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_ip_lists');
    }
};
