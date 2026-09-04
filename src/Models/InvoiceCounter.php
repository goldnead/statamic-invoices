<?php

namespace Goldnead\Invoices\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per number series, locked while it is incremented.
 *
 * Not a column on a brand: a series is (brand, prefix, period), and a site that
 * counts per month has twelve of them a year.
 *
 * @property int $brand_id
 * @property string $series
 * @property int $last_number
 */
class InvoiceCounter extends Model
{
    protected $table = 'invoice_counters';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_number' => 'integer'];
    }
}
