<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Console\Commands\PendingInvoices;
use Goldnead\Invoices\Exceptions\InvoiceNotWritten;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * I5, zweiter Teil: `invoices:pending` meldet auch Zahlungen, die voll erstattet
 * sind, eine Rechnung haben, aber keine Gutschrift. Eine Teilerstattung bekommt
 * bewusst keine Gutschrift (der Listener schreibt nur bei voller Erstattung),
 * also ist sie hier kein Befund.
 */
class PendingRefundedWithoutCreditNoteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app[Kernel::class]->registerCommand($this->app->make(PendingInvoices::class));
    }

    /** Bezahlt, mit einer Rechnung, und die Erstattung ist (ganz oder teilweise) gebucht. */
    private function erstattet(int $erstattetCent, string $providerId = 'tr_erstattet'): Payment
    {
        $zahlung = Payment::create([
            'provider' => 'fake', 'provider_id' => $providerId, 'product' => 'kurs',
            'amount_cent' => 11900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE', 'paid_at' => now(),
            'refunded_cent' => $erstattetCent,
            'refunded_at' => $erstattetCent > 0 ? now() : null,
        ]);

        app(InvoiceWriter::class)->forPayment($zahlung->fresh());

        return $zahlung->fresh();
    }

    private function gutschriften(): int
    {
        return Invoice::query()->where('kind', Invoice::KIND_CREDIT_NOTE)->count();
    }

    #[Test]
    public function a_full_refund_without_a_credit_note_is_listed_and_not_written_without_write(): void
    {
        $zahlung = $this->erstattet(11900);

        $code = Artisan::call('invoices:pending');
        $ausgabe = Artisan::output();

        $this->assertSame(0, $code, $ausgabe);
        $this->assertStringContainsString('erstattet, ohne Gutschrift', $ausgabe);
        $this->assertStringContainsString('| '.$zahlung->id.' ', $ausgabe);
        $this->assertStringContainsString('(nicht versucht)', $ausgabe);
        $this->assertSame(0, $this->gutschriften());
    }

    #[Test]
    public function with_write_the_missing_credit_note_is_written(): void
    {
        $zahlung = $this->erstattet(11900);

        $this->assertSame(0, Artisan::call('invoices:pending', ['--write' => true]));

        $storno = Invoice::query()->where('kind', Invoice::KIND_CREDIT_NOTE)->first();
        $this->assertNotNull($storno);
        $this->assertSame($zahlung->id, $storno->payment_id);
    }

    #[Test]
    public function a_partial_refund_is_not_listed(): void
    {
        $this->erstattet(5000);

        $code = Artisan::call('invoices:pending');

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('erstattet, ohne Gutschrift', Artisan::output());
        $this->assertSame(0, $this->gutschriften());
    }

    #[Test]
    public function a_full_refund_that_already_has_its_credit_note_is_not_listed(): void
    {
        $zahlung = $this->erstattet(11900);
        app(InvoiceWriter::class)->creditNoteFor($zahlung);

        $code = Artisan::call('invoices:pending');

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('erstattet, ohne Gutschrift', Artisan::output());
        $this->assertSame(1, $this->gutschriften());
    }

    #[Test]
    public function only_the_full_refund_with_an_invoice_is_listed_among_several_payments(): void
    {
        $voll = $this->erstattet(11900, 'tr_voll');
        $teil = $this->erstattet(5000, 'tr_teil');
        $ohneRechnung = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_ohne', 'product' => 'kurs',
            'amount_cent' => 11900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'x@example.com', 'name' => 'X', 'country' => 'DE', 'paid_at' => now(),
            'refunded_cent' => 11900, 'refunded_at' => now(),
        ]);

        Artisan::call('invoices:pending');
        $ausgabe = Artisan::output();
        $teile = explode('Voll erstattet, ohne Gutschrift:', $ausgabe);

        $this->assertCount(2, $teile, $ausgabe);
        $this->assertStringContainsString('| '.$voll->id.' ', $teile[1]);
        $this->assertStringNotContainsString('| '.$teil->id.' ', $teile[1]);
        $this->assertStringNotContainsString('| '.$ohneRechnung->id.' ', $teile[1]);
        $this->assertStringContainsString('1 Zahlungen erstattet, ohne Gutschrift', $ausgabe);
    }

    #[Test]
    public function a_credit_note_writer_that_returns_null_is_not_reported_as_written(): void
    {
        Log::spy();
        $zahlung = $this->erstattet(11900);

        $this->mock(InvoiceWriter::class, function ($mock) {
            $mock->shouldReceive('taxNotesFor')->andReturn([]);
            $mock->shouldReceive('creditNoteFor')->andReturn(null);
        });

        $code = Artisan::call('invoices:pending', ['--write' => true]);
        $ausgabe = Artisan::output();

        $this->assertSame(1, $code, $ausgabe);
        $this->assertStringNotContainsString('Gutschriften geschrieben', $ausgabe);
        $this->assertStringContainsString('| '.$zahlung->id.' ', $ausgabe);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($m, $c = []) => ($c['payment_id'] ?? null) === $zahlung->id)
            ->once();
    }

    #[Test]
    public function a_finding_without_a_program_error_gives_exit_1_and_the_reason_but_no_per_row_log(): void
    {
        Log::spy();
        $zahlung = $this->erstattet(11900);

        $this->mock(InvoiceWriter::class, function ($mock) {
            $mock->shouldReceive('taxNotesFor')->andReturn([]);
            $mock->shouldReceive('creditNoteFor')->andThrow(new class('Steuersatz offen') extends InvoiceNotWritten {});
        });

        $code = Artisan::call('invoices:pending', ['--write' => true]);
        $ausgabe = Artisan::output();

        $this->assertSame(1, $code, $ausgabe);
        $this->assertStringContainsString('| '.$zahlung->id.' ', $ausgabe);
        Log::shouldNotHaveReceived('error', [\Mockery::on(fn ($m) => is_string($m) && str_contains($m, 'Steuersatz offen')), \Mockery::any()]);
    }

    #[Test]
    public function a_credit_note_that_fails_with_write_ends_with_a_failure_and_a_log_line(): void
    {
        Log::spy();

        $zahlung = $this->erstattet(11900);

        $this->mock(InvoiceWriter::class, function ($mock) {
            $mock->shouldReceive('taxNotesFor')->andReturn([]);
            $mock->shouldReceive('creditNoteFor')->andThrow(new RuntimeException('Gutschrift kaputt'));
        });

        $code = Artisan::call('invoices:pending', ['--write' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('| '.$zahlung->id.' ', Artisan::output());

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($nachricht) => str_contains((string) $nachricht, 'erstattete Zahlungen ohne Gutschrift'))
            ->once();
    }
}
