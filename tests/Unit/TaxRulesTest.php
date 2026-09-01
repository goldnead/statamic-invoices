<?php

declare(strict_types=1);

use Goldnead\Invoices\Support\TaxResult;
use Goldnead\Invoices\Support\TaxRules;

/*
|--------------------------------------------------------------------------
| Which VAT rate applies to a line, and why
|--------------------------------------------------------------------------
|
| One dimension of the ticket per block, then the combinations that could
| contradict each other. The point of most of these is not that a rate comes
| out but that the *reason* comes out with it, and that nothing is invented
| when the config is silent.
|
*/

if (! function_exists('taxTestConfig')) {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function taxTestConfig(array $overrides = []): array
    {
        return array_replace([
            'merchant_country' => 'DE',
            'merchant_vat_id' => 'DE123456789',
            'prices_include_tax' => false,
            'product_classes' => [
                'cw-kurs' => 'standard',
                'chorwerk-noten' => 'reduced',
                'einzelunterricht' => 'teaching',
                'ungeklaert' => 'mystery',
                'gratis' => 'freebie',
            ],
            'exemptions' => [
                'teaching' => [
                    'reason' => 'Steuerfrei nach § 4 Nr. 20 Buchst. a UStG.',
                    'legal_basis' => '§ 4 Nr. 20 Buchst. a UStG',
                    'domestic_only' => true,
                ],
                // configured, but nobody wrote down why — see the "no reason" test
                'mystery' => ['legal_basis' => '§ 4 Nr. ?? UStG'],
            ],
            'zones' => [
                'de' => ['countries' => ['DE'], 'rates' => [
                    'standard' => 1900,
                    'reduced' => 700,
                    'freebie' => 0,
                ]],
                'at' => ['countries' => ['AT'], 'rates' => ['standard' => 2000, 'reduced' => 1000]],
            ],
        ], $overrides);
    }
}

if (! function_exists('taxRules')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function taxRules(array $overrides = []): TaxRules
    {
        return new TaxRules(taxTestConfig($overrides));
    }
}

// ── 1. Tax class on the product ──────────────────────────────────────────────

it('charges the standard rate on a course sold at home', function () {
    $result = taxRules()->resolve('cw-kurs', 'DE', null, true);

    expect($result->rateBasisPoints)->toBe(1900)
        ->and($result->productClass)->toBe('standard')
        ->and($result->zone)->toBe('de')
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_STANDARD)
        ->and($result->reverseCharge)->toBeFalse()
        ->and($result->reason)->toBe('Umsatzsteuer 19 %.');
});

it('charges the reduced rate on sheet music', function () {
    $result = taxRules()->resolve('chorwerk-noten', 'DE', null, false);

    expect($result->rateBasisPoints)->toBe(700)
        ->and($result->reason)->toBe('Umsatzsteuer 7 %.');
});

it('carries an exemption with the ground it rests on', function () {
    $result = taxRules()->resolve('einzelunterricht', 'DE', null, false);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_EXEMPT)
        ->and($result->reason)->toContain('§ 4 Nr. 20 Buchst. a UStG')
        ->and($result->legalBasis)->toBe('§ 4 Nr. 20 Buchst. a UStG');
});

it('refuses an exemption that never says why', function () {
    $result = taxRules()->resolve('ungeklaert', 'DE', null, false);

    expect($result->isDetermined())->toBeFalse()
        ->and($result->code)->toBe('exemption_without_reason');
});

// ── No rule found: undetermined, never 19 % ──────────────────────────────────

it('does not invent a rate for a product it has never heard of', function () {
    $result = taxRules()->resolve('unbekanntes-produkt', 'DE', null, true);

    expect($result->rateBasisPoints)->toBeNull()
        ->and($result->isDetermined())->toBeFalse()
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_UNDETERMINED)
        ->and($result->code)->toBe('unknown_product_class');
});

