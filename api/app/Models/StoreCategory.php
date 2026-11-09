<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class StoreCategory extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'store_categories';

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'sort' => 'integer'];

    public function parent()
    {
        return $this->belongsTo(StoreCategory::class, 'parent_id');
    }

    public function products()
    {
        return $this->hasMany(StoreProduct::class, 'category_id');
    }
}
