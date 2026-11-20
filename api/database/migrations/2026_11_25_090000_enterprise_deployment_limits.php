<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise part E: a deployment can be resized to a preset or to custom
 * limits (POST /enterprise/deployments/{ref}/resize). `limits` holds the
 * effective size row (same keys as config enterprise.sizes) when it differs
 * from the preset named in `size`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_deployments', fn (Blueprint $t) => $t->json('limits')->nullable());
    }

    public function down(): void
    {
        Schema::table('enterprise_deployments', fn (Blueprint $t) => $t->dropColumn('limits'));
    }
};