it('does not invent a rate for a country with no zone', function () {
    $result = taxRules(['oss' => ['destination_taxation' => true]])
        ->resolve('cw-kurs', 'FI', null, true);

    expect($result->rateBasisPoints)->toBeNull()
        ->and($result->code)->toBe('no_zone_for_country');
});

it('does not fall back when the zone has no rate for that class', function () {
    $result = taxRules(['oss' => ['destination_taxation' => true]])
        ->resolve('gratis', 'AT', null, true);

    expect($result->rateBasisPoints)->toBeNull()
        ->and($result->code)->toBe('no_rate_for_product_class');
});

it('refuses to print an undetermined result as a rate', function () {
    $result = taxRules()->resolve('unbekanntes-produkt', 'DE', null, true);

    expect(fn () => $result->requireRateBasisPoints())->toThrow(DomainException::class);
    expect(fn () => $result->ratePercent())->toThrow(DomainException::class);
    expect(fn () => $result->split(10000))->toThrow(DomainException::class);
});

it('flags a zone configured at zero without a ground', function () {
    $result = taxRules()->resolve('gratis', 'DE', null, true);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->isZeroRated())->toBeTrue()
        ->and($result->isTaxable())->toBeFalse()
        ->and(implode(' ', $result->notes))->toContain('without naming a ground');
});

// ── The country can be missing ───────────────────────────────────────────────

it('does not assume Germany when no country was stored', function () {
    $result = taxRules()->resolve('cw-kurs', null, null, true);

    expect($result->rateBasisPoints)->toBeNull()
        ->and($result->code)->toBe('missing_country')
        ->and($result->reason)->toContain('will not be assumed');
});

it('treats an empty string like a missing country', function () {
    expect(taxRules()->resolve('cw-kurs', '   ', null, true)->code)->toBe('missing_country');
});

it('assumes a country only when the operator said so', function () {
    $result = taxRules(['assume_country_when_missing' => 'DE'])->resolve('cw-kurs', null, null, true);

    expect($result->rateBasisPoints)->toBe(1900)
        ->and($result->buyerCountry)->toBe('DE')
        ->and(implode(' ', $result->notes))->toContain('was assumed');
});

it('rejects a country that is not a country code, without falling back to the assumption', function () {
    $result = taxRules(['assume_country_when_missing' => 'DE'])
        ->resolve('cw-kurs', 'Deutschland', null, true);

    expect($result->rateBasisPoints)->toBeNull()
        ->and($result->code)->toBe('invalid_country');
});

it('takes a lowercase country code', function () {
    expect(taxRules()->resolve('cw-kurs', 'de', null, true)->rateBasisPoints)->toBe(1900);
});

// ── 4. VAT ID and reverse charge ─────────────────────────────────────────────

it('shifts the liability for a digital supply to an EU business', function () {
    $result = taxRules()->resolve('cw-kurs', 'AT', 'ATU12345678', true);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->reverseCharge)->toBeTrue()
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_REVERSE_CHARGE)
        ->and($result->reason)->toBe('Steuerschuldnerschaft des Leistungsempfängers.')
        ->and($result->legalBasis)->toContain('§ 14a Abs. 5 UStG');
});

it('does not shift the liability inside Germany, VAT ID or not', function () {
    $result = taxRules()->resolve('cw-kurs', 'DE', 'DE123456789', true);

    expect($result->rateBasisPoints)->toBe(1900)
        ->and($result->reverseCharge)->toBeFalse()
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_STANDARD);
});

it('calls goods to an EU business an intra-community supply, not reverse charge', function () {
    $result = taxRules()->resolve('chorwerk-noten', 'AT', 'ATU12345678', false);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_INTRA_COMMUNITY_SUPPLY)
        ->and($result->reverseCharge)->toBeFalse()
        ->and($result->reason)->toBe('Steuerfreie innergemeinschaftliche Lieferung.')
        ->and(implode(' ', $result->notes))->toContain('Gelangensbestätigung');
});

