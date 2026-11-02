<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-6: the terms a subscription is actually sold on.
 *
 * A subscription used to be a plan, a status and a period. That cannot
 * express a yearly cycle, a trial, a negotiated discount, or whether it
 * renews by itself -- all of which administrators were already being asked
 * about and answering by hand. `cancelled_at` in particular has to be a
 * column rather than an inference from `status`, because churn is a question
 * about when something was lost, and a status only says that it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->string('billing_cycle', 10)->default('monthly');   // monthly|yearly
            $t->unsignedTinyInteger('discount_percent')->default(0);
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('cancelled_at')->nullable()->index();
            $t->timestamp('expires_at')->nullable();
            $t->boolean('auto_renew')->default(true);
            // A package change booked for the next renewal rather than applied now.
            $t->uuid('pending_plan_id')->nullable();
            $t->timestamp('pending_plan_at')->nullable();
            $t->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn([
            'billing_cycle', 'discount_percent', 'trial_ends_at', 'cancelled_at',
            'expires_at', 'auto_renew', 'pending_plan_id', 'pending_plan_at', 'notes',
        ]));
    }
};
