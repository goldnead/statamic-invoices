<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Console\Commands\HeldInvoices;
use Goldnead\Invoices\Console\Commands\PendingInvoices;
use Goldnead\Invoices\Console\Commands\RetryInvoices;
use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Delivery\InvoiceDelivery;
use Goldnead\Invoices\Events\InvoiceDelivered;
use Goldnead\Invoices\Models\DeliveryRecord;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Vier stille Fehler aus dem Nachtbefund vom 17.11.2026:
 * bezahlt ohne Rechnung, Rechnung ohne Käufer-Mail, ein Retry, der immer grün
 * endet, und eine Mail, die raus ist, während die Zeile auf `sending` bleibt.
 */
class SilentFailuresAreVisibleTest extends TestCase
{
    private int $nummer = 0;

    public bool $renderFails = false;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'post@host.test', 'name' => 'Der Host']);
        $app['config']->set('invoices.tax.small_business.enabled', false);
        // Kein Steuersatz bestimmbar: die Zahlung bleibt ohne Rechnung.
        $app['config']->set('invoices.tax.zones', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(PdfRenderer::class, new class($test) implements PdfRenderer
        {
            public function __construct(private SilentFailuresAreVisibleTest $test) {}

            public function render(Invoice $invoice): string
            {
                if ($this->test->renderFails) {
                    throw new RuntimeException('renderer down');
                }

                return '%PDF-1.4 stub';
            }
        });

        foreach ([RetryInvoices::class, HeldInvoices::class, PendingInvoices::class] as $befehl) {
            $this->app[Kernel::class]->registerCommand($this->app->make($befehl));
        }
    }

    protected function tearDown(): void
    {
        DeliveryRecord::flushEventListeners();

        parent::tearDown();
    }

    // ── I1: bezahlt ohne Rechnung ────────────────────────────────────────────

    #[Test]
    public function pending_says_it_with_log_error_when_a_paid_payment_has_no_invoice(): void
    {
        Log::spy();

        Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_offen', 'product' => 'kurs',
            'amount_cent' => 11900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'name' => 'Wer', 'country' => 'DE',
            'paid_at' => Carbon::parse('2026-08-10 10:00'),
        ]);

        Artisan::call('invoices:pending');

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($nachricht) => str_contains((string) $nachricht, 'ohne Rechnung') || str_contains((string) $nachricht, 'without an invoice'))
            ->once();
    }

    // ── I2: Rechnung ohne Käufer-Mail ────────────────────────────────────────

    #[Test]
    public function an_invoice_without_a_buyer_address_becomes_a_held_row_that_the_list_shows(): void
    {
        $rechnung = $this->rechnung(email: '');

        $this->assertFalse(app(InvoiceDelivery::class)->send($rechnung));

        $zeile = DeliveryRecord::query()->where('invoice_id', $rechnung->id)->first();
        $this->assertNotNull($zeile);
        $this->assertSame(DeliveryRecord::STATUS_HELD, $zeile->status);
        $this->assertSame('no buyer address', $zeile->reason);

        $this->artisan('invoices:held')->expectsOutputToContain($rechnung->number)->assertFailed();

        // Ein zweiter Versuch legt keine zweite Zeile an und wirft nicht.
        $this->assertFalse(app(InvoiceDelivery::class)->send($rechnung));
        $this->assertSame(1, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->count());
        $this->assertSame(0, $this->gesendet());
    }

    #[Test]
    public function held_list_fails_when_an_invoice_is_held_sending_failed_or_failed_final(): void
    {
        foreach ([DeliveryRecord::STATUS_HELD, DeliveryRecord::STATUS_SENDING, DeliveryRecord::STATUS_FAILED, DeliveryRecord::STATUS_FAILED_FINAL] as $status) {
            $rechnung = $this->rechnung();
            DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => $status, 'attempts' => 1]);

            $this->artisan('invoices:held')->expectsOutputToContain($rechnung->number)->assertFailed();
        }
    }

    #[Test]
    public function held_list_stays_green_when_nothing_is_held(): void
    {
        $this->artisan('invoices:held')->expectsOutputToContain('No invoice is held back.')->assertSuccessful();
    }

    // ── I3: Retry endet nicht mehr grün ──────────────────────────────────────

    #[Test]
    public function retry_fails_when_a_retry_fails(): void
    {
        $rechnung = $this->rechnung();
        DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => DeliveryRecord::STATUS_FAILED, 'attempts' => 1]);
        $this->altern($rechnung, 11);
        $this->renderFails = true;

        $this->artisan('invoices:retry')->assertFailed();

        $this->assertSame(0, $this->gesendet());
    }

    #[Test]
    public function retry_stays_green_when_nothing_failed(): void
    {
        $rechnung = $this->rechnung();
        DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => DeliveryRecord::STATUS_FAILED, 'attempts' => 1]);
        $this->altern($rechnung, 11);

        $this->artisan('invoices:retry')->assertSuccessful();

        $this->assertSame(1, $this->gesendet());
    }

    // ── I4: Mail raus, Status sauber ─────────────────────────────────────────

    #[Test]
    public function a_failing_status_update_after_the_mail_does_not_leave_the_row_in_sending(): void
    {
        $rechnung = $this->rechnung();

        // Das Modell-Update scheitert einmal; die Mail ist da schon angenommen.
        $einmal = true;
        DeliveryRecord::updating(function (DeliveryRecord $row) use (&$einmal) {
            if ($einmal && $row->status === DeliveryRecord::STATUS_SENT) {
                $einmal = false;

                throw new RuntimeException('db weg');
            }
        });

        Event::fake([InvoiceDelivered::class]);

        $this->assertTrue(app(InvoiceDelivery::class)->send($rechnung));

        $this->assertSame(1, $this->gesendet());
        $this->assertSame(DeliveryRecord::STATUS_SENT, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));
        Event::assertDispatched(InvoiceDelivered::class);

        // Und eine spätere Freigabe schickt nichts ein zweites Mal.
        $this->altern($rechnung, 60);
        $this->assertFalse(app(InvoiceDelivery::class)->release($rechnung));
        $this->assertSame(1, $this->gesendet());
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────

    private function altern(Invoice $rechnung, int $minuten): void
    {
        DB::table('invoice_deliveries')->where('invoice_id', $rechnung->id)->update(['updated_at' => now()->subMinutes($minuten)]);
    }

    private function gesendet(): int
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->count();
    }

    private function rechnung(string $email = 'maria.beispiel@example.com'): Invoice
    {
        $this->nummer++;

        $rechnung = Invoice::create([
            'brand_id' => 0,
            'number' => sprintf('R-2026-%04d', $this->nummer),
            'kind' => Invoice::KIND_INVOICE,
            'issued_at' => now(),
            'currency' => 'EUR',
            'buyer_name' => 'Maria Beispiel',
            'buyer_email' => $email,
            'buyer_country' => 'DE',
            'seller' => ['name' => 'Nordlicht Studio', 'email' => 'rechnung@nordlicht.test'],
            'net_cent' => 10000,
            'tax_cent' => 1900,
            'gross_cent' => 11900,
        ]);

        InvoiceItem::whileWriting(fn () => $rechnung->items()->create([
            'product' => 'kurs',
            'name' => 'Chorleitungskurs',
            'quantity' => 1,
            'unit_net_cent' => 10000,
            'net_cent' => 10000,
            'tax_rate_bp' => 1900,
            'tax_mechanism' => 'standard',
            'tax_cent' => 1900,
            'gross_cent' => 11900,
        ]));

        return $rechnung->fresh(['items']);
    }
}
