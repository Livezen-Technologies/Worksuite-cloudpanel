<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DNS-008: the zone's DNSSEC state and the DS records to give the registrar. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_zones', function (Blueprint $t) {
            $t->string('dnssec_status')->default('disabled');   // disabled | pending | active
            $t->json('ds_records')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dns_zones', function (Blueprint $t) {
            $t->dropColumn(['dnssec_status', 'ds_records']);
        });
    }
};
