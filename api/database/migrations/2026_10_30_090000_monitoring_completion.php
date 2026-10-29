<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MON-00x completion: notification cooldowns and escalation, auto-restart
 * bookkeeping for unhealthy containers, and per-check uptime history so
 * uptime percentages and downtime reports can be computed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $t) {
            // Cooldown and escalation state. An alert is deduplicated by there
            // being one open alert per resource; these govern how often it may
            // notify and when it escalates.
            $t->timestamp('last_notified_at')->nullable()->after('resolved_at');
            $t->unsignedInteger('notify_count')->default(0)->after('last_notified_at');
            $t->timestamp('escalated_at')->nullable()->after('notify_count');
            $t->boolean('suppressed')->default(false)->after('escalated_at');
        });

        Schema::table('containers', function (Blueprint $t) {
            // Auto-restart attempts are rate-limited, so a container that is
            // broken rather than wedged is not restarted forever.
            $t->unsignedInteger('auto_restart_attempts')->default(0)->after('health');
            $t->timestamp('last_auto_restart_at')->nullable()->after('auto_restart_attempts');
        });

        // One row per probe. 288 rows per domain per day at a five-minute
        // sweep; pruned by monitoring:prune.
        Schema::create('uptime_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('domain_id')->constrained('domains')->cascadeOnDelete();
            $t->boolean('ok');
            $t->unsignedSmallInteger('status')->nullable();
            $t->unsignedInteger('ms')->nullable();
            $t->string('error')->nullable();
            $t->timestamp('checked_at');
            $t->index(['domain_id', 'checked_at']);
            $t->index(['organization_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_checks');
        Schema::table('containers', fn (Blueprint $t) => $t->dropColumn(['auto_restart_attempts', 'last_auto_restart_at']));
        Schema::table('alerts', fn (Blueprint $t) => $t->dropColumn(['last_notified_at', 'notify_count', 'escalated_at', 'suppressed']));
    }
};
