<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Backup storage pool: SFTP servers that hold backups (Backup manager → Storage pool). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_storage_servers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id')->index();
            $t->string('name', 120);
            $t->string('host', 255);
            $t->unsignedInteger('port')->default(22);
            $t->string('username', 64);
            $t->string('path', 255)->default('backups');
            $t->text('private_key');      // encrypted
            $t->text('public_key');
            $t->text('host_keys')->nullable(); // known_hosts lines, pinned on the first successful test
            $t->boolean('enabled')->default(true);
            $t->unsignedBigInteger('total_bytes')->nullable();
            $t->unsignedBigInteger('used_bytes')->nullable();
            $t->unsignedBigInteger('free_bytes')->nullable();
            $t->string('status', 20)->default('untested'); // untested|ok|error
            $t->string('message', 500)->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_storage_servers');
    }
};
