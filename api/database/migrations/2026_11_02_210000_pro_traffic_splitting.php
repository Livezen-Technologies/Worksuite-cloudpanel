<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** USCL-3: traffic splitting (canary releases) is part of the Pro package. Other limits are left as they are. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('plans')->where('code', 'pro')->get() as $plan) {
            $limits = json_decode((string) $plan->limits, true) ?: [];
            if (! array_key_exists('traffic_splitting', $limits)) {
                $limits['traffic_splitting'] = 1;
                DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->where('code', 'pro')->get() as $plan) {
            $limits = json_decode((string) $plan->limits, true) ?: [];
            unset($limits['traffic_splitting']);
            DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
        }
    }
};
