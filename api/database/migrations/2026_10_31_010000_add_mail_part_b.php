<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mail hosting part B: limits, spam, relay, health, DKIM rotation, auto-replies. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_servers', function (Blueprint $t) {
            $t->json('settings')->nullable();          // send_per_hour, spam_reject, spam_header, relay{host, port, username}
            $t->text('relay_password')->nullable();    // encrypted
            $t->json('health')->nullable();            // MAIL-010: ptr, a, blacklists
            $t->timestamp('health_checked_at')->nullable();
        });
        Schema::table('mail_domains', function (Blueprint $t) {
            $t->string('dkim_next_selector')->nullable();
            $t->text('dkim_next_private')->nullable(); // encrypted
            $t->text('dkim_next_public')->nullable();
            $t->string('dkim_previous_selector')->nullable();
            $t->timestamp('dkim_rotated_at')->nullable();
        });
        Schema::table('mailboxes', function (Blueprint $t) {
            $t->json('autoresponder')->nullable();     // enabled, subject, body, until
        });
    }

    public function down(): void
    {
        Schema::table('mail_servers', fn (Blueprint $t) => $t->dropColumn(['settings', 'relay_password', 'health', 'health_checked_at']));
        Schema::table('mail_domains', fn (Blueprint $t) => $t->dropColumn(['dkim_next_selector', 'dkim_next_private', 'dkim_next_public', 'dkim_previous_selector', 'dkim_rotated_at']));
        Schema::table('mailboxes', fn (Blueprint $t) => $t->dropColumn('autoresponder'));
    }
};
