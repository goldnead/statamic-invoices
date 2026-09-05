<?php

namespace Goldnead\Invoices\Models;

use Goldnead\Invoices\Support\TaxZone;
use Goldnead\Invoices\Support\VatIdStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * @property int $brand_id
 * @property string $number
 * @property string $kind
 * @property int|null $payment_id
 * @property int|null $reverses_invoice_id
 * @property Carbon $issued_at
 * @property string $currency
 * @property string|null $buyer_name
 * @property string|null $buyer_email
 * @property string|null $buyer_country
 * @property string|null $buyer_vat_id
 * @property string|null $buyer_address
 * @property string|null $tax_zone
 * @property string|null $buyer_vat_id_status
 * @property Carbon|null $buyer_vat_id_checked_at
 * @property string|null $buyer_vat_id_service
 * @property string|null $buyer_vat_id_reference
 * @property array<string, mixed>|null $seller
 * @property int $net_cent
 * @property int $tax_cent
 * @property int $gross_cent
 * @property string|null $tax_reason
 * @property string|null $tax_note
 * @property array<string, mixed>|null $meta
 * @property Collection<int, InvoiceItem> $items
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
            'buyer_vat_id_checked_at' => 'datetime',
        ];
    }

    /**
     * Which of the three cases this document is, as the enum rather than a string.
     *
     * Null for a row written before the zones existed, and for one whose stored
     * value this version does not know. Neither is defaulted to `de`: an unknown
     * zone is a thing to look at, and "domestic" is the one answer that would hide
     * that by looking ordinary.
     */
    public function zone(): ?TaxZone
    {
        return is_string($this->tax_zone) ? TaxZone::tryFrom($this->tax_zone) : null;
    }

    /** What was known about the buyer's VAT ID when this was written. */
    public function vatIdStatus(): ?VatIdStatus
    {
        return VatIdStatus::tryFromMixed($this->buyer_vat_id_status);
    }

    /**
     * Is somebody still owed an answer about this buyer's VAT ID?
     *
     * Narrower than {@see self::scopeAwaitingVatIdConfirmation()} on purpose, and
     * the two answer different questions. This one is about *this* document: was a
     * confirmation attempted and left hanging? The scope is about the work queue,
     * which also has to contain the rows nobody ever asked about — otherwise they
     * are a class of invoice no report can count.
     */
    public function awaitsVatIdConfirmation(): bool
    {
        return $this->vatIdStatus() === VatIdStatus::Pending;
    }

    /**
     * Every invoice whose buyer VAT ID still owes somebody an answer.
     *
     * One definition, used by the Control Panel screen and by the console command
     * that asks again — because a list that shows rows the command never revisits,
     * or a command that revisits rows the list never shows, is two definitions of
     * "outstanding" that drift apart in silence.
     *
     * Two cases, and the second is the one that is easy to miss.
     *
     * `pending` anywhere: a check was attempted and left hanging. And any invoice in
     * the `eu-b2b` zone whose number was never asked about at all — an older
     * document, a payment that reached the writer past the checkout, or a status a
     * later release wrote and this one cannot read. That zone is the only one where a
     * confirmation carries the tax treatment, which is why it is also the only one
     * where "nobody asked" is outstanding work.
     *
     * Everything else stays off: a confirmed number is done, a domestic or
     * third-country number is deliberately never confirmed, and an invoice without a
     * number has nothing to ask about. A list that also showed those would be a list
     * nobody finishes reading.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingVatIdConfirmation($query)
    {
        return $query
            ->whereNotNull('buyer_vat_id')
            ->where(fn ($q) => $q
                ->where('buyer_vat_id_status', VatIdStatus::Pending->value)
                ->orWhere(fn ($nieGefragt) => $nieGefragt
                    ->where('tax_zone', TaxZone::EuBusiness->value)
                    ->where(fn ($ohneUrteil) => $ohneUrteil
                        ->whereNull('buyer_vat_id_status')
                        ->orWhere('buyer_vat_id_status', VatIdStatus::Unchecked->value))));
    }

    /**
     * Later looks at the same number. Never edits of this row — see booted().
     */
    public function vatIdChecks(): HasMany
    {
        return $this->hasMany(VatIdCheckRecord::class)->latest('checked_at');
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
