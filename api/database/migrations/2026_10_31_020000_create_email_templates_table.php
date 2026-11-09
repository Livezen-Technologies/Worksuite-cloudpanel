<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** EML: platform-wide overrides of the transactional emails, and per-user opt-outs (EML-5). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('overrides')->nullable();   // subject, preview, heading, body, button_label, button_url, secondary_label, secondary_url
            $t->boolean('enabled')->default(true);
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->json('email_preferences')->nullable(); // {opt_out: [template keys]}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('email_preferences'));
    }
};
