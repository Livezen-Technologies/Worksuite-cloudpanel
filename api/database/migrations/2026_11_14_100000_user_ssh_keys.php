<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SSH-GW: a person's own SSH keys, for shells through the panel's SSH gateway. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ssh_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('name', 80);
            $t->text('public_key');
            $t->string('fingerprint', 80)->unique();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ssh_keys');
    }
};
