<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Exceptions\DetailsMissing;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * What has to be on an invoice, checked before one is written.
 *
 * The order matters more than it looks: an invoice cannot be corrected, only
 * reversed and reissued. So a missing detail has to stop the writing, not be
 * discovered afterwards on a document that is already evidence.
 *
 * The €250 line is § 33 UStDV. Below it a Kleinbetragsrechnung needs no
 * recipient address — which is the ordinary case for a digital product bought
 * by an email address and nothing else, and demanding one there would refuse
 * invoices the law is perfectly happy with.
 */
class MandatoryDetailsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'kurs' => ['name' => 'Kurs', 'amount_cent' => 10000, 'digital' => true],
        ]);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
    }

    private function zahlung(int $cent, array $werte = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'fake', 'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs', 'amount_cent' => $cent, 'currency' => 'EUR',
            'status' => Payment::STATUS_PAID, 'email' => 'wer@example.com',
            'name' => 'Bärbel Öztürk-Weiß', 'country' => 'DE', 'paid_at' => now(),
        ], $werte));
    }

    #[Test]
    public function a_small_amount_needs_no_recipient_address(): void
    {
        // Der Normalfall: ein Digitalprodukt, gekauft mit einer Adresse und
        // sonst nichts. § 33 UStDV ist damit zufrieden.
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung(10000));

        $this->assertNotNull($rechnung);
    }

    #[Test]
    public function above_the_threshold_the_address_is_required(): void
    {
        try {
            app(InvoiceWriter::class)->forPayment($this->zahlung(30000));
            $this->fail('eine Rechnung über 250 € ohne Anschrift wurde geschrieben');
        } catch (DetailsMissing $e) {
            $this->assertSame(0, Invoice::count(), 'die unvollständige Rechnung wurde trotzdem geschrieben');
            $this->assertStringContainsString('address', implode(' ', $e->missing));
        }
    }

    #[Test]
    public function above_the_threshold_with_an_address_it_is_written(): void
    {
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung(30000, [
            'meta' => ['address' => "Beispielweg 3\n10115 Berlin"],
        ]));

        $this->assertNotNull($rechnung);
        $this->assertStringContainsString('Beispielweg', (string) $rechnung->buyer_address);
    }

    #[Test]
    public function a_sender_without_details_writes_nothing_at_any_amount(): void
    {
        // Eine Rechnung ohne Absenderangaben ist keine — und das laesst sich
        // hinterher nicht heilen, nur stornieren und neu ausstellen.
        config(['invoices.seller' => ['name' => '', 'address' => '']]);

        $this->expectException(DetailsMissing::class);

        app(InvoiceWriter::class)->forPayment($this->zahlung(1000));
    }

    #[Test]
    public function the_threshold_is_the_sites_to_set(): void
    {
        // Es ist eine Rechtsgroesse, keine Geschmacksfrage — aber sie aendert
        // sich, und eine fest verdrahtete Zahl waere in fuenf Jahren falsch.
        config(['invoices.small_amount_cent' => 5000]);

        $this->expectException(DetailsMissing::class);

        app(InvoiceWriter::class)->forPayment($this->zahlung(6000));
    }
}
