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
 * its verdict on the payment, and feeds it in here — not the other way round. The same
 * threshold has a second switch under `tax.small_business.eu_threshold_mode`, because it
 * decides something else there: not which rate, but whether a small business's 0 % still
 * holds for a consumer in another member state (§ 3a Abs. 5, § 19a UStG).
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
        // `eu_scheme`: the seller takes part in the EU small business scheme (§ 19a UStG,
        // since 2025, the "EX" number). `eu_threshold_mode`: which side of the 10.000 €
        // threshold (§ 3a Abs. 5 Satz 3, § 3c Abs. 4 UStG) the seller is on — 'below' or
        // 'above'. Both only matter for a consumer in another member state; see the § 19
        // branch in resolve().
        'small_business' => ['enabled' => false, 'eu_scheme' => false, 'eu_threshold_mode' => 'below'],
        'merchant_country' => 'DE',
        'merchant_vat_id' => null,
        // No default, on purpose. Whether a catalogue price already contains
        // tax cannot be guessed: the same payment becomes two different,
        // equally consistent invoices depending on the answer, and only one of
        // them matches the money that arrived. See PriceBasisUndecided.
        'prices_include_tax' => null,
        'assume_country_when_missing' => null,
        'default_product_class' => null,
        'product_classes' => [],
        'exemptions' => [],
        'zones' => [],
        'oss' => ['destination_taxation' => false],
        'eu_member_states' => null,
        // Read by BuyerAdmission and the console command, not by this class: the
        // confirmation is a network call and this is a calculation. They are listed
        // here so the constructor stops refusing them as unknown keys, and so the
        // whole tax block has one place that names everything it may contain.
        'vat_id_check' => ['enabled' => true, 'service' => 'vies', 'timeout' => 8, 'cache_hours' => 168],
        'business_only' => ['enabled' => true, 'require_company' => true],
        'texts' => [
            'small_business' => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.',
            'small_business_eu' => 'Steuerfrei nach der EU-Kleinunternehmerregelung, § 19a UStG.',
            'reverse_charge' => 'Steuerschuldnerschaft des Leistungsempfängers.',
            'intra_community_supply' => 'Steuerfreie innergemeinschaftliche Lieferung.',
            'export' => 'Steuerfreie Ausfuhrlieferung.',
            'outside_scope' => 'Nicht im Inland steuerbar; der Leistungsort liegt im Land des Empfängers.',
            'zero_rate' => 'Kein Umsatzsteuerausweis.',
        ],
        // The same sentences in English, appended to the German ones on the two
        // zones a foreign business sees. Not a translation layer: § 14a Abs. 1 UStG
        // prescribes the German phrase, so the German phrase stays and the English
        // one follows it. A buyer in Lisbon has to be able to act on the document
        // without translating it first, and their accountant reads the English half.
        // An empty string leaves the German sentence alone.
        'texts_en' => [
            'reverse_charge' => 'Reverse charge: the recipient is liable for the VAT.',
            'intra_community_supply' => 'Tax-exempt intra-community supply.',
            'outside_scope' => 'Not taxable in Germany; the place of supply is the customer\'s country.',
            'export' => 'Tax-exempt export.',
        ],
        'legal_bases' => [
            'small_business' => '§ 19 UStG',
            'small_business_eu' => '§ 19a UStG i. V. m. Art. 284 MwStSystRL',
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
        'small_business' => ['enabled', 'eu_scheme', 'eu_threshold_mode'],
        'oss' => ['destination_taxation'],
    ];

    /**
     * The only two answers to "which side of the 10.000 € threshold". Checked for the same
     * reason the keys are: 'abvoe' would otherwise read as 'below' and switch the warning off.
     *
     * @var array<int, string>
     */
    private const EU_THRESHOLD_MODES = ['below', 'above'];

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

        $mode = $config['small_business']['eu_threshold_mode'] ?? null;

        if ($mode !== null && ! in_array($mode, self::EU_THRESHOLD_MODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'tax.small_business.eu_threshold_mode has to be one of %s, %s given. A value this '
                .'class does not know would quietly count as "below" and silence a warning about '
                .'tax owed in another member state.',
                implode(', ', array_map(static fn (string $m): string => "'{$m}'", self::EU_THRESHOLD_MODES)),
                is_string($mode) ? "'{$mode}'" : get_debug_type($mode),
            ));
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
     * @param  VatIdStatus|null  $vatIdStatus  the frozen verdict of the confirmation
     *                                         service, when one was asked. Null means nobody asked, and the class
     *                                         then says exactly that on the document.
     * @param  bool  $buyerIsBusiness  the buyer declared they are buying as a business.
     *                                 Only used outside the EU, where there is no register to ask.
     */
    public static function for(
        string $productHandle,
        ?string $buyerCountry = null,
        ?string $buyerVatId = null,
        bool $isDigital = false,
        ?array $config = null,
        ?VatIdStatus $vatIdStatus = null,
        bool $buyerIsBusiness = false,
    ): TaxResult {
        return self::fromConfig($config)->resolve(
            productHandle: $productHandle,
            buyerCountry: $buyerCountry,
            buyerVatId: $buyerVatId,
            isDigital: $isDigital,
            vatIdStatus: $vatIdStatus,
            buyerIsBusiness: $buyerIsBusiness,
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
        ?VatIdStatus $vatIdStatus = null,
        bool $buyerIsBusiness = false,
    ): TaxResult {
        $notes = [];

        // ── The three zones of a business-only seller ────────────────────────────
        // Before everything else, § 19 included, and that ordering is the point.
        //
        // § 19 UStG is a domestic rule. A supply to a business in another country is
        // taxed where that business sits (§ 3a Abs. 2 UStG) and is not taxable in
        // Germany at all — so there is no German exemption to invoke and no § 19 note
        // to print. The earlier reading had § 19 answer first and merely warn about
        // the case; that produced a document saying "no VAT under § 19" where
        // § 14a Abs. 1 UStG wants "Steuerschuldnerschaft des Leistungsempfängers",
        // which is the note the buyer needs in order to account for it at home.
        // Derivation: TASKS/suite-steuer-selbsteinschaetzung-2026-09-05.md, question (b).
        //
        // It only takes effect once somebody has actually established that the buyer
        // is a business — a confirmed VAT ID inside the EU, a declaration outside it.
        // Without that evidence this returns null and the old path answers, so a
        // format-only VAT ID changes nothing about how an invoice reads today.
        $crossBorder = $this->crossBorderBusiness(
            productHandle: $productHandle,
            buyerCountry: $buyerCountry,
            buyerVatId: $buyerVatId,
            isDigital: $isDigital,
            vatIdStatus: $vatIdStatus,
            buyerIsBusiness: $buyerIsBusiness,
        );

        if ($crossBorder !== null) {
            return $crossBorder;
        }

        // NOT asked here, and the first attempt was wrong to.
        //
        // The basis only decides anything when tax is actually applied. Under
        // § 19 no tax is shown at all, an exempt or zero-rated line adds
        // nothing, and an undetermined rate has no arithmetic to do — in every
        // one of those cases net equals gross and the question is moot.
        // Refusing there would have turned three legitimate answers into an
        // error, including "this seller charges no VAT", which is the whole
        // point of the small-business branch below.
        //
        // The refusal lives in TaxResult::split(), which is the one place the
        // money is actually divided. False here is a placeholder that split()
        // never reaches when the real answer is missing.
        // Passed through as it stands, null included. Casting to bool here
        // would turn "nobody said" into "net", which is exactly the silent
        // wrong answer this refuses to give.
        $pricesIncludeTax = $this->cfg('prices_include_tax');
        $pricesIncludeTax = $pricesIncludeTax === null ? null : (bool) $pricesIncludeTax;
        $merchantCountry = $this->normalizeCountry((string) $this->cfg('merchant_country', 'DE'));
        $vatId = $this->normalizeVatId($buyerVatId);

        // ── 7. Small business scheme, § 19 UStG ──────────────────────────────────
        // First, because it suspends everything else: no tax is shown, whatever the
        // product, the country or the VAT ID say. Which is why it also answers when
        // nothing is configured for the product or the country — there is nothing left
        // to determine.
        if ((bool) $this->cfg('small_business.enabled', false)) {
            $country = $this->normalizeCountry($buyerCountry);
            $isBusiness = $vatId !== null && $this->isPlausibleVatId($vatId);
            $crossBorderEu = $country !== null && $country !== $merchantCountry && $this->isEuMemberState($country);
            $textKey = 'small_business';
            $placeOfSupply = null;

            if ($crossBorderEu && $isBusiness) {
                // Same shape of doubt as the exemption branch further down, and worth the
                // same warning.
                $notes[] = 'Cross-border B2B case while the small business scheme is on: § 19 UStG '
                    .'is a domestic rule and does not obviously override the place-of-supply shift. '
                    .'Have a tax adviser confirm this before invoicing it this way.';
            }

            // A consumer in another member state. § 19 is silent here, and silence was the
            // wrong answer: for a digital supply the place of supply is where the consumer
            // sits (§ 3a Abs. 5 UStG), for goods it moves there too (§ 3c UStG) — once the
            // seller's EU-wide B2C turnover is past 10.000 € a year (§ 3a Abs. 5 Satz 3,
            // § 3c Abs. 4 UStG). Above that line the German exemption reaches the other
            // country only through the EU small business scheme (§ 19a UStG, since 2025);
            // without it, that country's VAT is due, via OSS. Below the line the place of
            // supply stays at home and § 19 answers as before.
            //
            // Which side of the line the seller is on is a fact about a year's turnover,
            // not about this line — the same reason the OSS threshold is a switch further
            // down. So it is a switch here too, and the same one is not reused: OSS
            // destination taxation is about *which rate*, this is about *whether 0 % is
            // still true*. This is the reading of the law this class works with; it has
            // not been confirmed by a tax adviser, and the note says so.
            if ($crossBorderEu && ! $isBusiness
                && $this->cfg('small_business.eu_threshold_mode', 'below') === 'above') {
                if ((bool) $this->cfg('small_business.eu_scheme', false)) {
                    $textKey = 'small_business_eu';
                    $placeOfSupply = $country;
                } else {
                    $notes[] = sprintf(
                        'Verbraucher in %s: über der 10.000-€-Schwelle fällt Umsatzsteuer im '
                        .'Käuferland an (%s). Ohne EU-Kleinunternehmerregelung (§ 19a UStG) ist '
                        .'0 %% hier vermutlich falsch. Mit dem Steuerberater klären, bevor so '
                        .'fakturiert wird.',
                        $country,
                        $isDigital ? '§ 3a Abs. 5 UStG' : '§ 3c UStG',
                    );
                }
            }

            return TaxResult::zeroRated(
                mechanism: TaxResult::MECHANISM_SMALL_BUSINESS,
                reason: (string) $this->cfg('texts.'.$textKey, self::DEFAULTS['texts'][$textKey]),
                legalBasis: $this->legalBasis($textKey),
                productHandle: $productHandle,
                // Not needed for the decision, but the invoice is read years later and the
                // class it covered is part of what happened.
                productClass: $this->productClassFor($productHandle),
                buyerCountry: $country,
                placeOfSupplyCountry: $placeOfSupply,
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
            // Reaching this branch means the confirmation did not hold: no verdict was
            // handed in, or it was "invalid". The number matched a pattern and nothing
            // more — and a pattern is not evidence that a business exists.
            //
            // Zero-rating it anyway is the failure this whole ticket is about. It does
            // not look like a failure: the document comes out with the prescribed
            // § 14a note and a plausible-looking number, and the only sign that nobody
            // ever asked is a sentence in the notes saying the format was checked.
            // A tax office reading that document reads a claim the seller cannot back.
            //
            // So it is refused rather than guessed, the same way a missing country and
            // a missing tax class are refused. Switching `tax.vat_id_check.enabled`
            // off restores the old behaviour for an installation that has decided to
            // live with a format check — the choice becomes explicit instead of being
            // the default nobody noticed.
            if ($this->vatIdConfirmationRequired()) {
                return TaxResult::undetermined(
                    code: 'vat_id_unconfirmed',
                    reason: sprintf(
                        'The VAT ID "%s" was never confirmed with the issuing service, so this '
                        .'supply cannot be zero-rated as reverse charge. Only its format was '
                        .'checked. Confirm it (see Support\BuyerAdmission) or turn the '
                        .'confirmation off in tax.vat_id_check.enabled and accept a document '
                        .'that stands on a pattern.',
                        (string) $vatId,
                    ),
                    productHandle: $productHandle,
                    productClass: $productClass,
                    buyerCountry: $country,
                    isDigital: $isDigital,
                    pricesIncludeTax: $pricesIncludeTax,
                    notes: $notes,
                );
            }

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
    /**
     * The two zones that exist outside the seller's own country: `eu-b2b` and
     * `third-country-b2b`. Null when this is neither, and the ordinary path answers.
     *
     * Deliberately ahead of the product class. The rate here is zero whatever class
     * the product sits in — the supply is not taxable in Germany at all — so
     * refusing for a missing class would refuse a case that has no rate to get
     * wrong. The class is still read and stored, because the invoice is read years
     * later and what it covered is part of what happened.
     *
     * Also ahead of the exemption branch, which reverses the ordering decision made
     * on 25.08. for this one case: an exemption is a German rule, and a supply whose
     * place is abroad has no German rule to be exempt from. Nothing is lost — both
     * paths arrive at no tax; what changes is the sentence, and § 14a Abs. 1 UStG
     * prescribes which sentence.
     */
    private function crossBorderBusiness(
        string $productHandle,
        ?string $buyerCountry,
        ?string $buyerVatId,
        bool $isDigital,
        ?VatIdStatus $vatIdStatus,
        bool $buyerIsBusiness,
    ): ?TaxResult {
        $country = $this->normalizeCountry($buyerCountry);
        $merchantCountry = $this->normalizeCountry((string) $this->cfg('merchant_country', 'DE'));

        if ($country === null || $country === $merchantCountry) {
            return null;
        }

        $vatId = $this->normalizeVatId($buyerVatId);
        $notes = [];
        $productClass = $this->productClassFor($productHandle);
        $pricesIncludeTax = $this->cfg('prices_include_tax');
        $pricesIncludeTax = $pricesIncludeTax === null ? null : (bool) $pricesIncludeTax;

        if ($this->isEuMemberState($country)) {
            // Three separate conditions, and every one of them has to hold. A
            // confirmed number for a different country, or a verdict of "invalid",
            // is not evidence of a business — it is evidence of the opposite.
            if ($vatId === null || $vatIdStatus === null || ! $vatIdStatus->permitsReverseCharge()) {
                return null;
            }

            if (! $this->isPlausibleVatId($vatId) || $this->vatIdCountry($vatId) !== $country) {
                return null;
            }

            if ($this->cfg('merchant_vat_id') === null) {
                $notes[] = 'The seller\'s own VAT ID is missing (tax.merchant_vat_id). § 14a Abs. 1 UStG '
                    .'wants both numbers on the document, and without it this invoice is incomplete.';
            }

            $notes[] = $vatIdStatus === VatIdStatus::Valid
                ? sprintf('The buyer\'s VAT ID %s was confirmed before the invoice was written.', $vatId)
                : sprintf(
                    'The buyer\'s VAT ID %s could not be confirmed — the service was unreachable. The '
                    .'invoice says so, and the check is outstanding.',
                    $vatId,
                );

            // § 18a Abs. 4 UStG exempts a § 19 seller from the recapitulative
            // statement in as many words. Naming it only for the seller it applies to
            // keeps the notes free of a duty this one does not have.
            if (! (bool) $this->cfg('small_business.enabled', false)) {
                $notes[] = 'This turnover has to appear in the recapitulative statement (ZM, § 18a UStG).';
            }

            if ($isDigital) {
                return TaxResult::zeroRated(
                    mechanism: TaxResult::MECHANISM_REVERSE_CHARGE,
                    reason: $this->bilingual('reverse_charge'),
                    legalBasis: $this->legalBasis('reverse_charge'),
                    reverseCharge: true,
                    productHandle: $productHandle,
                    productClass: $productClass,
                    buyerCountry: $country,
                    placeOfSupplyCountry: $country,
                    isDigital: true,
                    pricesIncludeTax: $pricesIncludeTax,
                    notes: $notes,
                );
            }

            // Goods rather than a service: same amount, different provision, different
            // note. `reverseCharge` stays false — the liability does not shift under
            // § 13b; the buyer taxes an intra-community acquisition instead.
            return TaxResult::zeroRated(
                mechanism: TaxResult::MECHANISM_INTRA_COMMUNITY_SUPPLY,
                reason: $this->bilingual('intra_community_supply'),
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
                    .'(Gelangensbestätigung). This class does not check for it.',
                ],
            );
        }

        // Outside the EU there is no register to ask, so the evidence is the
        // buyer's own declaration together with the company name the checkout
        // insisted on. Without the declaration this is not a business as far as
        // anything here can tell, and the ordinary path answers instead.
        if (! $buyerIsBusiness) {
            return null;
        }

        if ($vatId !== null) {
            $notes[] = sprintf('The buyer gave a tax number (%s). Outside the EU it was not verified.', $vatId);
        }

        if ($isDigital) {
            return TaxResult::zeroRated(
                mechanism: TaxResult::MECHANISM_OUTSIDE_SCOPE,
                reason: $this->bilingual('outside_scope'),
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
                        'No German VAT, but possibly a duty to register in %s (UK VAT, US sales tax '
                        .'and so on). That is the buyer\'s side, and this class does not check it.',
                        $country,
                    ),
                ],
            );
        }

        return TaxResult::zeroRated(
            mechanism: TaxResult::MECHANISM_EXPORT,
            reason: $this->bilingual('export'),
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

    /**
     * Does this installation expect a VAT ID to be confirmed before it counts?
     *
     * Read from the same switch the confirmation service is configured under, so
     * the two cannot disagree: an installation that has turned the lookup off is
     * an installation that has accepted a format check, and one that has it on has
     * not accepted anything less.
     */
    private function vatIdConfirmationRequired(): bool
    {
        return (bool) $this->cfg('vat_id_check.enabled', true);
    }

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

    /**
     * The prescribed German sentence, followed by its English companion.
     *
     * In that order and never the other way round: § 14a Abs. 1 UStG names the
     * German wording, so an English-first document would be missing a mandatory
     * particular. Configuring the English half as an empty string leaves the
     * German sentence exactly as it was.
     */
    private function bilingual(string $key): string
    {
        $german = (string) $this->cfg('texts.'.$key, self::DEFAULTS['texts'][$key] ?? '');
        $english = $this->cfg('texts_en.'.$key, self::DEFAULTS['texts_en'][$key] ?? '');
        $english = is_string($english) ? trim($english) : '';

        return $english === '' ? $german : trim($german).' '.$english;
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
