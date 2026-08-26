<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Exceptions\ProductIncomplete;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Catalogue;
use PHPUnit\Framework\Attributes\Test;

/**
 * A payment sold through an offer must become an invoice.
 *
 * This crosses the boundary that nobody crossed. `product()` used to read
 * `config('statamic-payments.products')` directly, which skips every resolver
 * another addon registered with the catalogue — and `statamic-offers` registers
 * one, under the prefix `offer:`. `statamic-funnels` uses an offer for every
 * paid step, so the advertised chain funnel → offer → payment → invoice broke at
 * its last link on any installation using the family as documented.
 *
 * Three shipped addons with three green suites, and the defect lived in the gap
 * between them.
 *
 * The resolver below stands in for `statamic-offers`, which is not installed
 * here. It is deliberately as strict as the real one: it answers only for its
 * own prefix and returns exactly what the real resolver returns — the product's
 * facts with the offer's price and name on top. A stand-in that answered
 * everything would prove only that a lookup happened.
 */
class AnOfferBecomesAnInvoiceTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            // Deliberately NOT the default: if the offer fell through to
            // `default_product_class` the test would pass without the
            // inheritance ever working. The first version of this test did
            // exactly that, and taxHandle() could have been deleted without
            // turning anything red.
            'product_classes' => ['kurs' => 'reduced'],
            'zones' => [
                ['countries' => ['DE'], 'rates' => ['standard' => 1900, 'reduced' => 700]],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Catalogue::forgetResolvers();

        Catalogue::extend(function (string $handle): ?array {
            if (! str_starts_with($handle, 'offer:')) {
                return null;
            }

            if ($handle !== 'offer:fruehling-upsell') {
                return null;
            }

            // What statamic-offers returns since it was fixed: the product's
            // own array, with the offer's name and price in front of it.
            $product = (array) (config('statamic-payments.products')['kurs'] ?? []);
            unset($product['handle']);

            return [
                'name' => 'Frühlings-Upsell',
                'amount_cent' => 4900,
                'offer' => 'fruehling-upsell',
                // The handle of the thing underneath, which the real resolver
                // returns and this stand-in used to leave out. Without it
                // taxHandle() never ran in its interesting branch, and the
                // assertion below was satisfied by the default class instead of
                // by the inheritance it claims to test.
                'product' => 'kurs',
            ] + $product;
        });
    }

    protected function tearDown(): void
    {
        Catalogue::forgetResolvers();

        parent::tearDown();
    }

    private function zahlung(string $produkt): Payment
    {
        return Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => $produkt,
            'amount_cent' => 4900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com',
            'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE',
            'paid_at' => now(),
        ]);
    }

    #[Test]
    public function a_payment_made_through_an_offer_becomes_an_invoice(): void
    {
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung('offer:fruehling-upsell'));

        $this->assertNotNull($rechnung, 'ohne Katalog wirft isDigital() hier ProductIncomplete');
        $this->assertSame(4900, $rechnung->gross_cent);
        // 700, not 1900: the product is reduced-rate and the default is
        // standard, so this number can only come from the tax class being
        // looked up under the *product's* handle.
        $this->assertSame(700, $rechnung->items->first()->tax_rate_bp, 'Steuerklasse vom Produkt geerbt');
        $this->assertSame(1, Invoice::count());
    }

    #[Test]
    public function the_line_is_named_after_the_offer_not_the_product(): void
    {
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung('offer:fruehling-upsell'));

        // The buyer saw "Frühlings-Upsell" and paid €49. An invoice naming the
        // full-price product would not match what they bought.
        $this->assertSame('Frühlings-Upsell', $rechnung->items->first()->name);
    }

    /**
     * The other half: a resolver that names no product underneath.
     *
     * Not every extension has a product behind it, so the fallback to the
     * line's own handle has to keep working — and it decides a tax rate, so it
     * gets pinned rather than assumed.
     */
    #[Test]
    public function a_resolver_without_a_product_underneath_uses_its_own_handle(): void
    {
        Catalogue::forgetResolvers();
        Catalogue::extend(fn (string $handle): ?array => $handle === 'sonderposten'
            ? ['name' => 'Sonderposten', 'amount_cent' => 4900, 'digital' => true]
            : null);

        config()->set('invoices.tax.product_classes', ['sonderposten' => 'reduced']);

        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung('sonderposten'));

        $this->assertSame(700, $rechnung->items->first()->tax_rate_bp);
    }

    #[Test]
    public function an_offer_nobody_can_resolve_still_writes_no_invoice(): void
    {
        // The refusal has to survive the fix. A handle that resolves nowhere is
        // a product whose tax facts are unknown, and guessing one onto an
        // immutable document is exactly what this addon must never do.
        //
        // It refuses by throwing, not by returning null: the caller catches
        // InvoiceNotWritten and `invoices:pending` lists what is waiting and
        // why. A silent null would be the version where nobody finds out.
        $this->expectException(ProductIncomplete::class);

        try {
            app(InvoiceWriter::class)->forPayment($this->zahlung('offer:gibt-es-nicht'));
        } finally {
            $this->assertSame(0, Invoice::count(), 'und kein halbes Dokument bleibt zurueck');
        }
    }
}
