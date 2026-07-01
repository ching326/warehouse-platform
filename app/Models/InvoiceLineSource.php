<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLineSource extends Model
{
    protected $fillable = [
        'invoice_line_id',
        'source_type',
        'source_id',
        'warehouse_id',
        'source_date',
        'quantity',
        'amount_basis',
    ];

    protected function casts(): array
    {
        return [
            'invoice_line_id' => 'integer',
            'source_id' => 'integer',
            'warehouse_id' => 'integer',
            'source_date' => 'date',
            'quantity' => 'decimal:4',
            'amount_basis' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<InvoiceLine, $this>
     */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
