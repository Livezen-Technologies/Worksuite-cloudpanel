<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ENV: an environment per pull request, copied from the project's preview source. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->uuid('preview_environment_id')->nullable());
        Schema::table('environments', fn (Blueprint $t) => $t->unsignedInteger('preview_number')->nullable());
    }

    public function down(): void
    {
        Schema::table('environments', fn (Blueprint $t) => $t->dropColumn('preview_number'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('preview_environment_id'));
    }
};
