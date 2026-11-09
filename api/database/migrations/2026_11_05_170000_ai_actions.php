<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UAI: every AI / MCP tool call, and the ones waiting for a person's approval. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_actions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('user_id')->nullable();
            $t->uuid('token_id')->nullable();
            $t->string('client', 120);
            $t->string('tool', 80);
            $t->string('target', 200)->nullable();
            $t->text('arguments')->nullable();
            $t->string('status', 20); // done|failed|pending|rejected|refused
            $t->text('result')->nullable();
            $t->string('error', 500)->nullable();
            $t->uuid('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_actions');
    }
};
