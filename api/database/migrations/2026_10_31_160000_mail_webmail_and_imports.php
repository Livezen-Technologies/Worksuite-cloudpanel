<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MAIL webmail and IMAP import (MCL-10, UMAIL). Roundcube runs beside the mail
 * container; imports copy a remote IMAP account into a mailbox with imapsync,
 * signing in to Dovecot as a master user so the mailbox password is never needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_servers', function (Blueprint $t) {
            $t->string('webmail_hostname')->nullable();
            $t->string('webmail_status')->nullable();          // installing|running|failed
            $t->text('master_password')->nullable();           // encrypted; Dovecot master user for imports
        });
        Schema::create('mail_imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $t->string('source_host');
            $t->unsignedSmallInteger('source_port')->default(993);
            $t->string('source_security')->default('ssl');     // ssl|starttls|none
            $t->string('source_user');
            $t->text('source_password');                       // encrypted, cleared when the import ends
            $t->string('status')->default('queued');           // queued|running|succeeded|failed
            $t->unsignedInteger('messages')->nullable();
            $t->unsignedInteger('folders')->nullable();
            $t->unsignedBigInteger('bytes')->nullable();
            $t->unsignedInteger('errors')->nullable();
            $t->text('log')->nullable();                       // last 16 KB
            $t->uuid('job_id')->nullable();
            $t->foreignUuid('user_id')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_imports');
        Schema::table('mail_servers', fn (Blueprint $t) => $t->dropColumn(['webmail_hostname', 'webmail_status', 'master_password']));
    }
};
