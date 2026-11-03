<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CUST-5 phase 4: a dynamic group needs to remember when its rules were last
 * run, and an invoice needs to record the discount it was given and which
 * group gave it -- otherwise "why is this customer paying 15% less" is only
 * answerable by re-deriving today's groups, which may since have changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $t) {
            $t->timestamp('rules_run_at')->nullable();
            $t->integer('rules_matched')->nullable();   // the count at that run, for the UI
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->integer('discount_cents')->default(0);
            $t->uuid('customer_group_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('customer_groups', fn (Blueprint $t) => $t->dropColumn(['rules_run_at', 'rules_matched']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['discount_cents', 'customer_group_id']));
    }
};
