<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderPasteImportMapping extends Model
{
    protected $fillable = ['tenant_id', 'name', 'mapping', 'data_start_row', 'is_default', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'data_start_row' => 'integer',
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
