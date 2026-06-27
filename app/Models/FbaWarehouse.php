<?php

namespace App\Models;

use Database\Factories\FbaWarehouseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FbaWarehouse extends Model
{
    /** @use HasFactory<FbaWarehouseFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'country_code',
        'code',
        'name',
        'postal_code',
        'state',
        'city',
        'address_line1',
        'address_line2',
        'phone',
        'status',
        'note',
    ];
}
