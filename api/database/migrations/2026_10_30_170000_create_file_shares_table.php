<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FM File Sharing: expiring public links to a snapshot of files taken when the link is made. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_shares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('volume_id');
            $t->uuid('job_id')->nullable()->index();
            $t->json('paths');
            $t->string('name')->nullable();
            $t->string('format')->nullable();               // file | zip
            $t->unsignedBigInteger('size')->nullable();
            $t->string('token_hash', 64)->unique();         // sha256 of the link token; the token itself is shown once
            $t->string('password_hash')->nullable();
            $t->string('status')->default('preparing');     // preparing | ready | failed
            $t->timestamp('expires_at');
            $t->unsignedInteger('max_downloads')->nullable();
            $t->unsignedInteger('download_count')->default(0);
            $t->timestamp('last_downloaded_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->index(['volume_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_shares');
    }
};
