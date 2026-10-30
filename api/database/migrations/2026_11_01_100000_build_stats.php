<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** BLD-2/3/9: detected stack, layer cache hits and build time per deployment. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $t) {
            $t->string('build_stack', 20)->nullable();
            $t->unsignedInteger('build_seconds')->nullable();
            $t->unsignedSmallInteger('build_cache_steps')->nullable();
            $t->unsignedSmallInteger('build_cache_hits')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $t) {
            $t->dropColumn(['build_stack', 'build_seconds', 'build_cache_steps', 'build_cache_hits']);
        });
    }
};
