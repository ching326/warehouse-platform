<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountImportMapping extends Model
{
    protected $fillable = ['tenant_id', 'name', 'mapping', 'is_default', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
