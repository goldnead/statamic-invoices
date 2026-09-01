<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Console\Commands\PendingInvoices;
use Goldnead\Invoices\Facades\Invoices;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * The tax rules' doubts have to reach somebody.
 *
 * `TaxResult::notes` carried them from the start, and nobody read them: the
 * writer took the reason and the mechanism off the result and dropped the rest.
 * The § 19 warning for a consumer in another member state is only worth
 * anything if it lands where a person looks — the log, the invoice row, and
 * the daily `invoices:pending`.
 */
class TaxNotesReachSomeoneTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.products', [
            'kurs' => ['name' => 'Kurs', 'amount_cent' => 24900, 'digital' => true],
        ]);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'small_business' => ['enabled' => true, 'eu_threshold_mode' => 'above'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Wie in WhatLandedInTheWrongSeriesTest: der Test-Kernel findet die
        // per Konvention geladenen Addon-Befehle nicht von selbst.
        $this->app[Kernel::class]->registerCommand($this->app->make(PendingInvoices::class));
    }

    private function zahlungAusOesterreich(string $providerId = 'tr_at'): Payment
    {
        return Payment::create([
            'provider' => 'fake', 'provider_id' => $providerId, 'product' => 'kurs',
            'amount_cent' => 24900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.at', 'country' => 'AT', 'paid_at' => now(),
        ]);
    }

    #[Test]
    public function the_consumer_abroad_warning_is_logged_with_the_payment(): void
    {
        Log::spy();

        $zahlung = $this->zahlungAusOesterreich();

        app(InvoiceWriter::class)->forPayment($zahlung);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($nachricht, $kontext = []) => $nachricht === 'invoices: tax note'
                && ($kontext['payment'] ?? null) === $zahlung->getKey()
                && ($kontext['product'] ?? null) === 'kurs'
                && str_contains((string) ($kontext['note'] ?? ''), 'Verbraucher in AT'))
            ->once();
    }

    #[Test]
    public function the_warning_is_kept_on_the_invoice_row(): void
    {
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlungAusOesterreich());

        $this->assertIsArray($rechnung->meta);
        $this->assertCount(1, $rechnung->meta['tax_notes']);
        $this->assertSame('kurs', $rechnung->meta['tax_notes'][0]['product']);
        $this->assertStringContainsString('§ 3a Abs. 5 UStG', $rechnung->meta['tax_notes'][0]['note']);

        // Und nicht auf dem Dokument: der Hinweis ist fuer den Pruefer, nicht
        // fuer den Kaeufer.
        $this->assertStringNotContainsString('Verbraucher in AT', (string) $rechnung->tax_note);
    }

    #[Test]
    public function the_credit_note_carries_the_notes_of_the_decision_it_reverses(): void
    {
        $zahlung = $this->zahlungAusOesterreich();
        $original = app(InvoiceWriter::class)->forPayment($zahlung);

        $storno = Invoices::creditNoteFor($zahlung);

        $this->assertSame($original->number, $storno->meta['reverses_number']);
        $this->assertSame($original->meta['tax_notes'], $storno->meta['tax_notes']);
    }

    #[Test]
    public function below_the_threshold_nothing_is_logged_and_nothing_is_stored(): void
    {
        config(['invoices.tax.small_business' => ['enabled' => true]]);

        Log::spy();

        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlungAusOesterreich());

        $this->assertNull($rechnung->meta);
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function invoices_pending_shows_the_note_next_to_the_payment(): void
    {
        $zahlung = $this->zahlungAusOesterreich();

        [$code, $ausgabe] = $this->pending();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Zahlung '.$zahlung->id.', kurs: Verbraucher in AT', $ausgabe);
        $this->assertStringContainsString('(nicht versucht)', $ausgabe);
    }

    #[Test]
    public function invoices_pending_shows_it_when_writing_too(): void
    {
        $zahlung = $this->zahlungAusOesterreich();

        [$code, $ausgabe] = $this->pending(['--write' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Zahlung '.$zahlung->id.', kurs: Verbraucher in AT', $ausgabe);
        $this->assertStringContainsString('1 Rechnungen geschrieben.', $ausgabe);
    }

    /**
     * `Artisan::call` statt `$this->artisan()->expectsOutputToContain()`: das
     * prueft je Schreibvorgang, und eine Zusicherung, die an der Mechanik des
     * Pruefwerkzeugs scheitert statt am Befehl, ist keine.
     *
     * @param  array<string, mixed>  $optionen
     * @return array{0: int, 1: string}
     */
    private function pending(array $optionen = []): array
    {
        $code = Artisan::call('invoices:pending', $optionen);

        return [$code, Artisan::output()];
    }
}
