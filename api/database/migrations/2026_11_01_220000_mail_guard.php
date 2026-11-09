<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MCL: per-domain limits, per-mailbox spam settings, sending holds and searchable delivery logs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_domains', function (Blueprint $t) {
            $t->json('limits')->nullable(); // max_mailboxes, max_aliases, quota_total_mb, send_per_hour
            $t->timestamp('held_at')->nullable();
            $t->string('held_reason')->nullable();
        });
        Schema::table('mailboxes', function (Blueprint $t) {
            $t->json('spam')->nullable(); // level, allow[], block[]
            $t->timestamp('held_at')->nullable();
            $t->string('held_reason')->nullable();
        });
        Schema::table('mail_servers', fn (Blueprint $t) => $t->timestamp('log_cursor')->nullable());
        Schema::create('mail_log_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('mail_server_id')->constrained('mail_servers')->cascadeOnDelete();
            $t->timestamp('at')->index();
            $t->string('queue_id', 20)->nullable();
            $t->string('direction', 8); // out|in
            $t->string('sender')->nullable()->index();
            $t->string('recipient')->nullable()->index();
            $t->string('domain')->nullable()->index(); // the hosted domain the entry belongs to
            $t->string('status', 12); // sent|deferred|bounced|rejected
            $t->string('relay')->nullable();
            $t->string('detail', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_log_entries');
        Schema::table('mail_servers', fn (Blueprint $t) => $t->dropColumn('log_cursor'));
        Schema::table('mailboxes', fn (Blueprint $t) => $t->dropColumn(['spam', 'held_at', 'held_reason']));
        Schema::table('mail_domains', fn (Blueprint $t) => $t->dropColumn(['limits', 'held_at', 'held_reason']));
    }
};
