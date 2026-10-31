<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MCL: a backup MX on a second server that queues inbound mail while the primary is down. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_servers', function (Blueprint $t) {
            $t->uuid('backup_server_id')->nullable();
            $t->string('backup_hostname')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_servers', fn (Blueprint $t) => $t->dropColumn(['backup_server_id', 'backup_hostname']));
    }
};
