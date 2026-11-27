<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a server is for (servers, dns, hosting, database, backup, storage, cache); display and grouping only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->string('category', 20)->default('hosting')->index());
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('category'));
    }
};
