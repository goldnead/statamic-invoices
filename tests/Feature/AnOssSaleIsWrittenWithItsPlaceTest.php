<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Support\TaxResult;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * A consumer in Austria, past the threshold, with the shipped table switched on.
 *
 * The rate is only half of it. The tax report has to say *where* that tax is
 * owed, and a line that only remembers 20 % cannot answer that a year later:
 * 20 % is the Austrian rate and the French one. So every line keeps the place
 * of supply and the mechanism the rules landed on, frozen like the rest.
 */
class AnOssSaleIsWrittenWithItsPlaceTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'merchant_vat_id' => 'DE123456789',
            'prices_include_tax' => true,
            'business_only' => ['enabled' => false, 'require_company' => false],
            'product_classes' => ['kurs' => 'standard'],
            'zones' => ['de' => ['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
            'oss' => ['destination_taxation' => true, 'shipped_rates' => true],
        ]);
    }

    private function zahlung(string $land): Payment
    {
        return Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs',
            'amount_cent' => 12000,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'kaeuferin@example.at',
            'name' => 'Grete Huber',
            'country' => $land,
            'paid_at' => now(),
        ]);
    }

    #[Test]
    public function the_austrian_rate_and_the_austrian_place_are_on_the_line(): void
    {
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung('AT'));
        $zeile = $rechnung->items->first();

        $this->assertSame(2000, $zeile->tax_rate_bp);
        $this->assertSame(2000, $rechnung->tax_cent, '120 € brutto bei 20 % sind 20 € Steuer');
        $this->assertSame('AT', $zeile->place_of_supply);
        $this->assertSame(TaxResult::MECHANISM_STANDARD, $zeile->tax_mechanism);
    }

    #[Test]
    public function a_domestic_line_remembers_its_place_too(): void
    {
        $zeile = app(InvoiceWriter::class)->forPayment($this->zahlung('DE'))->items->first();

        $this->assertSame('DE', $zeile->place_of_supply);
        $this->assertSame(1900, $zeile->tax_rate_bp);
    }

    #[Test]
    public function the_credit_note_reverses_the_same_place(): void
    {
        $zahlung = $this->zahlung('AT');
        $schreiber = app(InvoiceWriter::class);
        $schreiber->forPayment($zahlung);

        $zeile = $schreiber->creditNoteFor($zahlung)->items->first();

        $this->assertSame('AT', $zeile->place_of_supply);
        $this->assertSame(TaxResult::MECHANISM_STANDARD, $zeile->tax_mechanism);
    }
}
