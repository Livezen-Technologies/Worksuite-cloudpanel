<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volumes', function (Blueprint $table) {
            // Containers currently mounting this volume, as reported by the agent.
            $table->unsignedInteger('used_by')->default(0)->after('size_gb');
        });
    }

    public function down(): void
    {
        Schema::table('volumes', fn (Blueprint $table) => $table->dropColumn('used_by'));
    }
};
