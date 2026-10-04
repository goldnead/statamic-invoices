<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Console\Commands\HeldInvoices;
use Goldnead\Invoices\Console\Commands\ReleaseInvoice;
use Goldnead\Invoices\Console\Commands\RetryInvoices;
use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Delivery\InvoiceDelivery;
use Goldnead\Invoices\Events\InvoiceIssued;
use Goldnead\Invoices\Integrations\EmailTemplates\InvoiceMailTemplate;
use Goldnead\Invoices\Models\DeliveryRecord;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Zwei Zusagen, beide aus dem Befund auf adg staging (04.10.2026).
 *
 * **Eine gescheiterte Rechnungsmail wird von selbst noch einmal versucht**,
 * begrenzt und mit wachsendem Abstand, und danach laut gemeldet. Exakt einmal
 * bleibt: nie ein `sent`, nie ein `sending`, nie ein `held`.
 *
 * **Die Anrede wird nie die volle Mailadresse.**
 */
class AFailedSendIsTriedAgainTest extends TestCase
{
    private int $nummer = 0;

    public bool $renderFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->getProvider(ServiceProvider::class)?->bootEvents();

        $test = $this;
        $this->app->instance(PdfRenderer::class, new class($test) implements PdfRenderer
        {
            public function __construct(private AFailedSendIsTriedAgainTest $test) {}

            public function render(Invoice $invoice): string
            {
                if ($this->test->renderFails) {
                    throw new RuntimeException('renderer down');
                }

                return '%PDF-1.4 stub';
            }
        });

