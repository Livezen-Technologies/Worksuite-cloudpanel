<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WordPress staging (WP-010): a staging site points at its live site. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wp_sites', function (Blueprint $t) {
            $t->uuid('production_id')->nullable()->index();
            $t->string('staging_status')->nullable();   // provisioning, cloning, ready, pushing, failed
            $t->text('staging_password')->nullable();   // encrypted; the staging site's HTTP password
        });
    }

    public function down(): void
    {
        Schema::table('wp_sites', function (Blueprint $t) {
            $t->dropColumn(['production_id', 'staging_status', 'staging_password']);
        });
    }
};
