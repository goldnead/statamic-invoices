<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Models;

use Goldnead\Invoices\Support\VatIdCheck;
use Goldnead\Invoices\Support\VatIdStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A later look at a VAT ID that was still outstanding when the invoice was written.
 *
 * Its own row rather than a column on the invoice, and that follows from the
 * invoice being immutable: the document says what was known on the day it was
 * issued, and it goes on saying that. What a re-check on the twelfth found is a
 * new fact about the same number, not a correction of an old one.
 *
 * Which is also why the answer is not acted on automatically. A number that
 * comes back invalid a week later does not make the invoice wrong — the seller
 * relied on what the service said at the time, and § 6a Abs. 4 UStG protects
 * exactly that reliance. What it makes is a case for a human: dunning the buyer,
 * a credit note plus a corrected invoice, or nothing at all. So this table
 * records, the Control Panel shows, and Adrian decides.
 *
 * @property int $invoice_id
 * @property string $vat_id
 * @property string $status
 * @property Carbon $checked_at
 * @property string|null $service
 * @property string|null $reference
 * @property string|null $failure
 */
class VatIdCheckRecord extends Model
{
    protected $table = 'invoice_vat_id_checks';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function verdict(): ?VatIdStatus
    {
        return VatIdStatus::tryFromMixed($this->status);
    }

    /**
     * Has the answer moved away from what the invoice says?
     *
     * The only question this table is really asked. A still-pending re-check is
     * noise; a `valid` closes the case; an `invalid` is the one that needs a
     * person, and it is the one that would disappear into a green checkmark if
     * "we got an answer" and "we got the answer we hoped for" were one state.
     */
    public function contradicts(Invoice $invoice): bool
    {
        return $this->verdict() === VatIdStatus::Invalid
            && $invoice->vatIdStatus() === VatIdStatus::Pending;
    }

    /** @return array<string, mixed> */
    public static function columnsFrom(VatIdCheck $check): array
    {
        return [
            'vat_id' => $check->vatId,
            'status' => $check->status->value,
            'checked_at' => $check->checkedAt ?? now(),
            'service' => $check->service,
            'reference' => $check->requestId,
            'failure' => $check->failure,
        ];
    }
}
