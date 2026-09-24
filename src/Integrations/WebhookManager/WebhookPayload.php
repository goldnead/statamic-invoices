<?php

namespace Goldnead\Invoices\Integrations\WebhookManager;

use Closure;
use Goldnead\Invoices\Events\CreditNoteIssued;
use Goldnead\Invoices\Events\InvoiceDelivered;
use Goldnead\Invoices\Events\InvoiceIssued;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a webhook receiver is told about an invoice, and nothing more.
 *
 * A chosen list, never the row. An invoice carries the buyer's postal address,
 * the seller block, the record of the VAT id check with the authority's
 * reference, and `meta`. A bookkeeping zap needs the number, the amounts and
 * who it was for; anything handed to a webhook is handed to a third party for
 * good, and a column added next year must not travel without a decision.
 *
 * The shape is the one every addon of the suite sends:
 *
 *     {
 *       "event": "invoices.issued",
 *       "occurred_at": "2026-09-24T10:12:03+02:00",
 *       "brand": {"id": 2, "handle": "nordlicht"} | null,
 *       "subject_type": "invoice", "subject_id": 17,
 *       "<object>": { ... }
 *     }
 *
 * Money is `*_cent` next to `currency`; times are ISO 8601 or null.
 *
 * Names no class of the webhook manager, so it loads on every install.
 */
final class WebhookPayload
{
    /**
     * Moment => event class. The handle is `invoices.<moment>`.
     *
     * @var array<string, class-string>
     */
    public const MOMENTS = [
        'issued' => InvoiceIssued::class,
        'credit_note_issued' => CreditNoteIssued::class,
        'delivered' => InvoiceDelivered::class,
    ];

    public const PREFIX = 'invoices.';

    /** @var array<int, array{id: int, handle: string}|null> */
    private static array $brands = [];

    /**
     * @return array<string, mixed>
     */
    public static function build(string $moment, object $event, ?\DateTimeInterface $at = null): array
    {
        $subject = self::subjectOf($event);

        return array_merge([
            'event' => self::PREFIX.$moment,
            'occurred_at' => self::date($at ?? now()),
            'brand' => self::brand(self::brandIdOf($event)),
            'subject_type' => $subject === null ? null : 'invoice',
            'subject_id' => $subject?->getKey() === null ? null : (int) $subject->getKey(),
        ], self::body($event));
    }

    /** The document the moment is about: the credit note for a credit note. */
    public static function subjectOf(object $event): ?Invoice
    {
        return match (true) {
            $event instanceof CreditNoteIssued => $event->creditNote,
            isset($event->invoice) && $event->invoice instanceof Invoice => $event->invoice,
            default => null,
        };
    }

    public static function referenceOf(object $event): ?string
    {
        $key = self::subjectOf($event)?->getKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * The brand stamped on the document. An invoice is written in a payment
     * webhook or a queue worker, where no brand is current, so the row is
     * asked, not the request.
     */
    public static function brandIdOf(object $event): ?int
    {
        $brand = self::subjectOf($event)?->brand_id;

        if (is_numeric($brand) && (int) $brand > 0) {
            return (int) $brand;
        }

        try {
            if (! app()->bound('brand-context')) {
                return null;
            }

            $manager = app('brand-context');

            return $manager->hasCurrent() ? (int) $manager->currentId() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{id: int, handle: string}|null
     */
    public static function brand(?int $id): ?array
    {
        $model = 'Goldnead\\BrandContext\\Models\\Brand';

        if ($id === null || $id < 1 || ! class_exists($model)) {
            return null;
        }

        if (array_key_exists($id, self::$brands)) {
            return self::$brands[$id];
        }

        try {
            $brand = $model::query()->find($id);
            $answer = $brand === null ? null : ['id' => (int) $brand->getKey(), 'handle' => (string) $brand->getAttribute('handle')];
        } catch (Throwable) {
            $answer = null;
        }

        return self::$brands[$id] = $answer;
    }

    public static function forgetBrands(): void
    {
        self::$brands = [];
    }

    /**
     * Run the dispatch as the document's brand, restoring whatever was current.
     * A brand that cannot be set (row deleted) is logged and the dispatch runs
     * without it: the manager then finds no brand's hooks, which is closed.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function runFor(?int $brand, Closure $callback): mixed
    {
        if (! $brand || ! app()->bound('brand-context')) {
            return $callback();
        }

        $ran = false;

        try {
            return app('brand-context')->runFor($brand, function () use ($callback, &$ran) {
                $ran = true;

                return $callback();
            });
        } catch (Throwable $e) {
            if ($ran) {
                throw $e;
            }

            Log::warning('statamic-invoices: the brand of this invoice could not be set for the webhook; it ran without it.', [
                'brand_id' => $brand,
                'exception' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(object $event): array
    {
        return match (true) {
            $event instanceof CreditNoteIssued => [
                'credit_note' => self::invoice($event->creditNote),
                'reverses' => [
                    'id' => (int) $event->reverses->getKey(),
                    'number' => $event->reverses->number,
                ],
            ],
            $event instanceof InvoiceDelivered => [
                'invoice' => self::invoice($event->invoice),
                'to' => $event->to,
            ],
            $event instanceof InvoiceIssued => [
                'invoice' => self::invoice($event->invoice),
            ],
            default => [],
        };
    }

    /**
     * A document, as a receiver needs it.
     *
     * Not here: the postal address, the seller block, the VAT id check record
     * (service, status, the authority's reference), `meta`.
     *
     * @return array<string, mixed>
     */
    public static function invoice(Invoice $invoice): array
    {
        return [
            'id' => (int) $invoice->getKey(),
            'number' => $invoice->number,
            'kind' => $invoice->kind,
            'payment_id' => $invoice->payment_id === null ? null : (int) $invoice->payment_id,
            'reverses_invoice_id' => $invoice->reverses_invoice_id === null ? null : (int) $invoice->reverses_invoice_id,
            'issued_at' => self::date($invoice->issued_at),
            'currency' => $invoice->currency,
            'net_cent' => (int) $invoice->net_cent,
            'tax_cent' => (int) $invoice->tax_cent,
            'gross_cent' => (int) $invoice->gross_cent,
            'tax_zone' => $invoice->tax_zone,
            'buyer_name' => $invoice->buyer_name,
            'buyer_email' => $invoice->buyer_email,
            'buyer_country' => $invoice->buyer_country,
            'buyer_vat_id' => $invoice->buyer_vat_id,
            'items' => self::items($invoice),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(Invoice $invoice): array
    {
        try {
            return $invoice->items
                ->sortBy('id')
                ->map(fn (InvoiceItem $item): array => [
                    'product' => $item->product,
                    'name' => $item->name,
                    'quantity' => (int) $item->quantity,
                    'unit_net_cent' => (int) $item->unit_net_cent,
                    'discount_cent' => (int) $item->discount_cent,
                    'net_cent' => (int) $item->net_cent,
                    'tax_rate_bp' => (int) $item->tax_rate_bp,
                    'tax_cent' => (int) $item->tax_cent,
                    'gross_cent' => (int) $item->gross_cent,
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public static function date(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(\DATE_ATOM) : null;
    }
}
