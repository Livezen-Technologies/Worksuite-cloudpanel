<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TBL-4: saved table views (columns, order, widths, filters, sorting, density, page size). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_views', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('table_key', 80);
            $t->string('name', 60);
            $t->json('config');
            $t->boolean('shared')->default(false);
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->index(['organization_id', 'table_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_views');
    }
};
