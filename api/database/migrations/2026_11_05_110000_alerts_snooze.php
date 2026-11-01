<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An alert can be snoozed: it stays open but sends no reminders or escalation until this time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $t) {
            $t->timestamp('snoozed_until')->nullable();
            $t->uuid('resolved_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', fn (Blueprint $t) => $t->dropColumn(['snoozed_until', 'resolved_by']));
    }
};
