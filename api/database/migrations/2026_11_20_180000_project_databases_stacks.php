<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Databases and Compose stacks can belong to a project, like applications. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases', fn (Blueprint $t) => $t->uuid('project_id')->nullable()->index());
        Schema::table('compose_projects', fn (Blueprint $t) => $t->uuid('project_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('databases', fn (Blueprint $t) => $t->dropColumn('project_id'));
        Schema::table('compose_projects', fn (Blueprint $t) => $t->dropColumn('project_id'));
    }
};
