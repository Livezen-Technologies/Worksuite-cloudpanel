<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dunning: how many times the panel tried to collect an invoice, and when it tries next. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->unsignedSmallInteger('collection_attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['collection_attempts', 'next_attempt_at']));
    }
};
