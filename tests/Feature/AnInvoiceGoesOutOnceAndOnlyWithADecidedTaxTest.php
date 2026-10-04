<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Console\Commands\HeldInvoices;
use Goldnead\Invoices\Console\Commands\ReleaseInvoice;
use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Delivery\InvoiceDelivery;
use Goldnead\Invoices\Delivery\ZeroTaxGuard;
use Goldnead\Invoices\Events\InvoiceIssued;
use Goldnead\Invoices\Models\DeliveryRecord;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\PaymentCommunication;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Zwei Zusagen der Zustellung, beide aus adriangoldner.com übernommen.
 *
 * **Genau eine Mail je Rechnung.** Auch wenn das Ereignis zweimal ankommt
 * oder der erste Versuch am Mailserver scheitert und ein zweiter folgt. Die
 * Rechnungszeile selbst kann sich das nicht merken (sie ist unveränderlich),
 * deshalb steht der Versand in `invoice_deliveries`.
 *
 * **Keine Rechnung mit 0 Steuer, wo für das Produkt ein Satz entschieden
 * ist.** Der Fall aus adg: ein Drittland und ein Produkt mit `digital =>
 * false` ergeben in `TaxRules` eine Ausfuhrlieferung, eine Aussage, die für
 * eine Zoom-Session niemand getroffen hat. Die Sperre entscheidet keine
 * Steuerfrage; sie vergleicht das Dokument mit der Konfiguration und hält es
 * zurück, wenn beide auseinandergehen. Eine Rechnung im Postfach lässt sich
 * nur noch stornieren, eine zurückgehaltene still korrigieren.
 */
