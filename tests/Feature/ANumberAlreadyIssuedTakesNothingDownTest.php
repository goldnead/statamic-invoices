<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Console\Commands\PendingInvoices;
use Goldnead\Invoices\Exceptions\NumberAlreadyTaken;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Support\NumberSeries;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * A number that is already issued must not take anything down with it.
 *
 * The counter runs per brand and per period, the number is unique across the
 * whole table. Change a prefix after invoices exist — a thing an operator is
 * allowed to do — and the fresh counter hands out a number that is already on a
 * document. The database says no, and until this file existed that "no" was a
 * `UniqueConstraintViolationException`: not an `InvoiceNotWritten`, so it
 * escaped the `PaymentPaid` listener (rolling back a fulfilment the buyer had
 * already been given, and having the provider deliver the webhook again for a
 * problem no retry solves) and it ended `invoices:pending --write` at the first
 * bad row, leaving the untried ones untried.
 *
 * Found on 09.09.2026 in the playground, on a Stripe test purchase, after a
 * brand's prefix was changed.
 */
class ANumberAlreadyIssuedTakesNothingDownTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'zones' => [
                ['countries' => ['DE'], 'rates' => ['standard' => 1900]],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Unter testbench laeuft `bootCommands()` nicht; ohne das hier gaebe es
        // den Befehl in diesem Test nicht, um den es hier geht.
        $this->app[Kernel::class]->registerCommand($this->app->make(PendingInvoices::class));

        // Und `bootEvents()` genauso wenig. Der Weg ueber `PaymentPaid` ist der
        // halbe Gegenstand dieser Datei, ein nicht angehaengter Zuhoerer waere
        // ein Test, der gruen wird, weil nichts passiert.
        $this->app->getProvider(ServiceProvider::class)?->bootEvents();
    }

    /**
     * Eine Nummer, die schon auf einem Dokument steht, waehrend der Zaehler
     * ihrer Reihe bei null steht — genau der Zustand nach einem Vorsilben-Wechsel.
     */
    private function vergebeneNummer(string $nummer): Invoice
    {
        return Invoice::create([
            'brand_id' => 0,
            'number' => $nummer,
            'kind' => Invoice::KIND_INVOICE,
            'issued_at' => Carbon::parse('2026-08-01 09:00'),
            'currency' => 'EUR',
            'net_cent' => 10000,
            'tax_cent' => 1900,
            'gross_cent' => 11900,
        ]);
    }

    private function zahlung(string $bezahltAm, string $providerId): Payment
    {
        return Payment::create([
            'provider' => 'fake',
            'provider_id' => $providerId,
            'product' => 'kurs',
            'amount_cent' => 11900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com',
            'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE',
            'paid_at' => Carbon::parse($bezahltAm),
        ]);
    }

    #[Test]
    public function the_collision_stays_inside_the_listener_and_the_payment_flow_runs_on(): void
    {
        $this->vergebeneNummer('RE2026-08-001');

        Log::spy();

        // Nach unserem Zuhoerer registriert, laeuft also nach ihm. Fliegt bei
        // ihm eine Ausnahme raus, kommt dieser hier nie dran — und genau das ist
        // die Erfuellung, die eine bezahlte Bestellung nicht verlieren darf.
        $danach = false;
        Event::listen(PaymentPaid::class, function () use (&$danach) {
            $danach = true;
        });

        $zahlung = $this->zahlung('2026-08-10 10:00', 'tr_kollision');

        PaymentPaid::dispatch($zahlung);

        $this->assertTrue($danach, 'Der Rest des Zahlungswegs muss weiterlaufen.');
        $this->assertSame(Payment::STATUS_PAID, $zahlung->fresh()->status);
        $this->assertNull(Invoice::query()->where('payment_id', $zahlung->getKey())->first());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($nachricht) => str_contains((string) $nachricht, 'RE2026-08'))
            ->once();
    }

    #[Test]
    public function the_pending_command_names_the_reason_for_the_broken_row(): void
    {
        $this->vergebeneNummer('RE2026-08-001');
        $zahlung = $this->zahlung('2026-08-10 10:00', 'tr_kollision');

        $code = Artisan::call('invoices:pending', ['--write' => true]);
        $ausgabe = Artisan::output();

        $this->assertNotSame(0, $code, 'Ein Lauf, der eine Rechnung nicht schreiben konnte, ist kein stiller Erfolg.');
        $this->assertStringContainsString((string) $zahlung->getKey(), $ausgabe);
        $this->assertStringContainsString('RE2026-08', $ausgabe);
    }

    #[Test]
    public function a_credit_note_runs_into_the_same_wall_and_calls_it_the_same_thing(): void
    {
        // Die Gutschrift zaehlt in der Reihe des Tages, an dem sie geschrieben
        // wird, nicht in der der Rechnung. Also trifft sie die Kollision auf
        // demselben Weg — und ueber `PaymentRefunded` haengt daran ein Widerruf,
        // der genauso wenig zurueckgerollt werden darf wie eine Erfuellung.
        $zahlung = $this->zahlung('2026-07-15 10:00', 'tr_storno');
        app(InvoiceWriter::class)->forPayment($zahlung);

        $this->vergebeneNummer('RE'.now()->format('Y-m').'-001');

        $this->expectException(NumberAlreadyTaken::class);

        app(InvoiceWriter::class)->creditNoteFor($zahlung->fresh());
    }

    #[Test]
    public function an_unexpected_error_is_a_failed_run_even_without_write(): void
    {
        // Die Steuerhinweise werden auch ohne --write geholt. Ein Programmfehler
        // auf diesem Weg ist kein Befund fuer die Tabelle, sondern ein kaputter
        // Lauf: er gehoert ins Log, mit der Ausnahme, und der Exit-Code muss ihn
        // zeigen. Sonst meldet ein Cron, dessen Ausgabe niemand liest, Erfolg.
        $this->app->bind(InvoiceWriter::class, fn ($app) => new class($app->make(NumberSeries::class)) extends InvoiceWriter
        {
            public function taxNotesFor(Payment $payment): array
            {
                throw new \RuntimeException('der Katalog antwortet nicht');
            }
        });

        Log::spy();

        $this->zahlung('2026-08-10 10:00', 'tr_kaputt');

        $code = Artisan::call('invoices:pending');

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('der Katalog antwortet nicht', Artisan::output());

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($nachricht, $kontext = []) => str_contains((string) $nachricht, 'der Katalog antwortet nicht')
                && ($kontext['exception'] ?? null) instanceof \RuntimeException)
            ->once();
    }

    #[Test]
    public function a_broken_row_in_the_middle_leaves_the_others_written(): void
    {
        // Blockiert ist nur die Reihe des August. Juli und September zaehlen in
        // eigenen Reihen weiter und haben mit der Kollision nichts zu tun.
        $this->vergebeneNummer('RE2026-08-001');

        $juli = $this->zahlung('2026-07-15 10:00', 'tr_juli');
        $august = $this->zahlung('2026-08-10 10:00', 'tr_august');
        $september = $this->zahlung('2026-09-05 10:00', 'tr_september');

        $code = Artisan::call('invoices:pending', ['--write' => true]);
        $ausgabe = Artisan::output();

        $this->assertNotSame(0, $code);
        $this->assertNotNull(Invoice::query()->where('payment_id', $juli->getKey())->first());
        $this->assertNull(Invoice::query()->where('payment_id', $august->getKey())->first());
        $this->assertNotNull(
            Invoice::query()->where('payment_id', $september->getKey())->first(),
            'Die Zeile nach der kaputten wurde nicht einmal versucht.'
        );
        $this->assertStringContainsString('2 Rechnungen geschrieben.', $ausgabe);
    }
}
