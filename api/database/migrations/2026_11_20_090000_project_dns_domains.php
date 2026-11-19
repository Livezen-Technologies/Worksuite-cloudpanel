<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDNS: project DNS & Domains. A domain belongs to a project (through its app, kept here so a
 * domain without an app still shows), one domain per project may be primary, custom domains carry
 * an ownership token that a TXT record proves, and DNS checks keep the full validation report.
 * Project owners grant other members DNS permissions per project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $t) {
            $t->uuid('project_id')->nullable()->index();
            $t->boolean('is_primary')->default(false);
            $t->string('dns_method', 16)->nullable(); // manual|provider|platform; null = created before PDNS
            $t->string('ownership_token', 64)->nullable();
            $t->timestamp('ownership_verified_at')->nullable();
        });
        Schema::table('dns_checks', function (Blueprint $t) {
            $t->string('kind', 16)->default('records'); // records (the 2-minute re-check) | validation (Validate DNS)
            $t->json('checks')->nullable();
        });
        Schema::create('project_domain_grants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->json('permissions');
            $t->uuid('granted_by')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_domain_grants');
        Schema::table('dns_checks', fn (Blueprint $t) => $t->dropColumn(['kind', 'checks']));
        Schema::table('domains', function (Blueprint $t) {
            $t->dropIndex(['project_id']);
            $t->dropColumn(['project_id', 'is_primary', 'dns_method', 'ownership_token', 'ownership_verified_at']);
        });
    }
};
