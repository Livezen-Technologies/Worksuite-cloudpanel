<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class StoreBrand extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'store_brands';

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean'];

    public function products()
    {
        return $this->hasMany(StoreProduct::class, 'brand_id');
    }
}
