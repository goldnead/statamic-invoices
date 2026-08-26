<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Support;

use Goldnead\Invoices\Exceptions\PriceBasisUndecided;

/**
 * What TaxRules decided for one invoice line, and why.
 *
 * Immutable, because the invoice is. What comes out of here is stored on the invoice
 * through `toArray()` and read back later rather than recalculated: a rate change or an
 * edited config must not reach backwards into a document that has already gone out.
 *
 * Three states:
 *
 *  - **determined and taxable** — `rateBasisPoints > 0`
 *  - **determined at zero** — `rateBasisPoints === 0`, with a reason (exemption, reverse
 *    charge, export, § 19). German law wants that reason printed on the invoice.
 *  - **undetermined** — `rateBasisPoints === null`. There is no rule for this case. Then
 *    `reason` is a diagnostic for the operator and **not invoice text**, and `code` says
 *    machine-readably what is missing. `requireRateBasisPoints()` throws, so an
 *    undetermined result cannot quietly print as 0 %.
 *
 * Why `null` instead of an exception: an invoice has several lines, and the caller should
 * be able to collect every unresolved one and show them together rather than stop at the
 * first. And `null` is distinguishable from a genuine zero-rated case, where `0` would not be.
 */
final class TaxResult
{
    /** Ordinary tax, at the standard or reduced rate of the place of supply. */
    public const MECHANISM_STANDARD = 'standard';

    /** The supply itself is exempt, e.g. § 4 Nr. 20 Buchst. a UStG. */
    public const MECHANISM_EXEMPT = 'exempt';

    /** Reverse charge: a service to a business in another member state. */
    public const MECHANISM_REVERSE_CHARGE = 'reverse_charge';

    /** Intra-community supply: goods to a business in another member state. */
    public const MECHANISM_INTRA_COMMUNITY_SUPPLY = 'intra_community_supply';

    /** Export: goods to a third country. */
    public const MECHANISM_EXPORT = 'export';

    /** Not taxable here — the place of supply is outside Germany. */
    public const MECHANISM_OUTSIDE_SCOPE = 'outside_scope';

    /** Small business scheme, § 19 UStG. Suspends everything else. */
    public const MECHANISM_SMALL_BUSINESS = 'small_business';

    /** No rule found. No rate, and no assumption either. */
    public const MECHANISM_UNDETERMINED = 'undetermined';

    /**
     * @param  int|null  $rateBasisPoints  1900 = 19 %, 0 = zero-rated, null = undetermined
     * @param  string  $reason  German invoice text — a diagnostic when undetermined
     * @param  string|null  $legalBasis  the provision, e.g. "§ 19 UStG"
     * @param  string|null  $code  machine-readable cause, set only when undetermined
     * @param  string|null  $buyerCountry  the resolved country, after any configured assumption
     * @param  array<int, string>  $notes  hints for the operator, never for the invoice
     */
    private function __construct(
        public readonly ?int $rateBasisPoints,
        public readonly string $reason,
        public readonly bool $reverseCharge,
        public readonly string $mechanism,
        public readonly ?string $legalBasis = null,
        public readonly ?string $code = null,
        public readonly ?string $productHandle = null,
        public readonly ?string $productClass = null,
        public readonly ?string $zone = null,
        public readonly ?string $buyerCountry = null,
        public readonly ?string $placeOfSupplyCountry = null,
        public readonly bool $isDigital = false,
        /**
         * Whether catalogue prices already contain tax — or `null` for "nobody
         * has said". Nullable on purpose: the two answers produce different,
         * equally consistent invoices for the same payment, so the absence of
         * an answer is a state of its own and not a synonym for `false`.
         */
        public readonly ?bool $pricesIncludeTax = false,
        public readonly array $notes = [],
    ) {}

