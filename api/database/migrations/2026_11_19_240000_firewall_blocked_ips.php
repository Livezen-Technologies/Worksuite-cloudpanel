<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The last known CrowdSec bans per ingress (refreshed every few minutes) for the Blocked IPs tab. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_blocked_ips', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('server_id')->index();
            $t->bigInteger('decision_id')->nullable();
            $t->string('ip', 64)->index();
            $t->string('origin', 20);          // crowdsec (automatic) | cscli (by hand)
            $t->string('scenario', 190)->nullable();
            $t->string('country', 8)->nullable();
            $t->unsignedInteger('events')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_blocked_ips');
    }
};
