<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Support;

/**
 * Answers one question about one invoice line: which VAT rate applies, and why.
 *
 * Arithmetic only. No database, no network, no clock, no Statamic. Everything this class
 * knows comes from the config it is handed (the `tax` block of `config/invoices.php`) and
 * from the four facts about the line. That makes the answer reproducible: the same inputs
 * give the same answer in two years, and an invoice can be checked against the reasoning
 * stored on it.
 *
 * The rule underneath all of it: **never guess a rate.** When the config has no rule for a
 * case, the result is undetermined (`TaxResult::undetermined()`), not 19 %. A wrong rate
 * looks like an answer and therefore goes unnoticed; a missing one forces the question.
 * That includes a missing buyer country — old payments have none, and "none" is not
 * "Germany".
 *
 * Covers points 1 to 5 and 7 of `backlog-statamic-invoices`.
 *
 * ## Deliberately not here
 *
 * **Point 6, the OSS threshold** (10.000 € of turnover into other member states,
 * § 3a Abs. 5 Satz 3 and § 3c Abs. 4 UStG). Whether the threshold is crossed is a state
 * over time: it depends on the turnover of the current and the previous calendar year into
 * *all* other member states combined, which means a query across every payment. A
 * calculation class that runs that query stops being reproducible — its answer would
 * depend on when you call it. The seam for it is {@see self::destinationTaxationApplies()},
 * backed by the `tax.oss.destination_taxation` switch. Anyone wanting the threshold decided
 * automatically builds a separate service, evaluates it at the moment of payment, stores
 * its verdict on the payment, and feeds it in here — not the other way round.
 *
 * **The VIES lookup.** Only the format of a VAT ID is checked here, in
 * {@see self::isPlausibleVatId()}. A real confirmation request (BZSt / VIES) is a network
 * call: it can be slow, it can be down, and it can answer differently than yesterday. It
 * belongs in its own service in the checkout, which asks once, stores the answer with a
 * timestamp and the confirmation reference on the payment, and whose stored verdict then
 * arrives here as `buyerVatId`. The qualified confirmation is what carries the good-faith
 * protection, and that is a document, not a computation.
 *
 * **Half of point 2:** tax zones by state and postcode (Cargo has them) are not built. In
 * the EU the country carries the rate; states and postcodes only start to matter in the US.
 * Nor do several matching zones stack — the most specific one wins. Stacking produces a
 * compound rate, and EU VAT has no such thing.
 */
final class TaxRules
{
    /**
     * EU member states as of 08/2026. A constant rather than an operator setting on
     * purpose: this is law, not taste. Overridable through `tax.eu_member_states` in case
     * something changes before the addon catches up.
     *
     * Northern Ireland (VAT prefix XI, still inside the EU VAT area for goods) is
     * deliberately not modelled.
     *
     * @var array<int, string>
     */
    public const EU_MEMBER_STATES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR',
        'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * VAT ID formats per member state, without the country prefix. Syntax only: it says
     * nothing about whether the number exists or belongs to this buyer. That is what the
     * VIES lookup is for — see the class docblock.
     *
     * @var array<string, string>
     */
    private const VAT_ID_PATTERNS = [
        'AT' => 'U[0-9]{8}',
        'BE' => '[01][0-9]{9}',
        'BG' => '[0-9]{9,10}',
        'CY' => '[0-9]{8}[A-Z]',
        'CZ' => '[0-9]{8,10}',
        'DE' => '[0-9]{9}',
        'DK' => '[0-9]{8}',
        'EE' => '[0-9]{9}',
        'ES' => '[A-Z0-9][0-9]{7}[A-Z0-9]',
        'FI' => '[0-9]{8}',
        'FR' => '[A-Z0-9]{2}[0-9]{9}',
        'GR' => '[0-9]{9}',
        'HR' => '[0-9]{11}',
        'HU' => '[0-9]{8}',
        'IE' => '(?:[0-9]{7}[A-W]|[0-9][A-Z*+][0-9]{5}[A-W]|[0-9]{7}[A-W][AH])',
        'IT' => '[0-9]{11}',
        'LT' => '(?:[0-9]{9}|[0-9]{12})',
        'LU' => '[0-9]{8}',
        'LV' => '[0-9]{11}',
        'MT' => '[0-9]{8}',
        'NL' => '[0-9]{9}B[0-9]{2}',
        'PL' => '[0-9]{10}',
        'PT' => '[0-9]{9}',
        'RO' => '[0-9]{2,10}',
        'SE' => '[0-9]{12}',
        'SI' => '[0-9]{8}',
        'SK' => '[0-9]{10}',
    ];

