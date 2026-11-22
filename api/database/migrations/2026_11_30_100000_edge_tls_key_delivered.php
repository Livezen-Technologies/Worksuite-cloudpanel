<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * XNODE-3b: the serial whose private key the node has confirmed. The key goes out with
 * fs.write create=true (never snapshotted into the agent's file history), which refuses to
 * overwrite, so it may be sent only once per serial; every later sync skips it. Rows whose
 * terminator already reported success (live_ports set) had their current key delivered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_tls_certs', function (Blueprint $t) {
            $t->string('key_delivered_serial', 40)->nullable();
        });
        DB::table('edge_tls_certs')->where('kind', 'node')->whereNotNull('live_ports')->update(['key_delivered_serial' => DB::raw('serial')]);
    }

    public function down(): void
    {
        Schema::table('edge_tls_certs', function (Blueprint $t) {
            $t->dropColumn('key_delivered_serial');
        });
    }
};
