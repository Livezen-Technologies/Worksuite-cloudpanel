<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class StoreProduct extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'store_products';

    protected $guarded = ['id'];

    protected $casts = [
        'price_cents' => 'integer', 'cost_cents' => 'integer', 'tax_rate' => 'float',
        'track_stock' => 'boolean', 'stock' => 'integer', 'low_stock_threshold' => 'integer',
        'sell_online' => 'boolean', 'sell_pos' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(StoreCategory::class, 'category_id');
    }

    public function brand()
    {
        return $this->belongsTo(StoreBrand::class, 'brand_id');
    }

    public function getStockStatusAttribute(): string
    {
        if (! $this->track_stock) {
            return 'untracked';
        }

        return $this->stock <= 0 ? 'out' : ($this->stock <= $this->low_stock_threshold ? 'low' : 'in');
    }
}