    /** Greece issues its VAT IDs with EL, its country code is GR. */
    private const VAT_ID_PREFIX_ALIASES = ['EL' => 'GR'];

    /**
     * Defaults for everything the class needs. The shipped `config/invoices.php` repeats
     * them with commentary; they live here as well so the class still answers without
     * Laravel and without a published config.
     *
     * On purpose: rates and product classes are EMPTY. Without maintenance the class says
     * "undetermined", not "19 %".
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'small_business' => ['enabled' => false],
        'merchant_country' => 'DE',
        'merchant_vat_id' => null,
        'prices_include_tax' => false,
        'assume_country_when_missing' => null,
        'default_product_class' => null,
        'product_classes' => [],
        'exemptions' => [],
        'zones' => [],
        'oss' => ['destination_taxation' => false],
        'eu_member_states' => null,
        'texts' => [
            'small_business' => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.',
            'reverse_charge' => 'Steuerschuldnerschaft des Leistungsempfängers.',
            'intra_community_supply' => 'Steuerfreie innergemeinschaftliche Lieferung.',
            'export' => 'Steuerfreie Ausfuhrlieferung.',
            'outside_scope' => 'Nicht im Inland steuerbar; der Leistungsort liegt im Land des Empfängers.',
            'zero_rate' => 'Kein Umsatzsteuerausweis.',
        ],
        'legal_bases' => [
            'small_business' => '§ 19 UStG',
            'reverse_charge' => '§ 3a Abs. 2 UStG i. V. m. Art. 196 MwStSystRL, Hinweispflicht § 14a Abs. 5 UStG',
            'intra_community_supply' => '§ 4 Nr. 1 Buchst. b i. V. m. § 6a UStG',
            'export' => '§ 4 Nr. 1 Buchst. a i. V. m. § 6 UStG',
            'outside_scope' => '§ 3a UStG',
        ],
    ];

    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * Sub-keys of the two switches that change behaviour when they are silently absent. A
     * typo in either of these does not produce a visible error, it produces an invoice with
     * the wrong tax on it — so they are checked rather than defaulted.
     *
     * @var array<string, array<int, string>>
     */
    private const CHECKED_SUB_KEYS = [
        'small_business' => ['enabled'],
        'oss' => ['destination_taxation'],
    ];

