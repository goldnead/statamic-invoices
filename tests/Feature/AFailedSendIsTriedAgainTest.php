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

        // Strict guard, as adg sets it: an export zero on a rated product is held.
        $app['config']->set('invoices.tax.product_classes', ['kurs' => 'standard']);
        $app['config']->set('invoices.tax.default_product_class', null);
        $app['config']->set('invoices.delivery.zero_tax_guard.accept', ['reverse_charge', 'intra_community_supply']);
    }

    // ── Der Claim ist ein Vergleich-und-Setzen ───────────────────────────────

    #[Test]
    public function a_stale_failed_read_loses_the_claim_to_a_row_that_became_sending_or_sent(): void
    {
        foreach ([DeliveryRecord::STATUS_SENDING, DeliveryRecord::STATUS_SENT] as $anderer) {
            $rechnung = $this->rechnung();
            $this->scheitern($rechnung);
            $this->altern($rechnung, minuten: 11);
            $this->renderFails = false;
            $vorher = $this->gesendet();

            // Another run reads the same `failed` row ... and wins in between.
            $einmal = true;
            DeliveryRecord::retrieved(function (DeliveryRecord $row) use (&$einmal, $anderer) {
                if ($einmal && $row->status === DeliveryRecord::STATUS_FAILED) {
                    $einmal = false;
                    DB::table('invoice_deliveries')->where('id', $row->id)->update(['status' => $anderer]);
                }
            });

            try {
                $this->assertFalse(app(InvoiceDelivery::class)->send($rechnung), "status {$anderer}");
            } finally {
                DeliveryRecord::flushEventListeners();
            }

            $this->assertSame($vorher, $this->gesendet(), "status {$anderer}: no mail from the loser");
            $this->assertSame($anderer, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));
        }
    }

    #[Test]
    public function of_two_runs_on_one_failed_row_exactly_one_mail_goes_out(): void
    {
        $rechnung = $this->rechnung();
        $this->scheitern($rechnung);
        $this->altern($rechnung, minuten: 11);
        $this->renderFails = false;

        $zweiter = null;
        $einmal = true;
        // The first run is mid-claim (after its read) when the second one starts and completes.
        DeliveryRecord::retrieved(function (DeliveryRecord $row) use (&$einmal, &$zweiter, $rechnung) {
            if ($einmal && $row->status === DeliveryRecord::STATUS_FAILED) {
                $einmal = false;
                $zweiter = app(InvoiceDelivery::class)->send($rechnung->fresh(['items']));
            }
        });

        try {
            $erster = app(InvoiceDelivery::class)->send($rechnung);
        } finally {
            DeliveryRecord::flushEventListeners();
        }

        $this->assertTrue($zweiter);
        $this->assertFalse($erster);
        $this->assertSame(1, $this->gesendet());
    }

    // ── Freigabe von failed ──────────────────────────────────────────────────

    #[Test]
    public function release_sends_a_failed_and_a_failed_final_row(): void
    {
        foreach ([DeliveryRecord::STATUS_FAILED, DeliveryRecord::STATUS_FAILED_FINAL] as $status) {
            $rechnung = $this->rechnung();
            DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => $status, 'attempts' => 3]);
            $vorher = $this->gesendet();

            $this->artisan('invoices:release', ['number' => $rechnung->number])->assertSuccessful();

            $this->assertSame($vorher + 1, $this->gesendet(), $status);
            $this->assertSame(DeliveryRecord::STATUS_SENT, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));
        }
    }

    #[Test]
    public function a_failed_release_puts_failed_and_failed_final_back_as_they_were(): void
    {
        foreach ([DeliveryRecord::STATUS_FAILED, DeliveryRecord::STATUS_FAILED_FINAL] as $status) {
            $rechnung = $this->rechnung();
            DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => $status, 'attempts' => 3, 'last_error' => 'boom']);

            $this->renderFails = true;
            $this->artisan('invoices:release', ['number' => $rechnung->number])->assertFailed();

            $eintrag = DeliveryRecord::query()->where('invoice_id', $rechnung->id)->firstOrFail();
            $this->assertSame($status, $eintrag->status);
            $this->assertSame(3, $eintrag->attempts);
            $this->assertSame('boom', $eintrag->last_error);
        }
    }

    #[Test]
    public function release_refuses_a_fresh_sending_row_and_frees_an_old_one(): void
    {
        $rechnung = $this->rechnung();
        DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => DeliveryRecord::STATUS_SENDING]);

        $this->artisan('invoices:release', ['number' => $rechnung->number])
            ->expectsOutputToContain('is being sent right now')
            ->assertFailed();
        $this->assertSame(0, $this->gesendet());
        $this->assertSame(DeliveryRecord::STATUS_SENDING, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));

        $this->altern($rechnung, minuten: 11);

        $this->artisan('invoices:release', ['number' => $rechnung->number])->assertSuccessful();
        $this->assertSame(1, $this->gesendet());
    }

    // ── Pflichtwege ohne Anspruch auf Versand ────────────────────────────────

    #[Test]
    public function a_retry_that_stops_before_the_claim_still_uses_up_attempts_until_failed_final(): void
    {
        $rechnung = $this->rechnung();
        $rechnung->forceFill(['buyer_email' => ''])->saveQuietly();
        DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => DeliveryRecord::STATUS_FAILED, 'attempts' => 1]);

        foreach ([2, 3] as $versuch) {
            $this->altern($rechnung, minuten: 200);
            $this->artisan('invoices:retry')->assertSuccessful();
            $this->assertSame($versuch, (int) DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('attempts'));
        }

        $this->assertSame(DeliveryRecord::STATUS_FAILED_FINAL, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));
        $this->assertSame(0, $this->gesendet());
    }

    #[Test]
    public function a_retry_skips_the_zero_tax_guard_that_the_first_attempt_passed(): void
    {
        // The guard is on: a fresh export zero on a rated product is held ...
        $neu = $this->rechnung(mechanism: 'export', rate: 0, land: 'CH');
        InvoiceIssued::dispatch($neu);
        $this->assertSame(DeliveryRecord::STATUS_HELD, DeliveryRecord::query()->where('invoice_id', $neu->id)->value('status'));

        // ... but a row that already failed once was past it (the invoice cannot
        // change in between), so the retry does not hold it again.
        $rechnung = $this->rechnung(mechanism: 'export', rate: 0, land: 'CH');
        DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => DeliveryRecord::STATUS_FAILED, 'attempts' => 1]);
        $this->altern($rechnung, minuten: 11);

        $this->artisan('invoices:retry')->assertSuccessful();

        $this->assertSame(1, $this->gesendet());
        $this->assertSame(DeliveryRecord::STATUS_SENT, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));
    }

    #[Test]
    public function the_event_firing_again_waits_out_the_backoff(): void
    {
        $rechnung = $this->rechnung();
        $this->scheitern($rechnung);
        $this->renderFails = false;

        InvoiceIssued::dispatch($rechnung);
        $this->assertSame(0, $this->gesendet());

        $this->altern($rechnung, minuten: 11);
        InvoiceIssued::dispatch($rechnung);
        $this->assertSame(1, $this->gesendet());
    }

    #[Test]
    public function an_intermediate_failure_is_logged_with_the_number_only(): void
    {
        Log::spy();
        $rechnung = $this->rechnung();
        $this->scheitern($rechnung);
        $this->altern($rechnung, minuten: 11);

        $this->artisan('invoices:retry')->assertSuccessful();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx = []) => $m === 'invoices: retry failed'
            && $ctx === ['number' => $rechnung->number, 'attempt' => 2])->once();
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

    private function rechnung(?string $name = 'Maria Beispiel', string $email = 'maria.beispiel@example.com', ?string $mechanism = 'standard', int $rate = 1900, string $land = 'DE'): Invoice
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
            'buyer_country' => $land,
            'seller' => ['name' => 'Nordlicht Studio', 'email' => 'rechnung@nordlicht.test'],
            'net_cent' => $rate === 0 ? 11900 : 10000,
            'tax_cent' => $rate === 0 ? 0 : 1900,
            'gross_cent' => 11900,
        ]);

        InvoiceItem::whileWriting(fn () => $rechnung->items()->create([
            'product' => 'kurs',
            'name' => 'Chorleitungskurs',
            'quantity' => 1,
            'unit_net_cent' => $rate === 0 ? 11900 : 10000,
            'net_cent' => $rate === 0 ? 11900 : 10000,
            'tax_rate_bp' => $rate,
            'tax_mechanism' => $mechanism,
            'tax_cent' => $rate === 0 ? 0 : 1900,
            'gross_cent' => 11900,
        ]));

        return $rechnung->fresh(['items']);
    }

    private function gesendet(): int
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->count();
    }
}
