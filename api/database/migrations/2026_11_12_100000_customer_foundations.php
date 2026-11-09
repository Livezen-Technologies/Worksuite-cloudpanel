<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CUST-1: the customer account grows the fields the directory has to filter,
 * sort and report on. Account status was a key inside `organizations.settings`,
 * which no index can reach; it becomes a column here and the JSON keeps only
 * the suspension reason and history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $t) {
            $t->string('status', 20)->default('active')->index();      // active|pending|suspended|closed|trial
            $t->string('customer_type', 20)->default('individual');     // individual|business|enterprise|reseller
            $t->uuid('account_manager_id')->nullable()->index();
            $t->string('onboarding_status', 20)->default('none');       // none|in_progress|completed|failed|abandoned
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('last_activity_at')->nullable()->index();
            $t->timestamp('archived_at')->nullable()->index();
        });

        // Carry the existing truth across rather than defaulting everyone to
        // active: closed beats suspended, and a suspended subscription is what
        // the old list read as a suspended customer.
        DB::table('organizations')->update(['registered_at' => DB::raw('created_at')]);

        foreach (DB::table('organizations')->select('id', 'settings')->cursor() as $org) {
            $settings = json_decode($org->settings ?? '{}', true) ?: [];
            $status = ! empty($settings['closed']) ? 'closed' : (! empty($settings['suspension']) ? 'suspended' : null);
            if ($status !== null) {
                DB::table('organizations')->where('id', $org->id)->update(['status' => $status]);
            }
        }

        $suspended = DB::table('subscriptions')->where('status', 'suspended')->pluck('organization_id');
        if ($suspended->isNotEmpty()) {
            DB::table('organizations')->whereIn('id', $suspended)->where('status', 'active')->update(['status' => 'suspended']);
        }

        Schema::table('users', function (Blueprint $t) {
            $t->string('phone', 40)->nullable();
            $t->char('country', 2)->nullable();
            $t->string('locale', 10)->nullable();
            $t->string('timezone', 64)->nullable();
            $t->json('notification_preferences')->nullable();
        });

        // The profile already carries contact_name, email, phone, the address,
        // tax_id and vat_number; only the company's own three fields are missing.
        Schema::table('billing_profiles', function (Blueprint $t) {
            $t->string('industry', 80)->nullable();
            $t->string('website', 190)->nullable();
            $t->string('registration_number', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn([
            'status', 'customer_type', 'account_manager_id', 'onboarding_status',
            'registered_at', 'last_activity_at', 'archived_at',
        ]));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn([
            'phone', 'country', 'locale', 'timezone', 'notification_preferences',
        ]));
        Schema::table('billing_profiles', fn (Blueprint $t) => $t->dropColumn(['industry', 'website', 'registration_number']));
    }
};
