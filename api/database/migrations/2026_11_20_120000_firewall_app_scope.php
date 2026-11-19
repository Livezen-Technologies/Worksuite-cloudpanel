<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firewall scope beyond one domain: a rule or IP entry can apply to a whole project or to one
 * application (all of its domains). Rules also get their own burst and block duration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firewall_rules', function (Blueprint $t) {
            $t->uuid('application_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->unsignedInteger('burst')->nullable();          // null: a tenth of the limit
            $t->unsignedInteger('block_minutes')->nullable();  // null: platform default ban length
        });
        Schema::table('firewall_ip_lists', function (Blueprint $t) {
            $t->uuid('application_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('firewall_rules', fn (Blueprint $t) => $t->dropColumn(['application_id', 'project_id', 'burst', 'block_minutes']));
        Schema::table('firewall_ip_lists', fn (Blueprint $t) => $t->dropColumn(['application_id', 'project_id']));
    }
};
