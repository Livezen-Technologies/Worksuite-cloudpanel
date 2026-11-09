<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SITE: "Contact sales" requests from the marketing website. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_leads', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('email', 190);
            $t->string('company', 160)->nullable();
            $t->string('budget', 60)->nullable();
            $t->text('message')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_leads');
    }
};
