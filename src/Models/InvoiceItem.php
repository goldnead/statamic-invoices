<?php

namespace Goldnead\Invoices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an invoice, with its own tax rate.
 *
 * The rate lives here rather than on the invoice because a single order can
 * carry two of them — sheet music at 7% beside a course at 19% — and that is
 * exactly the case a single figure cannot express.
 *
 * @property int $quantity
 * @property int $unit_net_cent
 * @property int $discount_cent
 * @property int $net_cent
 * @property int $tax_rate_bp
 * @property int $tax_cent
 * @property int $gross_cent
 */
class InvoiceItem extends Model
{
    protected $table = 'invoice_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_net_cent' => 'integer',
            'discount_cent' => 'integer',
            'net_cent' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_cent' => 'integer',
            'gross_cent' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The rate as a person reads it: 1900 basis points are 19%. */
    public function ratePercent(): string
    {
        return rtrim(rtrim(number_format($this->tax_rate_bp / 100, 2, ',', '.'), '0'), ',');
    }
}
