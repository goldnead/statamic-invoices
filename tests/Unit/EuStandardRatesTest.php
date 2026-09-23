<?php

declare(strict_types=1);

use Goldnead\Invoices\Support\EuStandardRates;
use Goldnead\Invoices\Support\TaxResult;
use Goldnead\Invoices\Support\TaxRules;
use Goldnead\Invoices\Support\VatIdStatus;

/*
|--------------------------------------------------------------------------
| The shipped EU standard rates, and the switch that lets them answer
|--------------------------------------------------------------------------
|
| Two promises. An installation that has not opted in behaves exactly as
| before: a consumer in Austria under destination taxation, with no zone for
| Austria, comes back undetermined. An installation that has opted in gets the
| Austrian standard rate from a table that says how old it is.
|
| And the two switches that already decide more than a rate keep deciding it:
| a confirmed VAT ID still shifts the liability, and § 19 still shows no tax.
|
*/

if (! function_exists('ossConfig')) {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function ossConfig(array $overrides = []): array
    {
        return array_replace_recursive([
            'merchant_country' => 'DE',
            'merchant_vat_id' => 'DE123456789',
            'prices_include_tax' => true,
            'product_classes' => ['kurs' => 'standard', 'noten' => 'reduced'],
            'zones' => [
                'de' => ['countries' => ['DE'], 'rates' => ['standard' => 1900, 'reduced' => 700]],
            ],
            'oss' => ['destination_taxation' => true],
        ], $overrides);
    }
}

it('ships a standard rate for every member state, and nothing else', function () {
    $raten = EuStandardRates::RATES;

    expect(array_keys($raten))->toEqualCanonicalizing(TaxRules::EU_MEMBER_STATES);

    foreach ($raten as $land => $satz) {
        // Art. 97 MwStSystRL: at least 15 %. Hungary is the highest at 27 %.
        expect($satz)->toBeInt()->toBeGreaterThanOrEqual(1500)->toBeLessThanOrEqual(2700, $land);
    }

    expect($raten['FI'])->toBe(2550)
        ->and($raten['EE'])->toBe(2400)
        ->and($raten['RO'])->toBe(2100)
        ->and($raten['SK'])->toBe(2300)
        ->and($raten['LU'])->toBe(1700)
        ->and($raten['DE'])->toBe(1900);
});

it('says how old the table is, and where it comes from', function () {
    expect(EuStandardRates::AS_OF)->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and(EuStandardRates::SOURCES)->not->toBeEmpty();
});

it('changes nothing for an installation that has not opted in', function () {
    $ergebnis = (new TaxRules(ossConfig()))->resolve(productHandle: 'kurs', buyerCountry: 'AT', isDigital: true);

    expect($ergebnis->isDetermined())->toBeFalse()
        ->and($ergebnis->code)->toBe('no_zone_for_country');
});

it('taxes a consumer abroad at their country\'s standard rate once the table is switched on', function () {
    $ergebnis = (new TaxRules(ossConfig(['oss' => ['shipped_rates' => true]])))
        ->resolve(productHandle: 'kurs', buyerCountry: 'AT', isDigital: true);

    expect($ergebnis->rateBasisPoints)->toBe(2000)
        ->and($ergebnis->placeOfSupplyCountry)->toBe('AT')
        ->and($ergebnis->zone)->toBe('eu-at')
        ->and($ergebnis->mechanism)->toBe(TaxResult::MECHANISM_STANDARD)
        ->and(implode(' ', $ergebnis->notes))->toContain(EuStandardRates::AS_OF);
});

it('keeps the seller\'s own rate below the threshold, table or not', function () {
    $ergebnis = (new TaxRules(ossConfig(['oss' => ['destination_taxation' => false, 'shipped_rates' => true]])))
        ->resolve(productHandle: 'kurs', buyerCountry: 'FR', isDigital: true);

    expect($ergebnis->rateBasisPoints)->toBe(1900)
        ->and($ergebnis->placeOfSupplyCountry)->toBe('DE');
});

it('lets a zone the operator wrote beat the shipped table', function () {
    $ergebnis = (new TaxRules(ossConfig([
        'oss' => ['shipped_rates' => true],
        'zones' => ['at' => ['countries' => ['AT'], 'rates' => ['standard' => 1300]]],
    ])))->resolve(productHandle: 'kurs', buyerCountry: 'AT', isDigital: true);

    expect($ergebnis->rateBasisPoints)->toBe(1300)
        ->and($ergebnis->zone)->toBe('at');
});

it('lets the shipped table beat a catch-all placeholder, which was only ever a guess', function () {
    $ergebnis = (new TaxRules(ossConfig([
        'oss' => ['shipped_rates' => true],
        'zones' => ['rest' => ['countries' => ['*'], 'rates' => ['standard' => 1900]]],
    ])))->resolve(productHandle: 'kurs', buyerCountry: 'FI', isDigital: true);

    expect($ergebnis->rateBasisPoints)->toBe(2550)
        ->and($ergebnis->zone)->toBe('eu-fi');
});

it('does not invent a reduced rate, because the table only carries standard rates', function () {
    $ergebnis = (new TaxRules(ossConfig(['oss' => ['shipped_rates' => true]])))
        ->resolve(productHandle: 'noten', buyerCountry: 'AT', isDigital: false);

    expect($ergebnis->isDetermined())->toBeFalse()
        ->and($ergebnis->code)->toBe('no_rate_for_product_class');
});

it('applies the table to the class the operator names', function () {
    $ergebnis = (new TaxRules(ossConfig([
        'product_classes' => ['kurs' => 'digital'],
        'zones' => ['de' => ['rates' => ['digital' => 1900]]],
        'oss' => ['shipped_rates' => true, 'shipped_rates_class' => 'digital'],
    ])))->resolve(productHandle: 'kurs', buyerCountry: 'NL', isDigital: true);

    expect($ergebnis->rateBasisPoints)->toBe(2100);
});

it('still shifts the liability for a business with a confirmed VAT ID', function () {
    $ergebnis = (new TaxRules(ossConfig(['oss' => ['shipped_rates' => true]])))->resolve(
        productHandle: 'kurs',
        buyerCountry: 'AT',
        buyerVatId: 'ATU12345678',
        isDigital: true,
        vatIdStatus: VatIdStatus::Valid,
    );

    expect($ergebnis->mechanism)->toBe(TaxResult::MECHANISM_REVERSE_CHARGE)
        ->and($ergebnis->rateBasisPoints)->toBe(0);
});

it('switches everything off for a small business', function () {
    $ergebnis = (new TaxRules(ossConfig([
        'oss' => ['shipped_rates' => true],
        'small_business' => ['enabled' => true],
    ])))->resolve(productHandle: 'kurs', buyerCountry: 'AT', isDigital: true);

    expect($ergebnis->mechanism)->toBe(TaxResult::MECHANISM_SMALL_BUSINESS)
        ->and($ergebnis->rateBasisPoints)->toBe(0);
});

it('refuses a misspelt switch rather than leaving the table off in silence', function () {
    new TaxRules(ossConfig(['oss' => ['shiped_rates' => true]]));
})->throws(InvalidArgumentException::class, 'shiped_rates');

it('ships switched off', function () {
    $config = require __DIR__.'/../../config/invoices.php';

    expect($config['tax']['oss']['shipped_rates'])->toBeFalse();
});
