<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Export;

use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\Invoices\Support\TaxResult;
use Goldnead\Invoices\Support\TaxRules;
use Goldnead\Invoices\Support\TaxZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * The documents of a period, and each line's tax treatment, read off the rows.
 *
 * One reading for all three exports, so the CSV, the ZIP and the report can
 * never disagree about which documents belong to August.
 *
 * **Lazily, two hundred at a time.** A year of a busy shop is tens of
 * thousands of rows, and a `get()` would hold every one of them and their
 * lines in memory at once. By id, not by offset: the table only ever grows,
 * and an offset walk over a table that grows while it is walked skips rows.
 *
 * **Nothing is recomputed.** The rate, the amounts, the mechanism and the
 * place of supply are what the line says. The one exception is a line written
 * before 2.2, which has no mechanism and no place; for those {@see self::treatment()}
 * derives both from what the document does say, and reports that it did.
 */
final class Documents
{
    /**
     * @return Builder<Invoice>
     */
    public static function query(Period $period, ?int $brandId = null): Builder
    {
        return Invoice::query()
            ->whereBetween('issued_at', [$period->from, $period->to])
            ->when($brandId !== null, fn (Builder $q) => $q->where('brand_id', $brandId));
    }

    /**
     * @return LazyCollection<int, Invoice>
     */
    public static function lazy(Period $period, ?int $brandId = null): LazyCollection
    {
        return self::query($period, $brandId)->with('items')->lazyById(200);
    }

    /** Credit notes count against the period, with a minus. */
    public static function sign(Invoice $invoice): int
    {
        return $invoice->kind === Invoice::KIND_CREDIT_NOTE || $invoice->reverses_invoice_id !== null ? -1 : 1;
    }

    /**
     * Mechanism and place of supply of one line, and whether they were derived.
     *
     * Derivation for a line from before the columns, in this order:
     *
     * - the document's zone: `eu-b2b` is reverse charge in the buyer's country,
     *   `third-country-b2b` is outside the scope of German VAT;
     * - a rate above zero is ordinary tax in the seller's country. That is the
     *   honest reading for the addon as it shipped until 2.2: only the German
     *   zone came filled in, and an operator who had written foreign zones and
     *   switched destination taxation on will see those lines under the wrong
     *   country. The report counts derived lines so this cannot pass unseen;
     * - zero with the § 19 sentence (or the configured one) is the small
     *   business scheme at home, § 19a in the buyer's country;
     * - any other zero is an exemption in the seller's country.
     *
     * @return array{mechanism: string, country: string|null, derived: bool}
     */
    public static function treatment(Invoice $invoice, InvoiceItem $line): array
    {
        if (is_string($line->tax_mechanism) && $line->tax_mechanism !== '') {
            return [
                'mechanism' => $line->tax_mechanism,
                'country' => $line->place_of_supply,
                'derived' => false,
            ];
        }

        $merchant = strtoupper((string) config('invoices.tax.merchant_country', 'DE'));
        $buyer = is_string($invoice->buyer_country) && $invoice->buyer_country !== ''
            ? strtoupper($invoice->buyer_country)
            : null;
        $reason = (string) $invoice->tax_reason;

        [$mechanism, $country] = match (true) {
            $invoice->tax_zone === TaxZone::EuBusiness->value => [TaxResult::MECHANISM_REVERSE_CHARGE, $buyer],
            $invoice->tax_zone === TaxZone::ThirdCountryBusiness->value => [TaxResult::MECHANISM_OUTSIDE_SCOPE, $buyer],
            (int) $line->tax_rate_bp > 0 => [TaxResult::MECHANISM_STANDARD, $merchant],
            self::mentions($reason, 'small_business_eu', '§ 19a') => [TaxResult::MECHANISM_SMALL_BUSINESS, $buyer ?? $merchant],
            self::mentions($reason, 'small_business', '§ 19 ') => [TaxResult::MECHANISM_SMALL_BUSINESS, $merchant],
            $buyer !== null && $buyer !== $merchant && ! (new TaxRules)->isEuMemberState($buyer) => [TaxResult::MECHANISM_OUTSIDE_SCOPE, $buyer],
            default => [TaxResult::MECHANISM_EXEMPT, $merchant],
        };

        return ['mechanism' => $mechanism, 'country' => $country, 'derived' => true];
    }

    private static function mentions(string $reason, string $textKey, string $marker): bool
    {
        $configured = config('invoices.tax.texts.'.$textKey);

        if (is_string($configured) && trim($configured) !== '' && str_contains($reason, trim($configured))) {
            return true;
        }

        return str_contains($reason.' ', $marker);
    }
}
