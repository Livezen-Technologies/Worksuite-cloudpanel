<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ENV-1..7: projects with environments, shared variables, branch mapping and sleep. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $t) {
            $t->string('description')->nullable();
            $t->text('webhook_secret')->nullable(); // encrypted; signs Git push webhooks
        });
        Schema::table('environments', function (Blueprint $t) {
            $t->string('slug', 40)->nullable();
            $t->string('kind', 20)->default('development'); // production|staging|development|preview
            $t->string('branch', 200)->nullable();
            $t->text('variables')->nullable(); // encrypted json, shared by the environment's apps
            $t->unsignedInteger('sleep_after_minutes')->nullable();
            $t->uuid('duplicated_from_id')->nullable();
        });
        Schema::table('applications', function (Blueprint $t) {
            $t->foreignUuid('environment_id')->nullable()->constrained('environments')->nullOnDelete();
            $t->string('service', 63)->nullable(); // the same service across environments
            $t->timestamp('asleep_at')->nullable();
            $t->timestamp('woken_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $t) {
            $t->dropConstrainedForeignId('environment_id');
            $t->dropColumn(['service', 'asleep_at', 'woken_at']);
        });
        Schema::table('environments', fn (Blueprint $t) => $t->dropColumn(['slug', 'kind', 'branch', 'variables', 'sleep_after_minutes', 'duplicated_from_id']));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn(['description', 'webhook_secret']));
    }
};
