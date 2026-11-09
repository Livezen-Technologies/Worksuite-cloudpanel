<?php

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CUST-1: the permission matrix is read from the `role_permissions` table, not
 * from config/rbac.php, so a new permission that is only added to the config
 * is invisible until the reference seeder runs again. Adding `customers.manage`
 * without this leaves the Customers section readable and every write refused.
 *
 * The seeder is idempotent and rebuilds the matrix from the config, so running
 * it here keeps a deploy from needing a remembered extra step.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('roles')->count() === 0) {
            return;   // a fresh install seeds this itself afterwards
        }
        (new ReferenceDataSeeder)->run();
    }

    public function down(): void
    {
        // Nothing to undo: dropping the permission is the business of whatever
        // removes it from the config, and the seeder would restore it anyway.
    }
};
