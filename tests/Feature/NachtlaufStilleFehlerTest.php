<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Events\CreditNoteIssued;
use Goldnead\Invoices\Events\InvoiceIssued;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Listeners\WriteCreditNoteOnRefund;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Drei stille Stellen aus dem Sweep vom 17.11.2026 (I5, I6) und dem Ticket zum doppelten Zeitplan.
 */
class NachtlaufStilleFehlerTest extends TestCase
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

    private function bezahlt(string $id = 'tr_1'): Payment
    {
        return Payment::create([
            'provider' => 'fake', 'provider_id' => $id, 'product' => 'kurs',
            'amount_cent' => 11900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'name' => 'Wer', 'country' => 'DE', 'paid_at' => now(),
        ]);
    }

    // ── Zeitplan: invoices:retry genau einmal ────────────────────────────────

    #[Test]
    public function retry_is_on_the_scheduler_exactly_once(): void
    {
        $schedule = new Schedule;
        (new \ReflectionMethod(ServiceProvider::class, 'schedule'))->invoke($this->app->getProvider(ServiceProvider::class), $schedule);
        $befehle = collect($schedule->events())->filter(fn ($e) => str_contains((string) $e->command, 'invoices:retry'));

        $this->assertCount(1, $befehle);
    }

    // ── I6: das Ereignis feuert nicht vor einem äußeren Commit ───────────────

    #[Test]
    public function the_issued_event_waits_for_an_outer_commit_and_never_fires_after_a_rollback(): void
    {
        $gesehen = [];
        Event::listen(InvoiceIssued::class, function (InvoiceIssued $e) use (&$gesehen) {
            $gesehen[] = $e->invoice->number;
        });
        $zahlung = $this->bezahlt();

        try {
            DB::transaction(function () use ($zahlung, &$gesehen) {
                app(InvoiceWriter::class)->forPayment($zahlung);
                $this->assertSame([], $gesehen, 'Das Ereignis darf nicht innerhalb der äußeren Transaktion feuern.');

                throw new RuntimeException('äußere Transaktion rollt zurück');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Invoice::count());
        $this->assertSame([], $gesehen, 'Nach dem Rollback darf kein Käufer eine Rechnungsnummer ohne Datensatz erfahren.');
    }

    #[Test]
    public function the_issued_event_fires_after_the_outer_commit(): void
    {
        $gesehen = [];
        Event::listen(InvoiceIssued::class, function (InvoiceIssued $e) use (&$gesehen) {
            $gesehen[] = $e->invoice->number;
        });
        $zahlung = $this->bezahlt();

        DB::transaction(function () use ($zahlung, &$gesehen) {
            app(InvoiceWriter::class)->forPayment($zahlung);
            $this->assertSame([], $gesehen);
        });

        $this->assertCount(1, $gesehen);
    }

    #[Test]
    public function the_credit_note_event_waits_for_an_outer_commit_and_never_fires_after_a_rollback(): void
    {
        $gesehen = 0;
        Event::listen(CreditNoteIssued::class, function () use (&$gesehen) {
            $gesehen++;
        });
        $zahlung = $this->bezahlt();
        app(InvoiceWriter::class)->forPayment($zahlung);

        try {
            DB::transaction(function () use ($zahlung, &$gesehen) {
                app(InvoiceWriter::class)->creditNoteFor($zahlung->fresh());
                $this->assertSame(0, $gesehen, 'Das Ereignis darf nicht innerhalb der äußeren Transaktion feuern.');

                throw new RuntimeException('äußere Transaktion rollt zurück');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $gesehen);
        $this->assertSame(1, Invoice::count());
    }

    // ── I5: Teilerstattung und fehlendes Original sind im Log zu sehen ───────

    #[Test]
    public function a_partial_refund_leaves_a_trace_in_the_log(): void
    {
        Log::spy();
        $zahlung = $this->bezahlt();

        (new WriteCreditNoteOnRefund(app(InvoiceWriter::class)))->handle(new PaymentRefunded($zahlung, 2000, false));

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($nachricht, $kontext = []) => str_contains((string) $nachricht, 'partial refund')
                && ($kontext['payment_id'] ?? null) === $zahlung->getKey())
            ->once();
        $this->assertSame(0, Invoice::count());
    }

    #[Test]
    public function a_refund_without_an_original_invoice_warns(): void
    {
        Log::spy();
        $zahlung = $this->bezahlt();

        $this->assertNull(app(InvoiceWriter::class)->creditNoteFor($zahlung));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($nachricht, $kontext = []) => str_contains((string) $nachricht, 'without an original invoice')
                && ($kontext['payment_id'] ?? null) === $zahlung->getKey())
            ->once();
    }
}
