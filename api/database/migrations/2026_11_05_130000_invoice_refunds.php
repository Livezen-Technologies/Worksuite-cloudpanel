<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Refunds: credit notes are invoices of kind credit_note pointing at the invoice they refund. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('kind', 20)->default('invoice');
            $t->uuid('credit_for_id')->nullable()->index();
            $t->integer('refunded_cents')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['kind', 'credit_for_id', 'refunded_cents']));
    }
};