        $this->app[Kernel::class]->registerCommand($this->app->make(RetryInvoices::class));
        $this->app[Kernel::class]->registerCommand($this->app->make(HeldInvoices::class));
        $this->app[Kernel::class]->registerCommand($this->app->make(ReleaseInvoice::class));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'post@host.test', 'name' => 'Der Host']);
        $app['config']->set('invoices.tax.small_business.enabled', false);
    }

    // ── Wiederholung ─────────────────────────────────────────────────────────

    #[Test]
    public function a_failed_attempt_is_recorded_as_failed_with_its_cause(): void
    {
        $rechnung = $this->rechnung();

        $this->renderFails = true;
        InvoiceIssued::dispatch($rechnung);

        $eintrag = DeliveryRecord::query()->where('invoice_id', $rechnung->id)->firstOrFail();
        $this->assertSame(DeliveryRecord::STATUS_FAILED, $eintrag->status);
        $this->assertSame(1, $eintrag->attempts);
        $this->assertStringContainsString('renderer down', (string) $eintrag->last_error);
        $this->assertSame(0, $this->gesendet());
    }

    #[Test]
    public function retry_sends_a_failed_invoice_once_it_is_old_enough(): void
    {
        $rechnung = $this->rechnung();
        $this->scheitern($rechnung);

        // Zu frisch: noch nichts.
        $this->artisan('invoices:retry')->assertSuccessful();
        $this->assertSame(0, $this->gesendet());

        $this->altern($rechnung, minuten: 11);
        $this->renderFails = false;

        $this->artisan('invoices:retry')->assertSuccessful();

        $this->assertSame(1, $this->gesendet());
        $this->assertSame(DeliveryRecord::STATUS_SENT, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));

        // Ein zweiter Lauf sendet nichts mehr.
        $this->artisan('invoices:retry')->assertSuccessful();
        $this->assertSame(1, $this->gesendet());
    }

    #[Test]
    public function the_gap_grows_with_every_attempt(): void
    {
        $rechnung = $this->rechnung();
        $this->scheitern($rechnung);
        $this->altern($rechnung, minuten: 11);

        // Zweiter Versuch scheitert ebenfalls: attempts = 2, Abstand nun 20 Minuten.
        $this->artisan('invoices:retry')->assertSuccessful();
        $this->assertSame(2, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('attempts'));

        $this->altern($rechnung, minuten: 15);
        $this->renderFails = false;
        $this->artisan('invoices:retry')->assertSuccessful();
        $this->assertSame(0, $this->gesendet());

        $this->altern($rechnung, minuten: 21);
        $this->artisan('invoices:retry')->assertSuccessful();
        $this->assertSame(1, $this->gesendet());
    }

    #[Test]
    public function after_the_last_attempt_it_is_failed_final_logged_listed_and_left_alone(): void
    {
        Log::spy();
        $rechnung = $this->rechnung();
        $this->scheitern($rechnung); // Versuch 1

        foreach ([2, 3] as $versuch) {
            $this->altern($rechnung, minuten: 200);
            $this->artisan('invoices:retry')->assertSuccessful();
            $this->assertSame($versuch, (int) DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('attempts'));
        }

        $eintrag = DeliveryRecord::query()->where('invoice_id', $rechnung->id)->firstOrFail();
        $this->assertSame(DeliveryRecord::STATUS_FAILED_FINAL, $eintrag->status);
        Log::shouldHaveReceived('error')->withArgs(fn (string $m) => $m === DeliveryRecord::LOG_FAILED_FINAL)->once();

        // Nicht mehr angefasst, auch wenn der Renderer wieder geht.
        $this->renderFails = false;
        $this->altern($rechnung, minuten: 500);
        $this->artisan('invoices:retry')->assertSuccessful();
        $this->assertSame(0, $this->gesendet());

        $this->artisan('invoices:held')->expectsOutputToContain($rechnung->number)->assertSuccessful();

        // Von Hand geht es weiter.
        $this->artisan('invoices:release', ['number' => $rechnung->number])->assertSuccessful();
        $this->assertSame(1, $this->gesendet());
    }

    #[Test]
    public function the_maximum_is_configurable(): void
    {
        config(['invoices.delivery.retry.max_attempts' => 1]);

        $rechnung = $this->rechnung();
        $this->scheitern($rechnung);

        $this->assertSame(DeliveryRecord::STATUS_FAILED_FINAL, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));
    }

    #[Test]
    public function retry_never_touches_sent_sending_or_held_rows(): void
    {
        $gesendet = $this->rechnung();
        $sending = $this->rechnung();
        $held = $this->rechnung();

        foreach ([[$gesendet, DeliveryRecord::STATUS_SENT], [$sending, DeliveryRecord::STATUS_SENDING], [$held, DeliveryRecord::STATUS_HELD]] as [$r, $status]) {
            DeliveryRecord::create(['invoice_id' => $r->id, 'status' => $status]);
            $this->altern($r, minuten: 1000);
        }

        $this->artisan('invoices:retry')->assertSuccessful();

        $this->assertSame(0, $this->gesendet());
        $this->assertSame(DeliveryRecord::STATUS_SENDING, DeliveryRecord::query()->where('invoice_id', $sending->id)->value('status'));
        $this->assertSame(DeliveryRecord::STATUS_HELD, DeliveryRecord::query()->where('invoice_id', $held->id)->value('status'));
    }

    #[Test]
    public function two_retries_at_once_send_one_mail(): void
    {
        $rechnung = $this->rechnung();
        $this->scheitern($rechnung);
        $this->altern($rechnung, minuten: 11);
        $this->renderFails = false;

        $delivery = app(InvoiceDelivery::class);
        $this->assertTrue($delivery->send($rechnung));
        $this->assertFalse($delivery->send($rechnung));
        $this->assertSame(1, $this->gesendet());
    }

    #[Test]
    public function a_failed_release_keeps_the_hold_and_is_not_retried_by_itself(): void
    {
        $rechnung = $this->rechnung();
        DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => DeliveryRecord::STATUS_HELD, 'reason' => 'unexpected_zero_tax']);

        $this->renderFails = true;
        $this->artisan('invoices:release', ['number' => $rechnung->number])->assertFailed();

        $this->assertSame(DeliveryRecord::STATUS_HELD, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));
    }

    #[Test]
    public function the_scheduler_runs_retry_unless_switched_off(): void
    {
        $befehle = collect($this->app->make(Schedule::class)->events())
            ->map(fn ($e) => $e->command)
            ->filter(fn ($c) => str_contains((string) $c, 'invoices:retry'));

        $this->assertCount(1, $befehle);
    }

    // ── Anrede ───────────────────────────────────────────────────────────────

    #[Test]
    public function the_greeting_uses_the_stored_name(): void
    {
        $v = app(InvoiceMailTemplate::class)->variables($this->rechnung(name: 'Maria Beispiel'));

        $this->assertSame('Maria Beispiel', $v['buyer']['name']);
        $this->assertSame('maria.beispiel@example.com', $v['buyer']['email']);
    }

    #[Test]
    public function without_a_name_the_greeting_is_the_part_before_the_at_sign(): void
    {
        foreach ([null, '', '   '] as $leer) {
            $v = app(InvoiceMailTemplate::class)->variables($this->rechnung(name: $leer, email: 'addon-rechnung-0410@example.test'));

            $this->assertSame('addon-rechnung-0410', $v['buyer']['name']);
            $this->assertSame('addon-rechnung-0410@example.test', $v['buyer']['email']);
        }
    }

    #[Test]
    public function an_address_that_is_not_one_stays_as_it_is(): void
    {
        $v = app(InvoiceMailTemplate::class)->variables($this->rechnung(name: null, email: 'kein-at-zeichen'));
        $this->assertSame('kein-at-zeichen', $v['buyer']['name']);

        $v = app(InvoiceMailTemplate::class)->variables($this->rechnung(name: null, email: '@example.test'));
        $this->assertSame('@example.test', $v['buyer']['name']);
    }

    // ── Hilfen ───────────────────────────────────────────────────────────────

    private function scheitern(Invoice $rechnung): void
    {
        $this->renderFails = true;
        InvoiceIssued::dispatch($rechnung);
    }

    private function altern(Invoice $rechnung, int $minuten): void
    {
        DB::table('invoice_deliveries')->where('invoice_id', $rechnung->id)->update(['updated_at' => now()->subMinutes($minuten)]);
    }

    private function rechnung(?string $name = 'Maria Beispiel', string $email = 'maria.beispiel@example.com'): Invoice
    {
        $this->nummer++;

        $rechnung = Invoice::create([
            'brand_id' => 0,
            'number' => sprintf('R-2026-%04d', $this->nummer),
            'kind' => Invoice::KIND_INVOICE,
            'issued_at' => now(),
            'currency' => 'EUR',
            'buyer_name' => $name,
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

    private function gesendet(): int
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->count();
    }
}
