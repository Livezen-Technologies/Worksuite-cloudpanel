<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Staff SSO part 2: a panel user linked to a WorkSuite Cloud admin (`staff:<id>`), and who disabled them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('wsc_sub', 80)->nullable()->unique();
            $t->boolean('managed_by_wsc')->default(false);
            $t->timestamp('wsc_disabled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['wsc_sub']);
            $t->dropColumn(['wsc_sub', 'managed_by_wsc', 'wsc_disabled_at']);
        });
    }
};
