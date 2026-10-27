<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Real backups (BAK-002..005, UBAK-002), invoice PDFs + Stripe (UBIL-003/004), Traefik ACME (DOM-002). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_policies', function (Blueprint $t) {
            $t->string('check_subset')->default('5%');
            $t->string('restore_test_schedule')->nullable()->default('0 4 * * 0');
            $t->timestamp('last_restore_test_at')->nullable();
            $t->string('last_restore_test_status')->nullable();
        });
        Schema::table('backup_jobs', function (Blueprint $t) {
            $t->string('kind')->default('backup'); // backup|restore_test|export
        });
        Schema::table('backup_artifacts', function (Blueprint $t) {
            $t->uuid('backup_policy_id')->nullable()->index();
            $t->string('dump_engine')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->text('verify_message')->nullable();
            $t->timestamp('restore_tested_at')->nullable();
            $t->string('restore_test_status')->nullable(); // running|passed|failed
            $t->text('restore_test_message')->nullable();
            $t->string('export_status')->nullable(); // exporting|ready|failed
            $t->string('export_disk')->nullable(); // local|s3
            $t->string('export_path')->nullable();
            $t->bigInteger('export_bytes')->nullable();
            $t->string('export_sha256', 64)->nullable();
            $t->uuid('export_job_id')->nullable();
            $t->timestamp('exported_at')->nullable();
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->json('line_items')->nullable();
            $t->integer('subtotal_cents')->default(0);
            $t->integer('tax_cents')->default(0);
            $t->decimal('tax_rate', 6, 4)->default(0);
            $t->string('pdf_path')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('paid_at')->nullable();
        });
        Schema::table('certificates', function (Blueprint $t) {
            $t->string('resolver')->nullable();
            $t->timestamp('checked_at')->nullable();
        });
        Schema::table('domains', function (Blueprint $t) {
            $t->string('dns_provider')->nullable(); // cloudflare|route53|digitalocean|... (DNS-01)
            $t->uuid('dns_secret_id')->nullable();
        });
        Schema::create('payment_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('provider');
            $t->string('event_id')->unique();
            $t->string('type');
            $t->uuid('organization_id')->nullable()->index();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::table('domains', fn (Blueprint $t) => $t->dropColumn(['dns_provider', 'dns_secret_id']));
        Schema::table('certificates', fn (Blueprint $t) => $t->dropColumn(['resolver', 'checked_at']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['line_items', 'subtotal_cents', 'tax_cents', 'tax_rate', 'pdf_path', 'due_at', 'paid_at']));
        Schema::table('backup_artifacts', fn (Blueprint $t) => $t->dropColumn(['backup_policy_id', 'dump_engine', 'verified_at', 'verify_message', 'restore_tested_at', 'restore_test_status', 'restore_test_message', 'export_status', 'export_disk', 'export_path', 'export_bytes', 'export_sha256', 'export_job_id', 'exported_at']));
        Schema::table('backup_jobs', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::table('backup_policies', fn (Blueprint $t) => $t->dropColumn(['check_subset', 'restore_test_schedule', 'last_restore_test_at', 'last_restore_test_status']));
    }
};
