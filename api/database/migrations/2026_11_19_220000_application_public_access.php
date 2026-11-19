<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Application lock: public_access false shuts every domain of the app to the public (Traefik answers
 * 403) except the always-allowed admin ranges and the app's allow list. Separate from IP blocks, so
 * unblocking an address never reopens a locked app, and unlocking an app never clears a block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $t) {
            $t->boolean('public_access')->default(true);
            $t->string('lock_reason', 255)->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->uuid('locked_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn(['public_access', 'lock_reason', 'locked_at', 'locked_by']));
    }
};
