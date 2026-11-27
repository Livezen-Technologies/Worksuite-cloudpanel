<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Certificate churn: each ingress (the two enterprise edges in particular) orders and holds its
 * own Let's Encrypt certificate for the same domain, so their expiry dates differ by seconds.
 * proxy:sync-certs replaced the active row whenever the reporting ingress's expiry differed from
 * the stored one, which made a new row on every sync. `observations` records what each ingress
 * holds ({server_id: {fingerprint, not_before, not_after, seen_at}}) so one row stands for the
 * certificate set until one of them is actually renewed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $t) {
            $t->json('observations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $t) {
            $t->dropColumn('observations');
        });
    }
};
