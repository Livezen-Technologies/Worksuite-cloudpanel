<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UPRJ: what the customer said the project is for, asked when they create it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->string('purpose', 40)->nullable());
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('purpose'));
    }
};
