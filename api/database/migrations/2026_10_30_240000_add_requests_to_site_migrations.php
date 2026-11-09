<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UMIG: customers ask for a migration; an administrator picks the server and approves. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_migrations', function (Blueprint $t) {
            $t->uuid('server_id')->nullable()->change();
            $t->text('notes')->nullable();
            $t->uuid('requested_by')->nullable();
            $t->text('decision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_migrations', function (Blueprint $t) {
            $t->dropColumn(['notes', 'requested_by', 'decision']);
        });
    }
};
