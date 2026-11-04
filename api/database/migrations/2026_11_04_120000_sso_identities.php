<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SSO-ID: which sign-in apps a person uses, and whether they turned one off.
        Schema::create('sso_identities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('provider', 40);
            $t->string('email')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('blocked_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_identities');
    }
};
