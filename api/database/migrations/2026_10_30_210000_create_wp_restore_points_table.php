<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WordPress restore points (WP-005 / WP-011): a database dump plus the versions at the time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_restore_points', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('wp_site_id')->constrained('wp_sites')->cascadeOnDelete();
            $t->string('reason');                      // manual, update
            $t->string('status')->default('pending'); // pending, ready, failed, restored
            $t->string('version')->nullable();
            $t->json('plugins')->nullable();
            $t->json('themes')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamp('restored_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_restore_points');
    }
};
