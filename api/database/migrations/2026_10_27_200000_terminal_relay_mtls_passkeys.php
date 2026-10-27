<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Terminal relay sessions + recordings (CTR-008/LOG-002), agent mTLS (SEC-003), passkeys (SEC-002/UACC-002). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminal_sessions', function (Blueprint $t) {
            $t->string('shell')->default('/bin/sh');
            $t->uuid('command_id')->nullable();
            $t->timestamp('connected_at')->nullable();
            $t->string('close_reason')->nullable();
            $t->unsignedBigInteger('recording_bytes')->nullable();
            $t->boolean('recording_stored')->default(false);
        });
        Schema::table('agent_certificates', function (Blueprint $t) {
            $t->string('fingerprint_sha1', 40)->nullable();
            $t->string('serial')->nullable();
            $t->timestamp('not_before')->nullable();
            $t->index('fingerprint');
        });
        Schema::table('passkeys', function (Blueprint $t) {
            $t->string('credential_hash', 64)->nullable()->unique();
            $t->unsignedBigInteger('sign_count')->default(0);
            $t->json('transports')->nullable();
            $t->string('aaguid')->nullable();
            $t->boolean('backup_eligible')->default(false);
            $t->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('terminal_sessions', fn (Blueprint $t) => $t->dropColumn(['shell', 'command_id', 'connected_at', 'close_reason', 'recording_bytes', 'recording_stored']));
        Schema::table('agent_certificates', function (Blueprint $t) {
            $t->dropIndex(['fingerprint']);
            $t->dropColumn(['fingerprint_sha1', 'serial', 'not_before']);
        });
        Schema::table('passkeys', fn (Blueprint $t) => $t->dropColumn(['credential_hash', 'sign_count', 'transports', 'aaguid', 'backup_eligible', 'last_used_at']));
    }
};
