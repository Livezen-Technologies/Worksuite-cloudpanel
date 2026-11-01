<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UAI: the assistant's conversations, one per user at a time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('user_id')->index();
            $t->longText('history'); // encrypted, serialised model messages (content passed back unchanged)
            $t->text('transcript')->nullable(); // encrypted json: what the person saw
            $t->unsignedInteger('turns')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
