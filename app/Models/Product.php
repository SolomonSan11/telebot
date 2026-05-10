<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property string $price Decimal string from DB
 */
class Product extends Model
{
    protected $fillable = ['name', 'description', 'price', 'stock', 'active'];

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'active' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }
}