    /**
     * @param  array<string, mixed>  $config  the `tax` block of `config/invoices.php`
     *
     * @throws \InvalidArgumentException on a key this class does not know
     */
    public function __construct(array $config = [])
    {
        $unknown = array_diff(array_keys($config), array_keys(self::DEFAULTS));

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown key(s) in the tax config: %s. Known keys are: %s. A misspelt key would '
                .'quietly leave its default in place, and a default is exactly what this class '
                .'exists not to fall back on.',
                implode(', ', $unknown),
                implode(', ', array_keys(self::DEFAULTS)),
            ));
        }

        foreach (self::CHECKED_SUB_KEYS as $block => $allowed) {
            if (! array_key_exists($block, $config)) {
                continue;
            }

            // `'small_business' => true` is what a person writes for a switch. Take it, but
            // take it deliberately — cfg() would otherwise walk into a bool and hand back
            // the default, which reads as "off".
            if (is_bool($config[$block])) {
                $config[$block] = [$allowed[0] => $config[$block]];
            }

            if (! is_array($config[$block])) {
                throw new \InvalidArgumentException(sprintf(
                    'tax.%s has to be an array (or a boolean shorthand for "%s"), %s given.',
                    $block,
                    $allowed[0],
                    get_debug_type($config[$block]),
                ));
            }

            $strayKeys = array_diff(array_keys($config[$block]), $allowed);

            if ($strayKeys !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown key(s) in tax.%s: %s. Known: %s. Left alone this would switch the '
                    .'rule off without saying so, and show tax on an invoice that should carry none.',
                    $block,
                    implode(', ', $strayKeys),
                    implode(', ', $allowed),
                ));
            }
        }

        $this->config = array_replace(self::DEFAULTS, $config);
    }

    /**
     * @param  array<string, mixed>|null  $config  null reads the Laravel config
     */
    public static function fromConfig(?array $config = null): self
    {
        return new self($config ?? self::configFromEnvironment());
    }

    /**
     * The convenient entry point, meant to be called with named arguments.
     *
     * @param  string  $productHandle  the product's handle, a key in `tax.product_classes`
     * @param  string|null  $buyerCountry  ISO 3166-1 alpha-2, null when it was never stored
     * @param  string|null  $buyerVatId  the buyer's VAT ID, including the country prefix
     * @param  bool  $isDigital  a digital supply (true) or physical goods (false)
     * @param  array<string, mixed>|null  $config  overrides the Laravel config
     */
    public static function for(
        string $productHandle,
        ?string $buyerCountry = null,
        ?string $buyerVatId = null,
        bool $isDigital = false,
        ?array $config = null,
    ): TaxResult {
        return self::fromConfig($config)->resolve(
            productHandle: $productHandle,
            buyerCountry: $buyerCountry,
            buyerVatId: $buyerVatId,
            isDigital: $isDigital,
        );
    }

    /**
     * The decision itself.
     */
    public function resolve(
        string $productHandle,
        ?string $buyerCountry = null,
        ?string $buyerVatId = null,
        bool $isDigital = false,
    ): TaxResult {
        $notes = [];
        $pricesIncludeTax = (bool) $this->cfg('prices_include_tax', false);
        $merchantCountry = $this->normalizeCountry((string) $this->cfg('merchant_country', 'DE'));
        $vatId = $this->normalizeVatId($buyerVatId);

        // ── 7. Small business scheme, § 19 UStG ──────────────────────────────────
        // First, because it suspends everything else: no tax is shown, whatever the
        // product, the country or the VAT ID say. Which is why it also answers when
        // nothing is configured for the product or the country — there is nothing left
        // to determine.
        if ((bool) $this->cfg('small_business.enabled', false)) {
            $country = $this->normalizeCountry($buyerCountry);

            if ($vatId !== null && $this->isPlausibleVatId($vatId) && $country !== null
                && $country !== $merchantCountry && $this->isEuMemberState($country)) {
                // Same shape of doubt as the exemption branch further down, and worth the
                // same warning.
                $notes[] = 'Cross-border B2B case while the small business scheme is on: § 19 UStG '
                    .'is a domestic rule and does not obviously override the place-of-supply shift. '
                    .'Have a tax adviser confirm this before invoicing it this way.';
            }

            return TaxResult::zeroRated(
                mechanism: TaxResult::MECHANISM_SMALL_BUSINESS,
                reason: (string) $this->cfg('texts.small_business', self::DEFAULTS['texts']['small_business']),
                legalBasis: $this->legalBasis('small_business'),
                productHandle: $productHandle,
                // Not needed for the decision, but the invoice is read years later and the
                // class it covered is part of what happened.
                productClass: $this->productClassFor($productHandle),
                buyerCountry: $country,
                isDigital: $isDigital,
                pricesIncludeTax: $pricesIncludeTax,
                notes: $notes,
            );
        }

        // ── 1. Tax class on the product ──────────────────────────────────────────
        $productClass = $this->productClassFor($productHandle);

        if ($productClass === null) {
            return TaxResult::undetermined(
                code: 'unknown_product_class',
                reason: sprintf(
                    'No tax class is configured for product "%s", and there is no fallback '
                    .'(tax.default_product_class). No rate is assumed.',
                    $productHandle,
                ),
                productHandle: $productHandle,
                isDigital: $isDigital,
                pricesIncludeTax: $pricesIncludeTax,
            );
        }

        // ── The buyer's country ──────────────────────────────────────────────────
        // A missing country is not a German one. Payments from before the column existed
        // arrive without it, and those have to surface rather than slip through.
        $given = is_string($buyerCountry) ? trim($buyerCountry) : '';
        $country = $this->normalizeCountry($buyerCountry);

        if ($country === null && $given !== '') {
            return TaxResult::undetermined(
                code: 'invalid_country',
                reason: sprintf('"%s" is not a valid ISO 3166-1 alpha-2 country code.', $given),
                productHandle: $productHandle,
                productClass: $productClass,
                isDigital: $isDigital,
                pricesIncludeTax: $pricesIncludeTax,
            );
        }

        if ($country === null) {
            $assumed = $this->normalizeCountry($this->cfg('assume_country_when_missing'));

            if ($assumed === null) {
                return TaxResult::undetermined(
                    code: 'missing_country',
                    reason: 'This payment has no buyer country stored. Without one the rate cannot be '
                        .'determined, and it will not be assumed. (An explicit assumption is available '
                        .'through tax.assume_country_when_missing.)',
                    productHandle: $productHandle,
                    productClass: $productClass,
                    isDigital: $isDigital,
                    pricesIncludeTax: $pricesIncludeTax,
                );
            }

            $country = $assumed;
            $notes[] = sprintf(
                'No buyer country was stored; "%s" was assumed per tax.assume_country_when_missing. '
                .'The operator makes that assumption, not this class.',
                $assumed,
            );
        }

        $isDomestic = $country === $merchantCountry;

        // ── 4. The buyer's VAT ID ────────────────────────────────────────────────
        $isBusiness = false;

        if ($vatId !== null) {
            if (! $this->isPlausibleVatId($vatId)) {
                // Undetermined would be the wrong severity here. The safe direction is to
                // charge tax: charging it wrongly means owing it (§ 14c UStG) and being able
                // to correct it, while leaving it off wrongly leaves a hole.
                $notes[] = sprintf(
                    'VAT ID "%s" matches no known format. The line is treated as B2C and tax is '
                    .'charged. Worth checking.',
                    $vatId,
                );
            } elseif ($this->vatIdCountry($vatId) !== $country) {
                // A contradiction in the input, not a missing rule: the class cannot tell
                // which of the two facts is true, so it does not pick one.
                return TaxResult::undetermined(
                    code: 'vat_id_country_mismatch',
                    reason: sprintf(
                        'VAT ID "%s" (%s) and the stated buyer country "%s" contradict each other.',
                        $vatId,
                        (string) $this->vatIdCountry($vatId),
                        $country,
                    ),
                    productHandle: $productHandle,
                    productClass: $productClass,
                    buyerCountry: $country,
                    isDigital: $isDigital,
                    pricesIncludeTax: $pricesIncludeTax,
                    notes: $notes,
                );
            } else {
                $isBusiness = true;
            }
        }

        // ── 1b. An exemption attached to the supply itself ───────────────────────
        $exemption = $this->exemptionFor($productClass);

        if ($exemption !== null) {
            $reason = isset($exemption['reason']) && is_string($exemption['reason'])
                ? trim($exemption['reason'])
                : '';

            if ($reason === '') {
                // § 14 Abs. 4 Nr. 8 UStG wants the exemption stated on the invoice. An
                // exemption without a stated ground is unusable.
                return TaxResult::undetermined(
                    code: 'exemption_without_reason',
                    reason: sprintf(
                        'The exemption "%s" has no reason configured. Without the note naming the '
                        .'exemption, the invoice must not go out without tax.',
                        $productClass,
                    ),
                    productHandle: $productHandle,
                    productClass: $productClass,
                    buyerCountry: $country,
                    isDigital: $isDigital,
                    pricesIncludeTax: $pricesIncludeTax,
                    notes: $notes,
                );
            }

            $domesticOnly = ! isset($exemption['domestic_only']) || (bool) $exemption['domestic_only'];

            if ($domesticOnly && ! $isDomestic) {
                return TaxResult::undetermined(
                    code: 'exemption_outside_domestic',
                    reason: sprintf(
                        'The exemption "%s" is configured as domestic only, but the recipient is in '
                        .'%s. Whether it holds there is a question of foreign law, and this class '
                        .'does not answer it.',
                        $productClass,
                        $country,
                    ),
                    productHandle: $productHandle,
                    productClass: $productClass,
                    buyerCountry: $country,
                    isDigital: $isDigital,
                    pricesIncludeTax: $pricesIncludeTax,
                    notes: $notes,
                );
            }

            // The exemption is checked before the cross-border branch, so an exemption the
            // operator has marked as applying abroad displaces the shift of liability rather
            // than the other way round. That ordering is a decision, so it gets said out loud.
            if ($isBusiness && ! $isDomestic && $this->isEuMemberState($country)) {
                $notes[] = 'An exemption was applied to a cross-border B2B supply, so the '
                    .'reverse-charge branch was never reached. Which of the two governs is a '
                    .'question for a tax adviser, not for this class.';
            }

            return TaxResult::zeroRated(
                mechanism: TaxResult::MECHANISM_EXEMPT,
                reason: $reason,
                legalBasis: isset($exemption['legal_basis']) ? (string) $exemption['legal_basis'] : null,
                productHandle: $productHandle,
                productClass: $productClass,
                buyerCountry: $country,
                placeOfSupplyCountry: $country,
                isDigital: $isDigital,
                pricesIncludeTax: $pricesIncludeTax,
                notes: $notes,
            );
        }

        // ── 4./5. B2B into another member state ──────────────────────────────────
        // Cross-border only: a German VAT ID on a German buyer shifts nothing, that line
        // carries ordinary tax.
        if ($isBusiness && ! $isDomestic && $this->isEuMemberState($country)) {
            if ($this->cfg('merchant_vat_id') === null) {
                $notes[] = 'The seller\'s own VAT ID is missing (tax.merchant_vat_id). § 14a UStG '
                    .'wants both numbers on the document.';
            }

            if ($isDigital) {
                return TaxResult::zeroRated(
                    mechanism: TaxResult::MECHANISM_REVERSE_CHARGE,
                    reason: (string) $this->cfg('texts.reverse_charge', self::DEFAULTS['texts']['reverse_charge']),
                    legalBasis: $this->legalBasis('reverse_charge'),
                    reverseCharge: true,
                    productHandle: $productHandle,
                    productClass: $productClass,
                    buyerCountry: $country,
                    placeOfSupplyCountry: $country,
                    isDigital: true,
                    pricesIncludeTax: $pricesIncludeTax,
                    notes: [
                        ...$notes,
                        'Reverse charge needs a confirmed VAT ID (VIES); only the format was checked '
                        .'here. The turnover also has to appear in the recapitulative statement (ZM).',
                    ],
                );
            }

            // Goods rather than a service: same amount, different provision, different note.
            // `reverseCharge` stays false on purpose — the liability does not shift under
            // § 13b; the buyer taxes an intra-community acquisition instead.
            return TaxResult::zeroRated(
                mechanism: TaxResult::MECHANISM_INTRA_COMMUNITY_SUPPLY,
                reason: (string) $this->cfg('texts.intra_community_supply', self::DEFAULTS['texts']['intra_community_supply']),
                legalBasis: $this->legalBasis('intra_community_supply'),
                reverseCharge: false,
                productHandle: $productHandle,
                productClass: $productClass,
                buyerCountry: $country,
                placeOfSupplyCountry: $country,
                isDigital: false,
                pricesIncludeTax: $pricesIncludeTax,
                notes: [
                    ...$notes,
                    'Exempt only with proof that the goods arrived in the other member state '
                    .'(Gelangensbestätigung) and with a confirmed VAT ID. This class checks neither.',
                ],
            );
        }

        // ── 5. Third countries ───────────────────────────────────────────────────
        if (! $this->isEuMemberState($country)) {
            if ($isDigital) {
                return TaxResult::zeroRated(
                    mechanism: TaxResult::MECHANISM_OUTSIDE_SCOPE,
                    reason: (string) $this->cfg('texts.outside_scope', self::DEFAULTS['texts']['outside_scope']),
                    legalBasis: $this->legalBasis('outside_scope'),
                    productHandle: $productHandle,
                    productClass: $productClass,
                    buyerCountry: $country,
                    placeOfSupplyCountry: $country,
                    isDigital: true,
                    pricesIncludeTax: $pricesIncludeTax,
                    notes: [
                        ...$notes,
                        sprintf(
                            'No German VAT, but possibly a registration duty in %s (UK VAT, US sales '
                            .'tax and so on). This class does not check that.',
                            $country,
                        ),
                    ],
                );
            }

            return TaxResult::zeroRated(
                mechanism: TaxResult::MECHANISM_EXPORT,
                reason: (string) $this->cfg('texts.export', self::DEFAULTS['texts']['export']),
                legalBasis: $this->legalBasis('export'),
                productHandle: $productHandle,
                productClass: $productClass,
                buyerCountry: $country,
                placeOfSupplyCountry: $country,
                isDigital: false,
                pricesIncludeTax: $pricesIncludeTax,
                notes: [
                    ...$notes,
                    'Exempt only with proof of export (§ 6 Abs. 4 UStG, §§ 9 ff. UStDV). This class '
                    .'does not check for it.',
                ],
            );
        }

        // ── 2./3./5./6. The rate, out of the zone ────────────────────────────────
        // Domestic: our own rate. B2C into the EU: our own rate below the OSS threshold,
        // the recipient country's above it — and which of the two applies is what the
        // switch says, not this class. See destinationTaxationApplies().
        $placeOfSupply = $country;
        $rateCountry = $merchantCountry;

        if (! $isDomestic) {
            if ($this->destinationTaxationApplies()) {
                $rateCountry = $country;
                $notes[] = sprintf(
                    'Taxed in the recipient country (%s) per tax.oss.destination_taxation. The '
                    .'10.000 € threshold itself is not calculated.',
                    $country,
                );
            } else {
                $placeOfSupply = $merchantCountry;
                $notes[] = sprintf(
                    'Own rate (%s) per tax.oss.destination_taxation = false, i.e. below the '
                    .'10.000 € threshold. The threshold is not calculated — whoever crosses it has '
                    .'to flip the switch.',
                    $merchantCountry,
                );
            }
        }

        [$zoneKey, $zone] = $this->zoneFor($rateCountry);

        if ($zone === null) {
            return TaxResult::undetermined(
                code: 'no_zone_for_country',
                reason: sprintf(
                    'No tax zone is configured for country "%s" and there is no placeholder zone. '
                    .'No rate is assumed.',
                    $rateCountry,
                ),
                productHandle: $productHandle,
                productClass: $productClass,
                buyerCountry: $country,
                isDigital: $isDigital,
                pricesIncludeTax: $pricesIncludeTax,
                notes: $notes,
            );
        }

        $rates = isset($zone['rates']) && is_array($zone['rates']) ? $zone['rates'] : [];

        if (! array_key_exists($productClass, $rates) || ! is_int($rates[$productClass])) {
            return TaxResult::undetermined(
                code: 'no_rate_for_product_class',
                reason: sprintf(
                    array_key_exists($productClass, $rates)
                        ? 'Tax zone "%s" (%s) has a rate for tax class "%s" that is not an integer '
                            .'number of basis points (1900 for 19 %%). No rate is assumed.'
                        : 'Tax zone "%s" (%s) has no rate for tax class "%s". No rate is assumed.',
                    $zoneKey,
                    $rateCountry,
                    $productClass,
                ),
                productHandle: $productHandle,
                productClass: $productClass,
                buyerCountry: $country,
                isDigital: $isDigital,
                pricesIncludeTax: $pricesIncludeTax,
                notes: $notes,
            );
        }

        $rate = $rates[$productClass];

        // Basis points, not percent. `19` is the typo everyone makes, and left alone it
        // prints "Umsatzsteuer 0,19 %." and charges 19 cents on a hundred euros — a wrong
        // figure on a tax document, arrived at quietly.
        if ($rate < 0 || $rate > 10000 || ($rate > 0 && $rate < 100)) {
            return TaxResult::undetermined(
                code: 'implausible_rate',
                reason: sprintf(
                    'Tax zone "%s" (%s) has %d as the rate for tax class "%s". Rates are basis '
                    .'points: 1900 is 19 %%, 700 is 7 %%. That value is outside the plausible range, '
                    .'so no rate is assumed.',
                    $zoneKey,
                    $rateCountry,
                    $rate,
                    $productClass,
                ),
                productHandle: $productHandle,
                productClass: $productClass,
                buyerCountry: $country,
                isDigital: $isDigital,
                pricesIncludeTax: $pricesIncludeTax,
                notes: $notes,
            );
        }

        if ($rate === 0) {
            // A configured zero rate without a ground is awkward on an invoice:
            // § 14 Abs. 4 Nr. 8 UStG wants a note saying why no tax is due.
            return TaxResult::zeroRated(
                mechanism: TaxResult::MECHANISM_STANDARD,
                reason: (string) $this->cfg('texts.zero_rate', self::DEFAULTS['texts']['zero_rate']),
                productHandle: $productHandle,
                productClass: $productClass,
                zone: $zoneKey,
                buyerCountry: $country,
                placeOfSupplyCountry: $placeOfSupply,
                isDigital: $isDigital,
                pricesIncludeTax: $pricesIncludeTax,
                notes: [
                    ...$notes,
                    sprintf(
                        'Zone "%s" is configured at 0 %% for class "%s" without naming a ground. If '
                        .'this is a real exemption it belongs under tax.exemptions, with its reason.',
                        $zoneKey,
                        $productClass,
                    ),
                ],
            );
        }

        $reason = $isDomestic
            ? sprintf('Umsatzsteuer %s %%.', $this->formatPercent($rate))
            : sprintf('Umsatzsteuer %s %% (Leistungsort %s).', $this->formatPercent($rate), $placeOfSupply);

        return TaxResult::taxed(
            rateBasisPoints: $rate,
            reason: $reason,
            productHandle: $productHandle,
            productClass: $productClass,
            zone: $zoneKey,
            buyerCountry: $country,
            placeOfSupplyCountry: $placeOfSupply,
            isDigital: $isDigital,
            pricesIncludeTax: $pricesIncludeTax,
            notes: $notes,
        );
    }

    /**
     * Format check on a VAT ID: country prefix plus that country's pattern.
     *
     * What it does not say: whether the number was ever issued, whether it is still valid,
     * and whether it belongs to this buyer. Only the confirmation request (VIES / BZSt)
     * answers that, and it does not belong in a calculation class — see the class docblock.
     * Anyone invoicing reverse charge needs the qualified confirmation as evidence, not this
     * check.
     */
    public function isPlausibleVatId(string $vatId): bool
    {
        $vatId = (string) $this->normalizeVatId($vatId);

        if (strlen($vatId) < 3) {
            return false;
        }

        $country = $this->vatIdCountry($vatId);

        if ($country === null || ! isset(self::VAT_ID_PATTERNS[$country])) {
            return false;
        }

        return (bool) preg_match('/^'.self::VAT_ID_PATTERNS[$country].'$/', substr($vatId, 2));
    }

    /** The country behind a VAT ID; EL resolves to GR. */
    public function vatIdCountry(string $vatId): ?string
    {
        $vatId = (string) $this->normalizeVatId($vatId);

        if (strlen($vatId) < 3) {
            return null;
        }

        $prefix = substr($vatId, 0, 2);
        $prefix = self::VAT_ID_PREFIX_ALIASES[$prefix] ?? $prefix;

        return isset(self::VAT_ID_PATTERNS[$prefix]) ? $prefix : null;
    }

    public function isEuMemberState(string $country): bool
    {
        $configured = $this->cfg('eu_member_states');
        $states = is_array($configured) && $configured !== [] ? $configured : self::EU_MEMBER_STATES;

        return in_array(strtoupper($country), array_map('strtoupper', $states), true);
    }

    /**
     * Point 6 of the ticket, and the only place the OSS threshold has any effect.
     *
     * Today a switch the operator sets. If the threshold is ever to be decided
     * automatically, this is exactly where the passed-in verdict goes: computed by a
     * service that sums the EU B2C turnover of the current and the previous calendar year
     * (§ 3a Abs. 5 Satz 3, § 3c Abs. 4 UStG), evaluated at the moment of payment and stored
     * on the payment. Do not query it here — the answer of this class would then depend on
     * when you asked, and an invoice could no longer be recomputed.
     */
    private function destinationTaxationApplies(): bool
    {
        return (bool) $this->cfg('oss.destination_taxation', false);
    }

    private function productClassFor(string $handle): ?string
    {
        $classes = $this->cfg('product_classes', []);
        $class = is_array($classes) ? ($classes[$handle] ?? null) : null;

        if (! is_string($class) || trim($class) === '') {
            $class = $this->cfg('default_product_class');
        }

        return is_string($class) && trim($class) !== '' ? trim($class) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exemptionFor(string $productClass): ?array
    {
        $exemptions = $this->cfg('exemptions', []);

        if (! is_array($exemptions) || ! array_key_exists($productClass, $exemptions)) {
            return null;
        }

        $exemption = $exemptions[$productClass];

        return is_array($exemption) ? $exemption : ['reason' => is_string($exemption) ? $exemption : ''];
    }

    /**
     * The most specific matching zone. A "*" in `countries` is the placeholder for all
     * remaining countries; a country named explicitly always beats it.
     *
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    private function zoneFor(string $country): array
    {
        $zones = $this->cfg('zones', []);

        if (! is_array($zones)) {
            return [null, null];
        }

        $placeholder = [null, null];

        foreach ($zones as $key => $zone) {
            if (! is_array($zone)) {
                continue;
            }

            $countries = isset($zone['countries']) && is_array($zone['countries']) ? $zone['countries'] : [];
            $countries = array_map(static fn ($c): string => strtoupper((string) $c), $countries);

            if (in_array($country, $countries, true)) {
                return [(string) $key, $zone];
            }

            if ($placeholder[0] === null && in_array('*', $countries, true)) {
                $placeholder = [(string) $key, $zone];
            }
        }

        return $placeholder;
    }

    private function normalizeCountry(mixed $country): ?string
    {
        if (! is_string($country)) {
            return null;
        }

        $country = strtoupper(trim($country));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    private function normalizeVatId(?string $vatId): ?string
    {
        if (! is_string($vatId)) {
            return null;
        }

        $vatId = strtoupper((string) preg_replace('/[\s.\-\/]/', '', $vatId));

        return $vatId === '' ? null : $vatId;
    }

    private function legalBasis(string $key): ?string
    {
        $value = $this->cfg('legal_bases.'.$key, self::DEFAULTS['legal_bases'][$key] ?? null);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** 1900 → "19", 250 → "2,5". */
    private function formatPercent(int $basisPoints): string
    {
        $formatted = number_format($basisPoints / 100, 2, ',', '');
        $formatted = rtrim($formatted, '0');

        return rtrim($formatted, ',');
    }

    /**
     * Dotted config access without the Laravel helper — the class has to calculate in a
     * bare PHP process too.
     */
    private function cfg(string $path, mixed $default = null): mixed
    {
        $value = $this->config;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    private static function configFromEnvironment(): array
    {
        if (function_exists('config')) {
            $config = config('invoices.tax');

            if (is_array($config)) {
                return $config;
            }
        }

        return [];
    }
}
