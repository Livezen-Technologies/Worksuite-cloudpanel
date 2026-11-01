<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the downloaded backup archive was removed from the migration's server. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_migrations', fn (Blueprint $t) => $t->timestamp('archive_removed_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('site_migrations', fn (Blueprint $t) => $t->dropColumn('archive_removed_at'));
    }
};
