<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InvoiceLine extends Model
{
    use LogsActivity;

    protected $fillable = [
        'invoice_id',
        'fee_type',
        'unit',
        'quantity',
        'rate',
        'markup_pct',
        'cost_base',
        'rate_from',
        'rate_to',
        'amount',
        'source_summary',
    ];

    protected function casts(): array
    {
        return [
            'invoice_id' => 'integer',
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'markup_pct' => 'decimal:4',
            'cost_base' => 'decimal:2',
            'rate_from' => 'date',
            'rate_to' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('invoice_line')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return HasMany<InvoiceLineSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(InvoiceLineSource::class)->orderBy('id');
    }
}
