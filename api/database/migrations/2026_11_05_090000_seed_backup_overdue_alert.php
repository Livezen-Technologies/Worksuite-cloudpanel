<?php

use Illuminate\Database\Migrations\Migration;

/** Existing organisations get the new default "Backups overdue" alert rule. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (\App\Models\Organization::all() as $org) {
            app(\App\Services\AlertService::class)->seedDefaults($org);
        }
    }

    public function down(): void
    {
        \App\Models\AlertRule::allTenants()->where('metric', 'backup_overdue')->where('is_default', true)->delete();
    }
};
