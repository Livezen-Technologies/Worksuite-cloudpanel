<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Delivery health per channel, so a broken channel is visible instead of failing silently. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $t) {
            $t->timestamp('last_delivered_at')->nullable();
            $t->timestamp('last_failed_at')->nullable();
            $t->text('last_error')->nullable();
            $t->unsignedInteger('consecutive_failures')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', fn (Blueprint $t) => $t->dropColumn(['last_delivered_at', 'last_failed_at', 'last_error', 'consecutive_failures']));
    }
};