    /**
     * @param  array<int, string>  $notes
     */
    public static function taxed(
        int $rateBasisPoints,
        string $reason,
        ?string $legalBasis = null,
        ?string $productHandle = null,
        ?string $productClass = null,
        ?string $zone = null,
        ?string $buyerCountry = null,
        ?string $placeOfSupplyCountry = null,
        bool $isDigital = false,
        ?bool $pricesIncludeTax = false,
        array $notes = [],
    ): self {
        return new self(
            rateBasisPoints: $rateBasisPoints,
            reason: $reason,
            reverseCharge: false,
            mechanism: self::MECHANISM_STANDARD,
            legalBasis: $legalBasis,
            productHandle: $productHandle,
            productClass: $productClass,
            zone: $zone,
            buyerCountry: $buyerCountry,
            placeOfSupplyCountry: $placeOfSupplyCountry,
            isDigital: $isDigital,
            pricesIncludeTax: $pricesIncludeTax,
            notes: $notes,
        );
    }

    /**
     * Zero percent WITH a stated reason. The reason belongs on the invoice.
     *
     * @param  array<int, string>  $notes
     */
    public static function zeroRated(
        string $mechanism,
        string $reason,
        ?string $legalBasis = null,
        bool $reverseCharge = false,
        ?string $productHandle = null,
        ?string $productClass = null,
        ?string $zone = null,
        ?string $buyerCountry = null,
        ?string $placeOfSupplyCountry = null,
        bool $isDigital = false,
        ?bool $pricesIncludeTax = false,
        array $notes = [],
    ): self {
        return new self(
            rateBasisPoints: 0,
            reason: $reason,
            reverseCharge: $reverseCharge,
            mechanism: $mechanism,
            legalBasis: $legalBasis,
            productHandle: $productHandle,
            productClass: $productClass,
            zone: $zone,
            buyerCountry: $buyerCountry,
            placeOfSupplyCountry: $placeOfSupplyCountry,
            isDigital: $isDigital,
            pricesIncludeTax: $pricesIncludeTax,
            notes: $notes,
        );
    }

    /**
     * No rule found. Explicitly no rate — not even zero.
     *
     * @param  array<int, string>  $notes
     */
    public static function undetermined(
        string $code,
        string $reason,
        ?string $productHandle = null,
        ?string $productClass = null,
        ?string $buyerCountry = null,
        bool $isDigital = false,
        ?bool $pricesIncludeTax = false,
        array $notes = [],
    ): self {
        return new self(
            rateBasisPoints: null,
            reason: $reason,
            reverseCharge: false,
            mechanism: self::MECHANISM_UNDETERMINED,
            legalBasis: null,
            code: $code,
            productHandle: $productHandle,
            productClass: $productClass,
            buyerCountry: $buyerCountry,
            isDigital: $isDigital,
            pricesIncludeTax: $pricesIncludeTax,
            notes: $notes,
        );
    }

    public function isDetermined(): bool
    {
        return $this->rateBasisPoints !== null;
    }

    /**
     * Tax is shown on the line. False here means zero-rated, not undetermined — check
     * `isDetermined()` for that, because "no answer" must not read as "no tax".
     */
    public function isTaxable(): bool
    {
        return $this->rateBasisPoints !== null && $this->rateBasisPoints > 0;
    }

    /** Determined, but zero percent — with a reason. */
    public function isZeroRated(): bool
    {
        return $this->rateBasisPoints === 0;
    }

    /**
     * For callers that cannot go on without a rate: the PDF, the bookkeeping line.
     *
     * @throws \DomainException when no rate could be determined
     */
    public function requireRateBasisPoints(): int
    {
        if ($this->rateBasisPoints === null) {
            throw new \DomainException(sprintf(
                'No tax rate could be determined (%s): %s',
                (string) $this->code,
                $this->reason,
            ));
        }

        return $this->rateBasisPoints;
    }

    /** 1900 → 19.0. Throws when undetermined rather than pretending 0.0. */
    public function ratePercent(): float
    {
        return $this->requireRateBasisPoints() / 100;
    }