it('charges tax when the VAT ID is malformed, and says so', function () {
    $result = taxRules()->resolve('cw-kurs', 'AT', 'ATU123', true);

    expect($result->rateBasisPoints)->toBe(1900)
        ->and($result->reverseCharge)->toBeFalse()
        ->and(implode(' ', $result->notes))->toContain('matches no known format');
});

it('refuses to choose when VAT ID and country contradict each other', function () {
    $result = taxRules()->resolve('cw-kurs', 'FR', 'ATU12345678', true);

    expect($result->rateBasisPoints)->toBeNull()
        ->and($result->code)->toBe('vat_id_country_mismatch');
});

it('notes the missing seller VAT ID on a reverse charge line', function () {
    $result = taxRules(['merchant_vat_id' => null])->resolve('cw-kurs', 'AT', 'ATU12345678', true);

    expect($result->reverseCharge)->toBeTrue()
        ->and(implode(' ', $result->notes))->toContain('§ 14a UStG');
});

it('reads a Greek VAT ID under its EL prefix', function () {
    $result = taxRules()->resolve('cw-kurs', 'GR', 'EL123456789', true);

    expect($result->reverseCharge)->toBeTrue();
});

it('checks VAT ID formats without pretending to have asked VIES', function () {
    $rules = taxRules();

    expect($rules->isPlausibleVatId('ATU12345678'))->toBeTrue()
        ->and($rules->isPlausibleVatId('DE 123 456 789'))->toBeTrue()
        ->and($rules->isPlausibleVatId('NL123456789B01'))->toBeTrue()
        ->and($rules->isPlausibleVatId('DE12345678'))->toBeFalse()
        ->and($rules->isPlausibleVatId('CHE123456789'))->toBeFalse()
        ->and($rules->isPlausibleVatId('XX123456789'))->toBeFalse()
        ->and($rules->isPlausibleVatId(''))->toBeFalse();
});

// ── 5. Physical against digital ──────────────────────────────────────────────

it('leaves a digital supply to a third-country consumer out of German VAT', function () {
    $result = taxRules()->resolve('cw-kurs', 'US', null, true);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_OUTSIDE_SCOPE)
        ->and($result->reason)->toContain('Nicht im Inland steuerbar')
        ->and(implode(' ', $result->notes))->toContain('registration duty');
});

it('treats goods to a third country as an export, with its own ground', function () {
    $result = taxRules()->resolve('chorwerk-noten', 'US', null, false);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_EXPORT)
        ->and($result->reason)->toBe('Steuerfreie Ausfuhrlieferung.')
        ->and($result->legalBasis)->toContain('§ 6 UStG')
        ->and(implode(' ', $result->notes))->toContain('proof of export');
});

it('separates a digital EU consumer sale from a third-country one', function () {
    $eu = taxRules()->resolve('cw-kurs', 'AT', null, true);
    $third = taxRules()->resolve('cw-kurs', 'US', null, true);

    expect($eu->isTaxable())->toBeTrue()
        ->and($third->isTaxable())->toBeFalse();
});

// ── 6. The OSS switch (the threshold itself stays out) ───────────────────────

it('uses the seller rate for an EU consumer while the switch is off', function () {
    $result = taxRules()->resolve('cw-kurs', 'AT', null, true);

    expect($result->rateBasisPoints)->toBe(1900)
        ->and($result->zone)->toBe('de')
        ->and($result->placeOfSupplyCountry)->toBe('DE')
        ->and($result->reason)->toBe('Umsatzsteuer 19 % (Leistungsort DE).')
        ->and(implode(' ', $result->notes))->toContain('10.000');
});

it('uses the recipient rate for an EU consumer once the switch is on', function () {
    $result = taxRules(['oss' => ['destination_taxation' => true]])
        ->resolve('cw-kurs', 'AT', null, true);

    expect($result->rateBasisPoints)->toBe(2000)
        ->and($result->zone)->toBe('at')
        ->and($result->placeOfSupplyCountry)->toBe('AT')
        ->and($result->reason)->toBe('Umsatzsteuer 20 % (Leistungsort AT).');
});