class AnInvoiceGoesOutOnceAndOnlyWithADecidedTaxTest extends TestCase
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
            public function __construct(private AnInvoiceGoesOutOnceAndOnlyWithADecidedTaxTest $test) {}

            public function render(Invoice $invoice): string
            {
                if ($this->test->renderFails) {
                    throw new RuntimeException('renderer down');
                }

                return '%PDF-1.4 stub';
            }
        });
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'post@host.test', 'name' => 'Der Host']);

        $app['config']->set('invoices.tax.small_business.enabled', false);
        $app['config']->set('invoices.tax.product_classes', ['kurs' => 'standard', 'unterricht' => 'teaching']);
        $app['config']->set('invoices.tax.default_product_class', null);
        $app['config']->set('invoices.tax.exemptions', [
            'teaching' => ['reason' => 'Steuerfrei nach § 4 Nr. 20 Buchst. a UStG.', 'domestic_only' => true],
        ]);

        // Enger als die Vorgabe, so wie adg es einstellt: nur was auf einer
        // bestätigten USt-IdNr. steht, gilt als entschieden.
        $app['config']->set('invoices.delivery.zero_tax_guard.accept', ['reverse_charge', 'intra_community_supply']);
    }

    // ── genau einmal ─────────────────────────────────────────────────────────

    #[Test]
    public function the_same_invoice_announced_twice_is_mailed_once(): void
    {
        $rechnung = $this->rechnung();

        InvoiceIssued::dispatch($rechnung);
        InvoiceIssued::dispatch($rechnung);

        $this->assertSame(1, $this->gesendet());
        $this->assertSame(DeliveryRecord::STATUS_SENT, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));
    }

    #[Test]
    public function a_failed_attempt_leaves_the_way_open_and_the_retry_sends_exactly_one(): void
    {
        $rechnung = $this->rechnung();

        $this->renderFails = true;
        InvoiceIssued::dispatch($rechnung);
        $this->assertSame(0, $this->gesendet());
        $this->assertSame(DeliveryRecord::STATUS_FAILED, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));

        $this->renderFails = false;

        // The event firing again right away waits out the backoff.
        $this->assertFalse(app(InvoiceDelivery::class)->send($rechnung));
        $this->assertSame(0, $this->gesendet());

        DB::table('invoice_deliveries')->where('invoice_id', $rechnung->id)->update(['updated_at' => now()->subMinutes(11)]);
        $this->assertTrue(app(InvoiceDelivery::class)->send($rechnung));
        $this->assertFalse(app(InvoiceDelivery::class)->send($rechnung));

        $this->assertSame(1, $this->gesendet());
    }

    // ── Nullsteuer ───────────────────────────────────────────────────────────

    #[Test]
    public function zero_tax_where_the_product_class_carries_a_rate_is_held_back_and_said_so(): void
    {
        Log::spy();

        $rechnung = $this->rechnung(mechanism: 'export', rate: 0, land: 'CH');

        InvoiceIssued::dispatch($rechnung);

        $this->assertSame(0, $this->gesendet());

        $eintrag = DeliveryRecord::query()->where('invoice_id', $rechnung->id)->firstOrFail();
        $this->assertSame(DeliveryRecord::STATUS_HELD, $eintrag->status);
        $this->assertSame(ZeroTaxGuard::REASON, $eintrag->reason);

        Log::shouldHaveReceived('error')->withArgs(fn (string $nachricht, array $kontext) => $nachricht === ZeroTaxGuard::LOG_HELD
            && $kontext['number'] === $rechnung->number
            && $kontext['lines'][0]['product'] === 'kurs'
            && $kontext['lines'][0]['tax_class'] === 'standard'
            && $kontext['lines'][0]['tax_mechanism'] === 'export')->once();

        // Ein zweiter Versuch findet dieselbe Rechnung vor: sie bleibt liegen.
        $this->assertFalse(app(InvoiceDelivery::class)->send($rechnung));
        $this->assertSame(0, $this->gesendet());
    }

    #[Test]
    public function the_hold_shows_up_on_the_payment_where_the_payments_log_exists(): void
    {
        if (! class_exists(PaymentCommunication::class)) {
            $this->markTestSkipped('this statamic-payments has no communication log');
        }

        $rechnung = $this->rechnung(mechanism: 'export', rate: 0, land: 'CH');

        InvoiceIssued::dispatch($rechnung);

        $zeile = PaymentCommunication::query()->where('payment_id', $rechnung->payment_id)->firstOrFail();
        $this->assertSame('invoice', $zeile->kind);
        $this->assertSame(PaymentCommunication::STATUS_FAILED, $zeile->status);
        $this->assertSame(ZeroTaxGuard::REASON, $zeile->meta['held'] ?? null);
    }

    #[Test]
    public function decided_zeros_go_out(): void
    {
        // Eine Befreiung ist die Entscheidung, § 19 auch, und ein Nullsatz der
        // Zone für die Klasse ebenso.
        $this->rechnung(mechanism: 'exempt', rate: 0, produkt: 'unterricht');
        $this->rechnung(mechanism: 'small_business', rate: 0);
        $this->rechnung(mechanism: 'standard', rate: 0);
        // Ein Produkt ohne Klasse: hier ist gar nichts entschieden worden.
        $this->rechnung(mechanism: 'export', rate: 0, produkt: 'noten', land: 'CH');
        // Reverse Charge steht auf einer bestätigten USt-IdNr. und ist per
        // Vorgabe angenommen.
        $this->rechnung(mechanism: 'reverse_charge', rate: 0, land: 'AT');

        foreach (Invoice::all() as $rechnung) {
            InvoiceIssued::dispatch($rechnung);
        }

        $this->assertSame(5, $this->gesendet());
        $this->assertFalse(DeliveryRecord::query()->where('status', DeliveryRecord::STATUS_HELD)->exists());
    }

    #[Test]
    public function the_accepted_mechanisms_and_the_guard_itself_are_configurable(): void
    {
        config(['invoices.delivery.zero_tax_guard.accept' => []]);
        InvoiceIssued::dispatch($this->rechnung(mechanism: 'reverse_charge', rate: 0, land: 'AT'));
        $this->assertSame(0, $this->gesendet());

        config(['invoices.delivery.zero_tax_guard.accept' => ['export']]);
        InvoiceIssued::dispatch($this->rechnung(mechanism: 'export', rate: 0, land: 'CH'));
        $this->assertSame(1, $this->gesendet());

        config(['invoices.delivery.zero_tax_guard.accept' => [], 'invoices.delivery.zero_tax_guard.enabled' => false]);
        InvoiceIssued::dispatch($this->rechnung(mechanism: 'outside_scope', rate: 0, land: 'US'));
        $this->assertSame(2, $this->gesendet());
    }

    #[Test]
    public function the_small_business_switch_still_counts_for_lines_from_before_the_mechanism_was_stored(): void
    {
        $alt = $this->rechnung(mechanism: null, rate: 0);
        InvoiceIssued::dispatch($alt);
        $this->assertSame(0, $this->gesendet(), 'ohne Mechanismus und ohne § 19 ist 0 eine Abweichung');

        config(['invoices.tax.small_business.enabled' => true]);
        InvoiceIssued::dispatch($this->rechnung(mechanism: null, rate: 0));
        $this->assertSame(1, $this->gesendet());
    }

    #[Test]
    public function the_guard_is_on_by_default(): void
    {
        $ausgeliefert = (require __DIR__.'/../../config/invoices.php')['delivery']['zero_tax_guard'];

        $this->assertTrue((bool) $ausgeliefert['enabled']);
        $this->assertSame(['reverse_charge', 'intra_community_supply', 'export', 'outside_scope'], $ausgeliefert['accept']);
    }

    #[Test]
    public function by_default_every_rule_based_zero_goes_out_and_only_an_unexplained_one_is_held(): void
    {
        // Die ausgelieferte Vorgabe: ein Update auf 2.6 hält keine
        // Ausfuhrrechnung an, die vorher rausging.
        config(['invoices.delivery.zero_tax_guard.accept' => (require __DIR__.'/../../config/invoices.php')['delivery']['zero_tax_guard']['accept']]);

        foreach (['reverse_charge' => 'AT', 'intra_community_supply' => 'AT', 'export' => 'CH', 'outside_scope' => 'US'] as $regel => $land) {
            InvoiceIssued::dispatch($this->rechnung(mechanism: $regel, rate: 0, land: $land));
        }

        $this->assertSame(4, $this->gesendet());

        // Keine Regel gespeichert und kein § 19: das erklärt nichts.
        InvoiceIssued::dispatch($this->rechnung(mechanism: null, rate: 0));
        $this->assertSame(4, $this->gesendet());

        // Enger gestellt: dieselbe Ausfuhr wird gehalten.
        config(['invoices.delivery.zero_tax_guard.accept' => ['reverse_charge', 'intra_community_supply']]);
        InvoiceIssued::dispatch($this->rechnung(mechanism: 'export', rate: 0, land: 'CH'));
        $this->assertSame(4, $this->gesendet());
        $this->assertSame(2, DeliveryRecord::query()->where('status', DeliveryRecord::STATUS_HELD)->count());
    }

    // ── Einmaligkeit unter Konkurrenz ────────────────────────────────────────

    #[Test]
    public function a_claim_already_in_sending_stops_a_second_attempt(): void
    {
        $rechnung = $this->rechnung();

        DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => DeliveryRecord::STATUS_SENDING]);

        $this->assertFalse(app(InvoiceDelivery::class)->send($rechnung));
        $this->assertSame(0, $this->gesendet());
    }

    #[Test]
    public function losing_the_race_for_the_claim_sends_nothing_and_throws_nothing(): void
    {
        $rechnung = $this->rechnung();

        // Der andere Prozess schreibt seine Zeile zwischen der Prüfung und dem
        // eigenen Insert: der Insert läuft in den eindeutigen Index.
        DeliveryRecord::creating(function () use ($rechnung) {
            DB::table('invoice_deliveries')->insert([
                'invoice_id' => $rechnung->id,
                'status' => DeliveryRecord::STATUS_SENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->assertFalse(app(InvoiceDelivery::class)->send($rechnung));
        } finally {
            DeliveryRecord::flushEventListeners();
        }

        $this->assertSame(0, $this->gesendet());
        $this->assertSame(1, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->count());
    }

    // ── Freigabe ohne SQL ────────────────────────────────────────────────────

    #[Test]
    public function a_held_invoice_is_listed_and_released_by_its_number(): void
    {
        Log::spy();

        $rechnung = $this->rechnung(mechanism: 'export', rate: 0, land: 'CH');
        InvoiceIssued::dispatch($rechnung);
        $this->assertSame(0, $this->gesendet());

        $this->registriereBefehle();

        $this->artisan('invoices:held')
            ->expectsOutputToContain($rechnung->number)
            ->assertSuccessful();

        $this->artisan('invoices:release', ['number' => $rechnung->number])->assertSuccessful();

        $this->assertSame(1, $this->gesendet());
        $this->assertSame(DeliveryRecord::STATUS_SENT, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $nachricht) => str_contains($nachricht, $rechnung->number.' released by hand'))->once();

        // Kein zweites Mal, auch nicht von Hand.
        $this->artisan('invoices:release', ['number' => $rechnung->number])->assertFailed();
        $this->artisan('invoices:held')->expectsOutputToContain('No invoice is held back.')->assertSuccessful();
        $this->assertSame(1, $this->gesendet());
    }

    #[Test]
    public function a_release_that_fails_keeps_the_invoice_held(): void
    {
        $rechnung = $this->rechnung(mechanism: 'export', rate: 0, land: 'CH');
        InvoiceIssued::dispatch($rechnung);

        $this->registriereBefehle();
        $this->renderFails = true;

        $this->artisan('invoices:release', ['number' => $rechnung->number])->assertFailed();

        $this->assertSame(0, $this->gesendet());
        $this->assertSame(DeliveryRecord::STATUS_HELD, DeliveryRecord::query()->where('invoice_id', $rechnung->id)->value('status'));
    }

    #[Test]
    public function an_unknown_or_unheld_number_is_refused(): void
    {
        $this->registriereBefehle();

        $this->artisan('invoices:release', ['number' => 'R-0000'])->assertFailed();
        $this->artisan('invoices:release', ['number' => $this->rechnung()->number])->assertFailed();
        $this->assertSame(0, $this->gesendet());
    }

    private function registriereBefehle(): void
    {
        $this->app[Kernel::class]->registerCommand($this->app->make(HeldInvoices::class));
        $this->app[Kernel::class]->registerCommand($this->app->make(ReleaseInvoice::class));
    }

    private function rechnung(?string $mechanism = 'standard', int $rate = 1900, string $produkt = 'kurs', string $land = 'DE'): Invoice
    {
        $this->nummer++;

        $zahlung = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_'.bin2hex(random_bytes(4)), 'product' => $produkt,
            'amount_cent' => 11900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'maria@example.com', 'paid_at' => now(),
        ]);

        $steuer = $rate === 0 ? 0 : 1900;
        $netto = $rate === 0 ? 11900 : 10000;

        $rechnung = Invoice::create([
            'brand_id' => 0,
            'number' => sprintf('R-2026-%04d', $this->nummer),
            'kind' => Invoice::KIND_INVOICE,
            'payment_id' => $zahlung->id,
            'issued_at' => now(),
            'currency' => 'EUR',
            'buyer_name' => 'Maria Beispiel',
            'buyer_email' => 'maria@example.com',
            'buyer_country' => $land,
            'seller' => ['name' => 'Nordlicht Studio', 'email' => 'rechnung@nordlicht.test'],
            'net_cent' => $netto,
            'tax_cent' => $steuer,
            'gross_cent' => 11900,
        ]);

        InvoiceItem::whileWriting(fn () => $rechnung->items()->create([
            'product' => $produkt,
            'name' => 'Chorleitungskurs',
            'quantity' => 1,
            'unit_net_cent' => $netto,
            'net_cent' => $netto,
            'tax_rate_bp' => $rate,
            'tax_mechanism' => $mechanism,
            'tax_cent' => $steuer,
            'gross_cent' => 11900,
        ]));

        return $rechnung->fresh(['items']);
    }

    private function gesendet(): int
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->count();
    }
}
