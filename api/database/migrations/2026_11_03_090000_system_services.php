<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SVC-1..5: the last systemd service snapshot per server and the services to watch. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->json('system_services')->nullable();
            $t->timestamp('system_services_at')->nullable();
            $t->json('services_watch')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn(['system_services', 'system_services_at', 'services_watch']));
    }
};
