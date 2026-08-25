<?php

namespace Goldnead\Invoices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An invoice. It does not change.
 *
 * That is not a convention here, it is enforced: `saving` refuses any update to
 * a row that already exists. German law requires an invoice to be immutable
 * once issued, and a correction is a **second document** — a credit note that
 * reverses the first — never an edit. A model that quietly allowed
 * `$invoice->update(...)` would make the whole series worthless as evidence,
 * and nothing about the row would show it had happened.
 *
 * Everything about the buyer and the seller is frozen as text. Not a reference
 * to a customer record: an invoice that changes when somebody edits their
 * profile is not an invoice.
 *
 * @property string $number
 * @property Carbon $issued_at
 * @property int $net_cent
 * @property int $tax_cent
 * @property int $gross_cent
 */
class Invoice extends Model
{
    /** A document that charges. */
    public const KIND_INVOICE = 'invoice';

    /** The one that reverses it. */
    public const KIND_CREDIT_NOTE = 'credit_note';

    protected $table = 'invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'seller' => 'array',
            'meta' => 'array',
            'net_cent' => 'integer',
            'tax_cent' => 'integer',
            'gross_cent' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            // No exceptions, not even a back-link to the credit note: that
            // relation is readable from the other side, and one permitted
            // column is how a rule becomes a habit of adding columns.
            throw new \RuntimeException(
                'An invoice cannot be changed once it exists. A correction is a credit note '
                .'plus a new invoice, never an edit — that is what makes the series evidence.'
            );
        });

        static::deleting(function () {
            throw new \RuntimeException(
                'An invoice cannot be deleted. A number that vanishes is a gap in the series, and '
                .'a gap is a question at the next audit.'
            );
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /** The invoice this one reverses, if it is a credit note. */
    public function reverses()
    {
        return $this->belongsTo(self::class, 'reverses_invoice_id');
    }

    public function isCreditNote(): bool
    {
        return $this->reverses_invoice_id !== null;
    }
}
