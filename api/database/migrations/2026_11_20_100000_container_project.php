<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which project a container belongs to, for display and counting only. A site's database, Redis
 * and worker containers are not part of the app (application_id), because a redeploy removes the
 * app's old containers; this column groups them under the project without that risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('containers', fn (Blueprint $t) => $t->uuid('project_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('containers', fn (Blueprint $t) => $t->dropColumn('project_id'));
    }
};
