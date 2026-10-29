<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FW-001. Firewall rules, country policy, challenge-protected paths and the
 * event log behind the Firewall screen.
 *
 * A rule with a null domain_id applies to every domain in the organisation;
 * otherwise it is scoped to that one domain. Rendering into Traefik middlewares
 * is FirewallConfigService's job — nothing here touches the proxy.
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
        Schema::create('firewall_rules', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();
            $t->string('name');
            $t->json('methods')->nullable();           // null = any method
            $t->json('paths');                         // ['/login', '/wp-login.php'] or ['*']
            $t->unsignedInteger('limit');              // requests allowed per window
            $t->unsignedInteger('window_seconds')->default(60);
            $t->string('counted_by')->default('ip');   // ip|ip_session|token_else_ip
            $t->string('action')->default('block');    // block|throttle|challenge|recaptcha|log
            $t->unsignedSmallInteger('priority')->default(100);
            $t->boolean('enabled')->default(true);
            $t->unsignedBigInteger('hits')->default(0);
            $t->timestamps();
            $t->index(['organization_id', 'enabled']);
        });

        Schema::create('firewall_countries', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();
            $t->string('code', 2);
            $t->string('state')->default('block');     // block|challenge
            $t->timestamps();
            $t->unique(['organization_id', 'domain_id', 'code']);
        });

        Schema::create('firewall_protected_paths', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();
            $t->string('path');
            $t->boolean('enabled')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'domain_id', 'path']);
        });

        Schema::create('firewall_events', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('domain_id')->nullable()->constrained('domains')->nullOnDelete();
            $t->foreignUuid('firewall_rule_id')->nullable()->constrained('firewall_rules')->nullOnDelete();
            $t->timestamp('occurred_at');
            $t->string('action');                      // block|throttle|challenge|recaptcha|log|allow
            $t->string('ip', 45);
            $t->string('country', 2)->nullable();
            $t->string('method', 10)->nullable();
            $t->string('path', 500)->nullable();
            $t->string('rule_name')->nullable();       // kept so events survive rule deletion
            $t->timestamps();
            $t->index(['organization_id', 'occurred_at']);
            $t->index(['organization_id', 'action']);
        });
    }

    public function down(): void
    {
        foreach (['firewall_events', 'firewall_protected_paths', 'firewall_countries', 'firewall_rules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
