<?php

namespace App\Models;

use Database\Factories\PackagingMaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackagingMaterial extends Model
{
    /** @use HasFactory<PackagingMaterialFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'length_value',
        'width_value',
        'height_value',
        'dimension_unit',
        'weight_value',
        'weight_unit',
        'cost',
        'currency',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'length_value' => 'decimal:2',
            'width_value' => 'decimal:2',
            'height_value' => 'decimal:2',
            'weight_value' => 'decimal:3',
            'cost' => 'decimal:2',
        ];
    }

    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class, 'default_packaging_material_id');
    }
}
