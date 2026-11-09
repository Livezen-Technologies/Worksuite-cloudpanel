<?php

namespace Tests\Feature;

use Tests\TestCase;

/** STORE-001: product catalogue, categories, brands and POS lookup. */
class StoreCatalogTest extends TestCase
{
    public function test_catalogue_crud_pos_lookup_and_stock(): void
    {
        [, , $token] = $this->org();
        $api = fn () => $this->api($token);

        $cat = $api()->postJson('/api/v1/store/categories', ['name' => 'Soft Drinks'])->assertCreated()->json('data');
        $this->assertSame('soft-drinks', $cat['slug']);
        $brand = $api()->postJson('/api/v1/store/brands', ['name' => 'Elephant House'])->assertCreated()->json('data');

        $p = $api()->postJson('/api/v1/store/products', [
            'name' => 'Cream Soda 400ml', 'sku' => 'CS-400', 'barcode' => '4792024012345',
            'category_id' => $cat['id'], 'brand_id' => $brand['id'],
            'price_cents' => 15000, 'cost_cents' => 11000, 'stock' => 3, 'sell_pos' => true,
        ])->assertCreated()->json('data');
        $this->assertSame('Soft Drinks', $p['category_name']);
        $this->assertSame('low', $p['stock_status']);
        $this->assertSame(4000, $p['margin_cents']);

        $api()->postJson('/api/v1/store/products', ['name' => 'Dup', 'sku' => 'CS-400', 'price_cents' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['sku']]]);

        $api()->getJson('/api/v1/store/pos/lookup?code=4792024012345')->assertOk()->assertJsonPath('data.sku', 'CS-400');
        $api()->getJson('/api/v1/store/pos/lookup?code=nope')->assertNotFound();

        $api()->postJson("/api/v1/store/products/{$p['id']}/stock", ['delta' => -3])->assertOk()
            ->assertJsonPath('data.stock', 0)->assertJsonPath('data.stock_status', 'out');

        $api()->getJson('/api/v1/store/products?stock_status=out')->assertOk()->assertJsonPath('data.0.id', $p['id']);
        $api()->getJson('/api/v1/store/summary')->assertOk()->assertJsonPath('data.pos', 1)->assertJsonPath('data.out_of_stock', 1);
        $api()->getJson('/api/v1/store/categories')->assertOk()->assertJsonPath('data.0.products_count', 1);

        $api()->patchJson("/api/v1/store/categories/{$cat['id']}", ['parent_id' => $cat['id']])->assertStatus(422);
        $api()->deleteJson("/api/v1/store/brands/{$brand['id']}")->assertNoContent();
        $api()->getJson("/api/v1/store/products/{$p['id']}")->assertOk()->assertJsonPath('data.brand_id', null);
    }

    public function test_another_organisation_cannot_see_products(): void
    {
        [, , $a] = $this->org();
        $p = $this->api($a)->postJson('/api/v1/store/products', ['name' => 'X', 'sku' => 'X1', 'price_cents' => 1])->assertCreated()->json('data');
        [, , $b] = $this->org();
        $this->api($b)->getJson("/api/v1/store/products/{$p['id']}")->assertNotFound();
    }
}
