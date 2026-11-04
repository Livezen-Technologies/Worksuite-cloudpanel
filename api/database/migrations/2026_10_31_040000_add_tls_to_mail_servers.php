<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MAIL-005: where the mail server's certificate comes from and when it expires. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_servers', fn (Blueprint $t) => $t->json('tls')->nullable());
    }

    public function down(): void
    {
        Schema::table('mail_servers', fn (Blueprint $t) => $t->dropColumn('tls'));
    }
};
