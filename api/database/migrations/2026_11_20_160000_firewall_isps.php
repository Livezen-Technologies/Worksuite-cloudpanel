<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ISP (autonomous system) rules: block, challenge or always allow traffic from one network operator. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_isps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id')->index();
            $t->unsignedBigInteger('asn');
            $t->string('name', 160)->nullable();
            $t->string('state', 16)->default('block'); // block | challenge | allow
            $t->uuid('domain_id')->nullable();
            $t->uuid('application_id')->nullable();
            $t->uuid('project_id')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'asn', 'domain_id', 'application_id', 'project_id'], 'firewall_isps_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_isps');
    }
};
