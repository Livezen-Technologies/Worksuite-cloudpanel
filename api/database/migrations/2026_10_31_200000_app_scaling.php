<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCL-1..5, 9, 10 / USCL-1..3: replicas per app on its server, balanced by Traefik
 * across containers that share the app's service labels; fixed, autoscale or scheduled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $t) {
            $t->unsignedSmallInteger('replicas')->default(1);
            $t->json('scaling')->nullable();   // mode, min, max, cpu_target, schedule, sticky, health
        });
        Schema::create('scaling_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('organization_id')->index();
            $t->uuid('application_id')->index();
            $t->unsignedSmallInteger('from');
            $t->unsignedSmallInteger('to');
            $t->string('reason');                // manual, autoscale: CPU 82% > 70% …, schedule, deploy
            $t->string('result')->default('applied'); // applied|at_max|refused
            $t->uuid('user_id')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scaling_events');
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn(['replicas', 'scaling']));
    }
};
