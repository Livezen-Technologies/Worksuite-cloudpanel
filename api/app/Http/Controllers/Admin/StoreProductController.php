<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Models\StoreProduct;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * STORE-001. Products. `sell_pos` marks a product for the point of sale, which
 * looks products up by SKU or barcode (GET store/pos/lookup).
 */
class StoreProductController extends CrudController
{
    protected string $model = StoreProduct::class;

    protected array $searchable = ['name', 'sku', 'barcode'];

    protected array $sortable = ['name', 'sku', 'price_cents', 'stock', 'created_at', 'updated_at'];

    public function __construct()
    {
        $this->filters = [
            'category_id', 'brand_id', 'status', 'sell_pos', 'sell_online',
            'stock_status' => function (Builder $q, $v) {
                match ($v) {
                    'out' => $q->where('track_stock', true)->where('stock', '<=', 0),
                    'low' => $q->where('track_stock', true)->where('stock', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold'),
                    default => null,
                };
            },
        ];
    }

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $org = app(Tenant::class)->id();
        $unique = fn (string $col) => Rule::unique('store_products', $col)->where('organization_id', $org)->ignore($model?->getKey());

        return [
            'name' => "{$req}|string|max:190",
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $unique('slug')],
            'sku' => [$req, 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-]+$/', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/', $unique('barcode')],
            'category_id' => ['nullable', 'uuid', Rule::exists('store_categories', 'id')->where('organization_id', $org)],
            'brand_id' => ['nullable', 'uuid', Rule::exists('store_brands', 'id')->where('organization_id', $org)],
            'description' => 'nullable|string|max:10000',
            'image_url' => 'nullable|url|max:500',
            'unit' => 'sometimes|string|max:20',
            'price_cents' => "{$req}|integer|min:0|max:100000000000",
            'cost_cents' => 'sometimes|integer|min:0|max:100000000000',
            'currency' => 'sometimes|string|size:3|in:LKR,USD,EUR,GBP,AED,INR',
            'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'track_stock' => 'sometimes|boolean',
            'stock' => 'sometimes|integer|min:-1000000|max:100000000',
            'low_stock_threshold' => 'sometimes|integer|min:0|max:1000000',
            'sell_online' => 'sometimes|boolean',
            'sell_pos' => 'sometimes|boolean',
            'status' => 'sometimes|in:draft,active,archived',
        ];
    }

    protected function prepare(array $data, ?Model $model = null): array
    {
        if (array_key_exists('slug', $data) || ! $model) {
            $data['slug'] = ($data['slug'] ?? null) ?: Str::slug(($data['name'] ?? $model?->name).'-'.($data['sku'] ?? $model?->sku));
        }

        return $data;
    }

    protected function present(Model $model): array
    {
        /** @var StoreProduct $model */
        return $model->toArray() + [
            'category_name' => $model->category?->name,
            'brand_name' => $model->brand?->name,
            'stock_status' => $model->stock_status,
            'margin_cents' => $model->price_cents - $model->cost_cents,
        ];
    }

    /** POS: find one sellable product by exact barcode or SKU. */
    public function posLookup(Request $request)
    {
        $code = trim((string) $request->validate(['code' => 'required|string|max:64'])['code']);
        $product = StoreProduct::query()->where('sell_pos', true)->where('status', 'active')
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->first();
        abort_if(! $product, 404, 'No POS product with that barcode or SKU.');

        return $this->data($this->present($product));
    }

    /** Stock adjustment: a signed delta, so concurrent POS sales never overwrite each other. */
    public function adjustStock(Request $request, string $id)
    {
        $delta = (int) $request->validate(['delta' => 'required|integer|not_in:0|min:-1000000|max:1000000', 'reason' => 'nullable|string|max:190'])['delta'];
        $product = $this->find($id);
        StoreProduct::query()->whereKey($product->getKey())->update(['stock' => DB::raw('stock + '.$delta)]);

        return $this->data($this->present($product->fresh()));
    }

    /** Catalogue headline numbers for the Store overview tiles. */
    public function summary()
    {
        $q = fn () => StoreProduct::query();

        return $this->data([
            'products' => $q()->count(),
            'active' => $q()->where('status', 'active')->count(),
            'pos' => $q()->where('sell_pos', true)->where('status', 'active')->count(),
            'low_stock' => $q()->where('track_stock', true)->where('stock', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold')->count(),
            'out_of_stock' => $q()->where('track_stock', true)->where('stock', '<=', 0)->count(),
            'categories' => \App\Models\StoreCategory::query()->count(),
            'brands' => \App\Models\StoreBrand::query()->count(),
        ]);
    }
}
