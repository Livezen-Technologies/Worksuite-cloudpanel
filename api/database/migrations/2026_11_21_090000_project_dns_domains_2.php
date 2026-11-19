<?php

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PDNS slice 2. A DNS provider account connected by a customer from a project (source=customer,
 * with who connected it and when the token last verified); the last domain state a notification
 * went out for, so an unchanged state never notifies twice; and the new project_domains.manage
 * permission, which only takes effect once the reference seeder rebuilds role_permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_providers', function (Blueprint $t) {
            $t->string('source', 16)->default('admin'); // admin | customer
            $t->uuid('connected_by')->nullable();
            $t->timestamp('verified_at')->nullable();
        });
        Schema::table('domains', function (Blueprint $t) {
            $t->string('notified_state', 120)->nullable();
        });
        if (DB::table('roles')->count() > 0) {
            (new ReferenceDataSeeder)->run();
        }
    }

    public function down(): void
    {
        Schema::table('domains', fn (Blueprint $t) => $t->dropColumn('notified_state'));
        Schema::table('dns_providers', fn (Blueprint $t) => $t->dropColumn(['source', 'connected_by', 'verified_at']));
    }
};
