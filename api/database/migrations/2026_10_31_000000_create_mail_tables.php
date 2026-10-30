<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mail hosting (MAIL-001..005): a mail stack per server, its domains, mailboxes and aliases. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_servers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('server_id')->unique();
            $t->string('hostname');                      // mail.example.com: MX target and HELO name
            $t->string('status')->default('installing'); // installing, running, failed
            $t->text('error')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('mail_domains', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('mail_server_id')->constrained('mail_servers')->cascadeOnDelete();
            $t->string('name')->unique();
            $t->string('dkim_selector')->default('mail');
            $t->text('dkim_private');                    // encrypted PEM
            $t->text('dkim_public');                     // base64 DER for the TXT record
            $t->string('catch_all')->nullable();         // address that gets mail for unknown local parts
            $t->json('dns')->nullable();                 // last check: [{type, name, value, status}]
            $t->timestamp('dns_checked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('mailboxes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('mail_domain_id')->constrained('mail_domains')->cascadeOnDelete();
            $t->string('local_part');
            $t->text('password_hash');                   // {SHA512-CRYPT}$6$…
            $t->unsignedInteger('quota_mb')->default(1024);
            $t->boolean('enabled')->default(true);
            $t->timestamps();
            $t->unique(['mail_domain_id', 'local_part']);
        });
        Schema::create('mail_aliases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('mail_domain_id')->constrained('mail_domains')->cascadeOnDelete();
            $t->string('local_part');
            $t->json('destinations');
            $t->timestamps();
            $t->unique(['mail_domain_id', 'local_part']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_aliases');
        Schema::dropIfExists('mailboxes');
        Schema::dropIfExists('mail_domains');
        Schema::dropIfExists('mail_servers');
    }
};