it('applies the same switch to physical distance sales', function () {
    $result = taxRules(['oss' => ['destination_taxation' => true]])
        ->resolve('chorwerk-noten', 'AT', null, false);

    expect($result->rateBasisPoints)->toBe(1000)
        ->and($result->zone)->toBe('at');
});

// ── 2. Zones and the placeholder ─────────────────────────────────────────────

it('falls to the placeholder zone for countries nobody listed', function () {
    $result = taxRules([
        'oss' => ['destination_taxation' => true],
        'zones' => [
            'de' => ['countries' => ['DE'], 'rates' => ['standard' => 1900, 'reduced' => 700]],
            'rest' => ['countries' => ['*'], 'rates' => ['standard' => 2100, 'reduced' => 900]],
        ],
    ])->resolve('cw-kurs', 'NL', null, true);

    expect($result->rateBasisPoints)->toBe(2100)
        ->and($result->zone)->toBe('rest');
});

it('lets a named country beat the placeholder whatever the order', function () {
    $result = taxRules([
        'oss' => ['destination_taxation' => true],
        'zones' => [
            'rest' => ['countries' => ['*'], 'rates' => ['standard' => 2100]],
            'at' => ['countries' => ['AT'], 'rates' => ['standard' => 2000]],
        ],
    ])->resolve('cw-kurs', 'AT', null, true);

    expect($result->zone)->toBe('at')
        ->and($result->rateBasisPoints)->toBe(2000);
});

// ── 3. Gross or net ──────────────────────────────────────────────────────────

it('splits a net price by adding the tax', function () {
    $result = taxRules()->resolve('cw-kurs', 'DE', null, true);

    expect($result->split(10000))->toBe(['net' => 10000, 'tax' => 1900, 'gross' => 11900]);
});

it('splits a gross price by taking the tax out of it', function () {
    $result = taxRules(['prices_include_tax' => true])->resolve('cw-kurs', 'DE', null, true);

    expect($result->pricesIncludeTax)->toBeTrue()
        ->and($result->split(11900))->toBe(['net' => 10000, 'tax' => 1900, 'gross' => 11900]);
});

it('rounds cents commercially and mirrors that on a credit note', function () {
    $result = taxRules()->resolve('cw-kurs', 'DE', null, true);

    expect($result->taxOnNet(999))->toBe(190)
        ->and($result->taxOnNet(-999))->toBe(-190)
        ->and($result->split(-10000))->toBe(['net' => -10000, 'tax' => -1900, 'gross' => -11900]);
});

it('leaves nothing to split on a zero-rated line', function () {
    $result = taxRules()->resolve('cw-kurs', 'AT', 'ATU12345678', true);

    expect($result->split(10000))->toBe(['net' => 10000, 'tax' => 0, 'gross' => 10000]);
});

// ── 7. The small business scheme suspends the rest ───────────────────────────

it('shows no tax at all while § 19 is on', function () {
    $result = taxRules(['small_business' => ['enabled' => true]])
        ->resolve('cw-kurs', 'DE', null, true);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_SMALL_BUSINESS)
        ->and($result->reason)->toBe('Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.')
        ->and($result->legalBasis)->toBe('§ 19 UStG');
});

it('answers under § 19 even when nothing else is configured', function () {
    $result = (new TaxRules(['small_business' => ['enabled' => true]]))
        ->resolve('produkt-das-niemand-kennt', null, null, true);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->isDetermined())->toBeTrue();
});

it('suppresses reverse charge under § 19 but says that it did', function () {
    $result = taxRules(['small_business' => ['enabled' => true]])
        ->resolve('cw-kurs', 'AT', 'ATU12345678', true);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->reverseCharge)->toBeFalse()
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_SMALL_BUSINESS)
        ->and(implode(' ', $result->notes))->toContain('Cross-border B2B case');
});

