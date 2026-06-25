<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MediaAsset extends Model
{
    public const MODEL_TYPE_STOCK_ITEM = 'stock_item';

    public const MODEL_TYPE_ISSUE = 'issue';

    public const MODEL_TYPE_RETURN_ORDER = 'return_order';

    public const MODEL_TYPE_RETURN_ORDER_LINE = 'return_order_line';

    public const MODEL_TYPE_INBOUND_ORDER = 'inbound_order';

    protected $fillable = [
        'tenant_id',
        'model_type',
        'model_id',
        'type',
        'disk',
        'path',
        'original_url',
        'file_name',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'sort_order',
        'is_primary',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function url(): string
    {
        if ($this->disk === 'public') {
            return Storage::disk('public')->url($this->path);
        }

        return route('media.show', $this);
    }
}
