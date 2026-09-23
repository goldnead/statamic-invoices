<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Support;

/**
 * The standard VAT rate of every EU member state, with the date it was read.
 *
 * A data source, not a rule. {@see TaxRules} only reaches for it when an
 * installation has switched it on (`tax.oss.shipped_rates`) *and* destination
 * taxation applies (`tax.oss.destination_taxation`), i.e. for a consumer in
 * another member state once the seller is past the 10.000 € threshold
 * (§ 3a Abs. 5 Satz 3, § 3c Abs. 4 UStG). Everything else keeps answering as
 * before, which is the promise to installations that never asked for this.
 *
 * **Standard rates only.** Reduced rates differ per country *and* per kind of
 * supply (e-books, sheet music, courses are each treated differently in each
 * member state), and a reduced rate applied to the wrong kind of supply looks
 * exactly like a right one. A product in any class other than the configured
 * `shipped_rates_class` therefore still comes back undetermined unless the
 * operator writes a zone for it.
 *
 * **The table ages.** Member states change their rates, usually on 1 January or
 * 1 July, with a few months' notice. Every result that used this table carries
 * {@see self::AS_OF} in its notes, so an invoice written from a stale table
 * says which table it was written from. A zone the operator writes for a
 * country always beats this table, which is the way to correct a rate before
 * the addon catches up.
 *
 * Basis points, as everywhere in this addon: 1900 is 19 %, 2550 is 25,5 %.
 *
 * This is the addon's reading of published tables, not tax advice. Check the
 * rate of every country you actually sell into against the primary source
 * before the first invoice goes out.
 */
final class EuStandardRates
{
    /**
     * The day the rates below were last checked against the sources.
     */
    public const AS_OF = '2026-02-02';

    /**
     * Where the rates come from. The first is the primary source, filled in by
     * the member states themselves; the other two are the tables the values
     * were read from and cross-checked against each other.
     *
     * Changes the tables name since 2024: Finland 24 → 25,5 % (1.9.2024),
     * Slovakia 20 → 23 % (1.1.2025), Estonia 22 → 24 % (1.7.2025),
     * Romania 19 → 21 % (1.8.2025).
     *
     * @var list<string>
     */
    public const SOURCES = [
        'European Commission, Taxes in Europe Database (TEDB): https://ec.europa.eu/taxation_customs/tedb/',
        'Tax Foundation, "2026 VAT Rates in Europe", as of January 2026: https://taxfoundation.org/data/all/eu/value-added-tax-vat-rates-europe/',
        'Fiscalead, "VAT rates in Europe in 2026", 2 February 2026: https://www.fiscalead.com/en/vat-rates-in-europe-in-2026-full-review-by-country/',
    ];

    /**
     * Standard rate per member state, ISO 3166-1 alpha-2 (Greece is GR here,
     * EL only in VAT IDs), in basis points.
     *
     * @var array<string, int>
     */
    public const RATES = [
        'AT' => 2000,
        'BE' => 2100,
        'BG' => 2000,
        'CY' => 1900,
        'CZ' => 2100,
        'DE' => 1900,
        'DK' => 2500,
        'EE' => 2400,
        'ES' => 2100,
        'FI' => 2550,
        'FR' => 2000,
        'GR' => 2400,
        'HR' => 2500,
        'HU' => 2700,
        'IE' => 2300,
        'IT' => 2200,
        'LT' => 2100,
        'LU' => 1700,
        'LV' => 2100,
        'MT' => 1800,
        'NL' => 2100,
        'PL' => 2300,
        'PT' => 2300,
        'RO' => 2100,
        'SE' => 2500,
        'SI' => 2200,
        'SK' => 2300,
    ];

    /** The standard rate of one member state, or null for anything else. */
    public static function for(string $country): ?int
    {
        return self::RATES[strtoupper($country)] ?? null;
    }

    /**
     * The zone {@see TaxRules} would have found had the operator written it:
     * one country, one rate, under the tax class the table stands for.
     *
     * @return array{0: string, 1: array{countries: list<string>, rates: array<string, int>, shipped: true}}|null
     */
    public static function zoneFor(string $country, string $taxClass): ?array
    {
        $country = strtoupper($country);
        $rate = self::for($country);

        if ($rate === null) {
            return null;
        }

        return ['eu-'.strtolower($country), [
            'countries' => [$country],
            'rates' => [$taxClass => $rate],
            'shipped' => true,
        ]];
    }
}
