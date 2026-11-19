<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PWS: who may work on a project and how (owner, developer, viewer). Keyed by project id, not
 * name. No organization_id: the project carries it, and a tenant scope here would hide rows
 * from staff working on a customer's project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('role', 20);
            $t->timestamps();
            $t->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
    }
};