// ── § 19 meets a consumer in another member state ────────────────────────────

it('warns about a consumer abroad once the seller is above the threshold without the EU scheme', function () {
    $result = taxRules(['small_business' => ['enabled' => true, 'eu_threshold_mode' => 'above']])
        ->resolve('cw-kurs', 'AT', null, true);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_SMALL_BUSINESS)
        ->and($result->reason)->toBe('Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.')
        ->and(implode(' ', $result->notes))->toContain('Verbraucher in AT')
        ->and(implode(' ', $result->notes))->toContain('§ 3a Abs. 5 UStG')
        ->and(implode(' ', $result->notes))->toContain('§ 19a');
});

it('names § 3c rather than § 3a for goods to that consumer', function () {
    $result = taxRules(['small_business' => ['enabled' => true, 'eu_threshold_mode' => 'above']])
        ->resolve('chorwerk-noten', 'AT', null, false);

    expect(implode(' ', $result->notes))->toContain('§ 3c UStG')
        ->and(implode(' ', $result->notes))->not->toContain('§ 3a Abs. 5');
});

it('cites the EU small business scheme instead of § 19 when the seller uses it', function () {
    $result = taxRules(['small_business' => ['enabled' => true, 'eu_scheme' => true, 'eu_threshold_mode' => 'above']])
        ->resolve('cw-kurs', 'AT', null, true);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_SMALL_BUSINESS)
        ->and($result->reason)->toBe('Steuerfrei nach der EU-Kleinunternehmerregelung, § 19a UStG.')
        ->and($result->legalBasis)->toContain('§ 19a UStG')
        ->and($result->placeOfSupplyCountry)->toBe('AT')
        ->and($result->notes)->toBe([]);
});

it('leaves a consumer abroad at § 19 while the seller is below the threshold', function () {
    $default = taxRules(['small_business' => ['enabled' => true]])
        ->resolve('cw-kurs', 'AT', null, true);
    $explicit = taxRules(['small_business' => ['enabled' => true, 'eu_threshold_mode' => 'below', 'eu_scheme' => true]])
        ->resolve('cw-kurs', 'AT', null, true);

    foreach ([$default, $explicit] as $result) {
        expect($result->rateBasisPoints)->toBe(0)
            ->and($result->reason)->toBe('Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.')
            ->and($result->legalBasis)->toBe('§ 19 UStG')
            ->and($result->placeOfSupplyCountry)->toBeNull()
            ->and($result->notes)->toBe([]);
    }
});

it('keeps the B2B warning, and only that one, for a business abroad above the threshold', function () {
    $result = taxRules(['small_business' => ['enabled' => true, 'eu_threshold_mode' => 'above']])
        ->resolve('cw-kurs', 'AT', 'ATU12345678', true);

    expect($result->reason)->toBe('Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.')
        ->and($result->notes)->toHaveCount(1)
        ->and($result->notes[0])->toContain('Cross-border B2B case');
});

it('says nothing extra to a domestic consumer, whatever the threshold switch says', function () {
    $result = taxRules(['small_business' => ['enabled' => true, 'eu_threshold_mode' => 'above']])
        ->resolve('cw-kurs', 'DE', null, true);

    expect($result->reason)->toBe('Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.')
        ->and($result->notes)->toBe([]);
});

it('does not warn about a third-country consumer under the EU threshold switch', function () {
    $result = taxRules(['small_business' => ['enabled' => true, 'eu_threshold_mode' => 'above']])
        ->resolve('cw-kurs', 'US', null, true);

    expect($result->notes)->toBe([]);
});

it('refuses a threshold mode it does not know', function () {
    expect(fn () => new TaxRules(['small_business' => ['enabled' => true, 'eu_threshold_mode' => 'abvoe']]))
        ->toThrow(InvalidArgumentException::class, 'eu_threshold_mode');
});

