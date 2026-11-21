<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A scheduled maintenance window on an app: the scheduler locks it at start and, if it locked it
 * itself (maintenance_auto_locked), unlocks it at end. The notice is shown in-app beforehand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $t) {
            $t->timestamp('maintenance_scheduled_start')->nullable();
            $t->timestamp('maintenance_scheduled_end')->nullable();
            $t->string('maintenance_notice', 500)->nullable();
            $t->boolean('maintenance_auto_locked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn(['maintenance_scheduled_start', 'maintenance_scheduled_end', 'maintenance_notice', 'maintenance_auto_locked']));
    }
};
