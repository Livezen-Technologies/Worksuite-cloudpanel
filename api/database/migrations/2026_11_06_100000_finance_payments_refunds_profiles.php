<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance (FIN-1..3): the three things the panel could not record before.
 *
 * Until now an invoice carried its own `status` and nothing else: money that
 * arrived was implied by `paid`, money that went back was a credit note, and a
 * customer's billing address lived nowhere. That is enough to bill with and
 * far too little to account with -- it cannot answer "what did this customer
 * pay, through which gateway, against which invoice", and a refund has no
 * state between asked for and done.
 *
 * - `payments` is every movement of money in, whatever its origin: a gateway
 *   collection, a bank transfer typed in by an administrator, a gateway
 *   webhook. It points at the invoice it settles, when it settles one.
 * - `refund_requests` is the decision trail around money going back. The
 *   credit note (an invoice of kind `credit_note`) stays the accounting
 *   record; this is the request, the approval and the gateway attempt.
 * - `billing_profiles` is who the invoice is addressed to and the tax
 *   identity it is issued under, one per organisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('invoice_id')->nullable()->index();
            $t->uuid('subscription_id')->nullable()->index();
            $t->integer('amount_cents');
            $t->string('currency', 3)->default('EUR');
            // stripe|paypal|fake|manual -- manual is anything taken outside a gateway.
            $t->string('gateway', 30)->default('manual');
            // card|paypal|bank_transfer|cash|cheque|credit
            $t->string('method', 30)->default('bank_transfer');
            // succeeded|pending|failed|disputed|refunded|partially_refunded
            $t->string('status', 30)->default('succeeded')->index();
            $t->integer('refunded_cents')->default(0);
            // The gateway's own id for this charge. Unique per gateway so a webhook
            // delivered twice cannot be recorded twice (UBIL-003).
            $t->string('gateway_reference')->nullable();
            $t->string('failure_reason')->nullable();
            $t->text('notes')->nullable();
            $t->uuid('recorded_by')->nullable();
            $t->timestamp('paid_at')->nullable()->index();
            $t->json('meta')->nullable();
            $t->timestamps();

            $t->unique(['gateway', 'gateway_reference']);
            $t->index(['organization_id', 'paid_at']);
        });

        Schema::create('refund_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('payment_id')->nullable()->index();
            $t->uuid('invoice_id')->nullable()->index();
            $t->uuid('credit_note_id')->nullable();
            $t->integer('amount_cents');
            $t->string('currency', 3)->default('EUR');
            // pending|approved|rejected|processing|refunded|failed
            $t->string('status', 20)->default('pending')->index();
            $t->text('reason');
            $t->text('admin_notes')->nullable();
            $t->string('gateway_reference')->nullable();
            $t->string('failure_reason')->nullable();
            $t->uuid('requested_by')->nullable();
            $t->uuid('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('refunded_at')->nullable();
            $t->timestamps();

            $t->index(['organization_id', 'status']);
        });

        Schema::create('billing_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $t->string('company_name')->nullable();
            $t->string('contact_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('address_line1')->nullable();
            $t->string('address_line2')->nullable();
            $t->string('city')->nullable();
            $t->string('region')->nullable();
            $t->string('postal_code', 30)->nullable();
            $t->string('country', 2)->nullable();
            $t->string('tax_id', 60)->nullable();
            $t->string('vat_number', 60)->nullable();
            $t->boolean('tax_exempt')->default(false);
            $t->string('currency', 3)->default('EUR');
            // Credit the customer holds, in the profile's currency. Negative is owed.
            $t->integer('balance_cents')->default(0);
            $t->text('invoice_notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_profiles');
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('payments');
    }
};