it('beats an exemption too, and keeps the § 19 wording', function () {
    $result = taxRules(['small_business' => ['enabled' => true]])
        ->resolve('einzelunterricht', 'DE', null, false);

    expect($result->reason)->toContain('§ 19 UStG')
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_SMALL_BUSINESS);
});

// ── Exemptions across the border ─────────────────────────────────────────────

it('will not carry a domestic exemption into another country by itself', function () {
    $result = taxRules()->resolve('einzelunterricht', 'AT', null, false);

    expect($result->rateBasisPoints)->toBeNull()
        ->and($result->code)->toBe('exemption_outside_domestic');
});

it('carries it abroad when the operator has said it applies there', function () {
    $result = taxRules([
        'exemptions' => [
            'teaching' => [
                'reason' => 'Steuerfrei nach § 4 Nr. 20 Buchst. a UStG.',
                'domestic_only' => false,
            ],
        ],
    ])->resolve('einzelunterricht', 'AT', null, false);

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->mechanism)->toBe(TaxResult::MECHANISM_EXEMPT);
});

// ── The interface itself ─────────────────────────────────────────────────────

it('answers through the static entry point with named arguments', function () {
    $result = TaxRules::for(
        productHandle: 'cw-kurs',
        buyerCountry: 'AT',
        buyerVatId: 'ATU12345678',
        isDigital: true,
        config: taxTestConfig(),
    );

    expect($result->rateBasisPoints)->toBe(0)
        ->and($result->reverseCharge)->toBeTrue();
});

it('says nothing at all when handed an empty config', function () {
    $result = (new TaxRules)->resolve('cw-kurs', 'DE', null, true);

    expect($result->isDetermined())->toBeFalse()
        ->and($result->code)->toBe('unknown_product_class');
});

it('hands the whole reasoning over for storage on the invoice', function () {
    $stored = taxRules()->resolve('cw-kurs', 'AT', 'ATU12345678', true)->toArray();

    expect($stored)->toHaveKeys([
        'rate_basis_points', 'reason', 'reverse_charge', 'mechanism', 'legal_basis',
        'code', 'product_handle', 'product_class', 'zone', 'buyer_country',
        'place_of_supply_country', 'is_digital', 'prices_include_tax', 'notes',
    ])->and($stored['rate_basis_points'])->toBe(0)
        ->and($stored['mechanism'])->toBe('reverse_charge')
        ->and($stored['buyer_country'])->toBe('AT');
});

it('formats an odd rate for the invoice line', function () {
    $result = taxRules([
        'zones' => ['de' => ['countries' => ['DE'], 'rates' => ['standard' => 250]]],
    ])->resolve('cw-kurs', 'DE', null, false);

    expect($result->reason)->toBe('Umsatzsteuer 2,5 %.')
        ->and($result->ratePercent())->toBe(2.5);
});

it('will not take a rate that is not whole basis points', function () {
    $result = taxRules([
        'zones' => ['de' => ['countries' => ['DE'], 'rates' => ['standard' => '19']]],
    ])->resolve('cw-kurs', 'DE', null, true);

    expect($result->rateBasisPoints)->toBeNull()
        ->and($result->code)->toBe('no_rate_for_product_class')
        ->and($result->reason)->toContain('not an integer');
});

// ── Hardening against a config nobody proof-read ─────────────────────────────

it('refuses a rate typed as percent instead of basis points', function () {
    $result = taxRules([
        'zones' => ['de' => ['countries' => ['DE'], 'rates' => ['standard' => 19]]],
    ])->resolve('cw-kurs', 'DE', null, true);

    expect($result->rateBasisPoints)->toBeNull()
        ->and($result->code)->toBe('implausible_rate')
        ->and($result->reason)->toContain('basis points');
});

