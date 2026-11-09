<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STORE-001. Product catalogue behind /admin/store: categories (nestable),
 * brands and products. A product can be sold online, at the point of sale, or
 * both; POS lookups use the SKU or barcode. Prices are in minor units.
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
        Schema::create('store_categories', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('parent_id')->nullable()->constrained('store_categories')->nullOnDelete();
            $t->string('name');
            $t->string('slug');
            $t->text('description')->nullable();
            $t->string('image_url', 500)->nullable();
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'slug']);
        });

        Schema::create('store_brands', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('slug');
            $t->string('logo_url', 500)->nullable();
            $t->string('website', 255)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'slug']);
        });

        Schema::create('store_products', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('category_id')->nullable()->constrained('store_categories')->nullOnDelete();
            $t->foreignUuid('brand_id')->nullable()->constrained('store_brands')->nullOnDelete();
            $t->string('name');
            $t->string('slug');
            $t->string('sku', 64);
            $t->string('barcode', 64)->nullable();
            $t->text('description')->nullable();
            $t->string('image_url', 500)->nullable();
            $t->string('unit', 20)->default('pcs');
            $t->unsignedBigInteger('price_cents')->default(0);
            $t->unsignedBigInteger('cost_cents')->default(0);
            $t->string('currency', 3)->default('LKR');
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->boolean('track_stock')->default(true);
            $t->integer('stock')->default(0);
            $t->unsignedInteger('low_stock_threshold')->default(5);
            $t->boolean('sell_online')->default(true);
            $t->boolean('sell_pos')->default(false);
            $t->string('status')->default('active'); // draft|active|archived
            $t->timestamps();
            $t->unique(['organization_id', 'sku']);
            $t->unique(['organization_id', 'slug']);
            $t->index(['organization_id', 'barcode']);
            $t->index(['organization_id', 'sell_pos', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['store_products', 'store_brands', 'store_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