    /** Tax on a net amount, in cents. */
    public function taxOnNet(int $netCents): int
    {
        return self::divRound($netCents * $this->requireRateBasisPoints(), 10000);
    }

    /** The tax contained in a gross amount, in cents. */
    public function taxInGross(int $grossCents): int
    {
        $rate = $this->requireRateBasisPoints();

        return self::divRound($grossCents * $rate, 10000 + $rate);
    }

    /**
     * Splits a stored price according to point 3 of the ticket: are prices gross or net,
     * switched globally.
     *
     * Negative amounts are allowed. A credit note is an invoice with negative lines, and
     * the rounding there has to mirror the original exactly.
     *
     * @return array{net: int, tax: int, gross: int}
     */
    public function split(int $amountCents): array
    {
        // A rate of zero and no rate at all are NOT the same thing, and my
        // first attempt here treated them as one — which silently made an
        // undetermined line split as if it were tax-free. An existing test
        // caught it, which is what it was there for.
        //
        // `0` is an answer: § 19, an exemption, a zero-rated zone. The basis
        // decides nothing then, because net equals gross either way.
        // `null` is the absence of an answer and keeps falling through to the
        // throw below it, exactly as before.
        if ($this->rateBasisPoints === 0) {
            return ['net' => $amountCents, 'tax' => 0, 'gross' => $amountCents];
        }

        // Here it decides everything. 1900 is either €19.00 gross with €3.03 of
        // tax inside it, or €19.00 net plus €3.61 on top — two documents for
        // one payment, both internally consistent, and only one of them
        // matching the money that arrived. Nothing downstream contradicts the
        // wrong one, which is why this refuses instead of picking.
        // After the rate check, deliberately: an undetermined rate is the
        // older and more specific complaint, and answering it with "you have
        // not configured the price basis" would send the reader after the
        // wrong thing.
        if ($this->rateBasisPoints !== null && $this->pricesIncludeTax === null) {
            throw new PriceBasisUndecided;
        }

        if ($this->pricesIncludeTax) {
            $tax = $this->taxInGross($amountCents);

            return ['net' => $amountCents - $tax, 'tax' => $tax, 'gross' => $amountCents];
        }

        $tax = $this->taxOnNet($amountCents);

        return ['net' => $amountCents, 'tax' => $tax, 'gross' => $amountCents + $tax];
    }

    public function withNote(string $note): self
    {
        return new self(
            rateBasisPoints: $this->rateBasisPoints,
            reason: $this->reason,
            reverseCharge: $this->reverseCharge,
            mechanism: $this->mechanism,
            legalBasis: $this->legalBasis,
            code: $this->code,
            productHandle: $this->productHandle,
            productClass: $this->productClass,
            zone: $this->zone,
            buyerCountry: $this->buyerCountry,
            placeOfSupplyCountry: $this->placeOfSupplyCountry,
            isDigital: $this->isDigital,
            pricesIncludeTax: $this->pricesIncludeTax,
            notes: [...$this->notes, $note],
        );
    }

    /**
     * For storing on the immutable invoice: not only the rate but the reasoning. An audit
     * asks for the reasoning.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rate_basis_points' => $this->rateBasisPoints,
            'reason' => $this->reason,
            'reverse_charge' => $this->reverseCharge,
            'mechanism' => $this->mechanism,
            'legal_basis' => $this->legalBasis,
            'code' => $this->code,
            'product_handle' => $this->productHandle,
            'product_class' => $this->productClass,
            'zone' => $this->zone,
            'buyer_country' => $this->buyerCountry,
            'place_of_supply_country' => $this->placeOfSupplyCountry,
            'is_digital' => $this->isDigital,
            'prices_include_tax' => $this->pricesIncludeTax,
            'notes' => $this->notes,
        ];
    }

    /** Round half away from zero, so a credit note rounds as the mirror of its invoice. */
    private static function divRound(int $numerator, int $denominator): int
    {
        $sign = $numerator < 0 ? -1 : 1;
        $numerator = abs($numerator);

        return $sign * intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