it('refuses a negative and an absurd rate', function () {
    $negative = taxRules([
        'zones' => ['de' => ['countries' => ['DE'], 'rates' => ['standard' => -500]]],
    ])->resolve('cw-kurs', 'DE', null, true);

    $absurd = taxRules([
        'zones' => ['de' => ['countries' => ['DE'], 'rates' => ['standard' => 19000]]],
    ])->resolve('cw-kurs', 'DE', null, true);

    expect($negative->code)->toBe('implausible_rate')
        ->and($absurd->code)->toBe('implausible_rate');
});

it('does not let a misspelt switch turn § 19 off in silence', function () {
    expect(fn () => new TaxRules(['small_business' => ['enable' => true]]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new TaxRules(['small_buisness' => ['enabled' => true]]))
        ->toThrow(InvalidArgumentException::class);
});

it('takes the boolean shorthand for a switch', function () {
    $result = (new TaxRules(['small_business' => true]))->resolve('cw-kurs', 'DE', null, true);

    expect($result->mechanism)->toBe(TaxResult::MECHANISM_SMALL_BUSINESS)
        ->and($result->rateBasisPoints)->toBe(0);

    $off = taxRules(['oss' => true])->resolve('cw-kurs', 'AT', null, true);

    expect($off->rateBasisPoints)->toBe(2000);
});

// ── Gaps the reviewer found ─────────────────────────────────────────────────

it('uses the seller rate for physical distance sales while the switch is off', function () {
    $result = taxRules()->resolve('chorwerk-noten', 'AT', null, false);

    expect($result->rateBasisPoints)->toBe(700)
        ->and($result->zone)->toBe('de')
        ->and($result->placeOfSupplyCountry)->toBe('DE');
});

it('mirrors the gross split on a credit note too', function () {
    $result = taxRules(['prices_include_tax' => true])->resolve('cw-kurs', 'DE', null, true);

    expect($result->split(-11900))->toBe(['net' => -10000, 'tax' => -1900, 'gross' => -11900])
        ->and($result->split(-999))->toBe(['net' => -839, 'tax' => -160, 'gross' => -999])
        ->and($result->split(999))->toBe(['net' => 839, 'tax' => 160, 'gross' => 999]);
});

it('will not carry a domestic exemption into a third country either', function () {
    $result = taxRules()->resolve('einzelunterricht', 'US', null, false);

    expect($result->rateBasisPoints)->toBeNull()
        ->and($result->code)->toBe('exemption_outside_domestic');
});

it('says when an exemption displaced the shift of liability', function () {
    $result = taxRules([
        'exemptions' => [
            'teaching' => [
                'reason' => 'Steuerfrei nach § 4 Nr. 20 Buchst. a UStG.',
                'domestic_only' => false,
            ],
        ],
    ])->resolve('einzelunterricht', 'AT', 'ATU12345678', true);

    expect($result->mechanism)->toBe(TaxResult::MECHANISM_EXEMPT)
        ->and($result->reverseCharge)->toBeFalse()
        ->and(implode(' ', $result->notes))->toContain('reverse-charge branch was never reached');
});

it('keeps the product class on the record under § 19', function () {
    $stored = taxRules(['small_business' => ['enabled' => true]])
        ->resolve('chorwerk-noten', 'DE', null, false)
        ->toArray();

    expect($stored['product_class'])->toBe('reduced')
        ->and($stored['buyer_country'])->toBe('DE');
});

it('never lets "no answer" read as "no tax"', function () {
    $undetermined = taxRules()->resolve('unbekanntes-produkt', 'DE', null, true);
    $free = taxRules()->resolve('einzelunterricht', 'DE', null, false);

    expect($undetermined->isTaxable())->toBeFalse()
        ->and($undetermined->isZeroRated())->toBeFalse()
        ->and($undetermined->isDetermined())->toBeFalse()
        ->and($free->isTaxable())->toBeFalse()
        ->and($free->isZeroRated())->toBeTrue()
        ->and($free->isDetermined())->toBeTrue();
});
