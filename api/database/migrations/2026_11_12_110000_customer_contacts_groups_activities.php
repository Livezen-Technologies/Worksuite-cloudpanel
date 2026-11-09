<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CUST-2: contacts, customer groups and the customer activity timeline.
 *
 * Groups are operator configuration, so they are platform-wide and carry no
 * organisation column; membership is the tenant-stamped join. Activities are
 * the customer's readable history and are safe to prune -- they are not the
 * tamper-evident `audit_logs` chain, which records staff actions for
 * compliance and must never be thinned.
 */
return new class extends Migration
{
    private function tenant(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
    }

    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('name', 190);
            $t->string('email', 190);
            $t->string('phone', 40)->nullable();
            $t->string('role', 20);                 // owner|admin|billing|technical|abuse
            $t->boolean('is_primary')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'email', 'role']);
            $t->index(['organization_id', 'role']);
        });

        Schema::create('customer_groups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 80);
            $t->string('slug', 80)->unique();
            $t->string('kind', 10)->default('static');   // static|dynamic
            $t->json('rules')->nullable();
            $t->unsignedTinyInteger('discount_percent')->default(0);
            $t->json('plan_overrides')->nullable();
            $t->string('colour', 16)->nullable();
            $t->text('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->timestamps();
        });

        Schema::create('customer_group_members', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $t->string('source', 10)->default('manual');  // manual|rule
            $t->uuid('added_by')->nullable();
            $t->timestamps();
            $t->unique(['customer_group_id', 'organization_id']);
        });

        Schema::create('customer_activities', function (Blueprint $t) {
            $this->tenant($t);
            $t->uuid('user_id')->nullable();     // who it happened to
            $t->uuid('actor_id')->nullable();    // who did it, when that is someone else
            $t->string('kind', 40);
            $t->string('subject_type', 40)->nullable();
            $t->uuid('subject_id')->nullable();
            $t->string('summary', 255);
            $t->json('meta')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index(['organization_id', 'occurred_at']);
            $t->index(['kind', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_activities');
        Schema::dropIfExists('customer_group_members');
        Schema::dropIfExists('customer_groups');
        Schema::dropIfExists('contacts');
    }
};
