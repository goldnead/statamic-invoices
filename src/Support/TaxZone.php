<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Support;

/**
 * The three cases a business-only seller actually has, and no fourth.
 *
 * Not twenty-seven country rates. A supply to a business abroad is taxed where
 * the buyer sits (§ 3a Abs. 2 UStG), which means the German seller charges
 * nothing in all three cases and the only thing that differs is the sentence on
 * the document. Rates per member state would be dead weight: they belong to a
 * seller with consumers abroad, and this one has none by decision.
 *
 * Which sentence goes with which zone:
 *
 * - {@see self::Domestic}: no VAT under § 19 UStG, with the § 19 note.
 * - {@see self::EuBusiness}: no VAT, both VAT IDs on the document and
 *   "Steuerschuldnerschaft des Leistungsempfängers" (§ 14a Abs. 1 UStG). The
 *   note is prescribed; it is not a courtesy.
 * - {@see self::ThirdCountryBusiness}: no VAT, "Leistung im Inland nicht
 *   steuerbar". Reverse charge is *not* the phrase here — whether the buyer's
 *   country shifts the liability is that country's business, not ours.
 *
 * A consumer has no zone. That is the point of {@see BuyerAdmission}: the case
 * is refused at the checkout rather than given a rate.
 */
enum TaxZone: string
{
    case Domestic = 'de';
    case EuBusiness = 'eu-b2b';
    case ThirdCountryBusiness = 'third-country-b2b';

    /** Does § 14a Abs. 1 UStG want both VAT IDs printed for this zone? */
    public function needsBothVatIds(): bool
    {
        return $this === self::EuBusiness;
    }

    public function label(): string
    {
        return match ($this) {
            self::Domestic => 'Inland',
            self::EuBusiness => 'EU, Unternehmen',
            self::ThirdCountryBusiness => 'Drittland, Unternehmen',
        };
    }
}
