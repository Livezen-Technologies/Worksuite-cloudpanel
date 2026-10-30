<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TPL-1..6: the template catalogue (synced from manifests) and what was installed from it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $t) {
            $t->string('id', 80)->primary();
            $t->string('name');
            $t->string('category', 40);
            $t->string('type', 12); // image|compose
            $t->unsignedInteger('version');
            $t->json('manifest');
            $t->string('source', 12)->default('bundled'); // bundled|remote
            $t->timestamp('synced_at')->nullable();
        });
        Schema::create('template_installs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('template_id', 80)->index();
            $t->unsignedInteger('version');
            $t->string('name', 63);
            $t->string('resource_type', 20); // application|compose_project
            $t->uuid('resource_id');
            $t->text('values'); // encrypted json: variables + generated secrets
            $t->foreignUuid('user_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_installs');
        Schema::dropIfExists('templates');
    }
};
