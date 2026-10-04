<?php

namespace Goldnead\Invoices\Models;

use Goldnead\Invoices\Delivery\InvoiceDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What became of an invoice's delivery: sent, held back, or in flight.
 *
 * Its own table because the invoice row is immutable. The unique
 * `invoice_id` is the guarantee of one mail per invoice; see
 * {@see InvoiceDelivery::send()}.
 *
 * @property int $invoice_id
 * @property string $status
 * @property string|null $recipient
 * @property string|null $subject
 * @property string|null $reason
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $updated_at
 */
class DeliveryRecord extends Model
{
    /** Claimed, the mail is being built or handed over. */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    /** Not sent on purpose, see `reason`. Stays until a person decides. */
    public const STATUS_HELD = 'held';

    /** The last send failed (renderer, mail server); `invoices:retry` tries again. */
    public const STATUS_FAILED = 'failed';

    /** Out of attempts. Stays until a person decides: `invoices:release`. */
    public const STATUS_FAILED_FINAL = 'failed_final';

    public const LOG_FAILED_FINAL = 'invoices: delivery failed for good, out of attempts';

    protected $table = 'invoice_deliveries';

    protected $guarded = [];

    /**
     * Whether a `failed` row has waited long enough for its next attempt:
     * `delivery.retry.after_minutes`, doubled for every attempt already made.
     * The one rule behind `invoices:retry` and a send that arrives by itself
     * (the event firing again).
     */
    public function isDueForRetry(): bool
    {
        if ($this->status !== self::STATUS_FAILED || $this->updated_at === null) {
            return false;
        }

        $after = max(1, (int) config('invoices.delivery.retry.after_minutes', 10));

        return $this->updated_at->lte(Carbon::now()->subMinutes($after * (2 ** max(0, $this->attempts - 1))));
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
