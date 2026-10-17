<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\ServiceProvider as BrandContextServiceProvider;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\Invoices\Console\Commands\BrandCheck;
use Goldnead\Invoices\Console\Commands\ExportInvoices;
use Goldnead\Invoices\Console\Commands\PendingInvoices;
use Goldnead\Invoices\Console\Commands\RecheckVatIds;
use Goldnead\Invoices\Console\Commands\ReleaseInvoice;
use Goldnead\Invoices\Console\Commands\RetryInvoices;
use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Contracts\VatIdVerifier;
use Goldnead\Invoices\Events\InvoiceIssued;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Support\VatIdCheck;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * A console run has no brand, so on a multi-brand host it saw the package
 * values and not what the operator saved on the settings page.
 *
 * Measured on 07.09.2026 (0 of 12 settings reach a console or scheduler run),
 * decided on 18.10.2026: every command that reads brand settings runs once per
 * brand, on that brand's rows only. The money path is `invoices:pending`: the
 * seller on an invoice is frozen from the settings, and it must be the seller of
 * the brand that sold, not the package's empty one.
 *
 * Every test stores a *different* value per brand through the settings layer
 * (not through `config()`), so that "the run saw the package value" and "the run
 * saw the neighbour's value" both fail.
 */
class ConsoleCommandsRunPerBrandTest extends TestCase
{
    private Brand $eins;

    private Brand $zwei;

    private int $nummer = 0;

    public bool $renderFails = false;

    /** @var list<array{seller: mixed, brand: int|null}> */
    public array $archiveBuilds = [];

    protected function getPackageProviders($app): array
    {
        return array_merge([BrandContextServiceProvider::class], parent::getPackageProviders($app));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('brand-context.multi_brand', true);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'post@host.test', 'name' => 'Der Host']);
        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
        $app['config']->set('invoices.seller', ['name' => null, 'address' => null]);
        $app['config']->set('invoices.number.prefix_per_brand', [1 => 'AA', 2 => 'BB']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');
        $this->artisan('migrate')->run();

        $this->app->getProvider(ServiceProvider::class)?->bootEvents();

        $kernel = $this->app->make(Kernel::class);
        foreach ([BrandCheck::class, ExportInvoices::class, PendingInvoices::class, RecheckVatIds::class, ReleaseInvoice::class, RetryInvoices::class] as $befehl) {
            $kernel->registerCommand($this->app->make($befehl));
        }

        $test = $this;
        $this->app->instance(PdfRenderer::class, new class($test) implements PdfRenderer
        {
            public function __construct(private ConsoleCommandsRunPerBrandTest $test) {}

            public function render(Invoice $invoice): string
            {
                if ($this->test->renderFails) {
                    throw new RuntimeException('renderer down');
                }

                $this->test->archiveBuilds[] = ['seller' => config('invoices.seller.name'), 'brand' => (int) $invoice->brand_id];

                return '%PDF-1.4 stub';
            }
        });

        $this->eins = Brand::updateOrCreate(['id' => 1], ['handle' => 'eins', 'name' => 'Marke Eins', 'is_default' => true]);
        $this->zwei = Brand::updateOrCreate(['id' => 2], ['handle' => 'zwei', 'name' => 'Marke Zwei', 'is_default' => false]);

        BrandContext::forget();
    }

    /** Stored through the settings layer, as the settings page does. */
    private function speichere(Brand $marke, array $werte): void
    {
        BrandContext::runFor($marke, function () use ($werte): void {
            app(SettingsManager::class)->for('invoices')->save($werte);
        });

        BrandContext::forget();
    }

    private function zahlung(Brand $marke, string $mail = 'kaeufer@example.com'): Payment
    {
        return BrandContext::runFor($marke, function () use ($mail): Payment {
            return Payment::create([
                'provider' => 'fake',
                'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
                'product' => 'kurs',
                'amount_cent' => 11900,
                'currency' => 'EUR',
                'status' => Payment::STATUS_PAID,
                'email' => $mail,
                'name' => 'Bärbel Öztürk-Weiß',
                'country' => 'DE',
                'paid_at' => now(),
            ]);
        });
    }

    private function rechnung(Brand $marke, string $mail = 'kaeufer@example.com'): Invoice
    {
        $this->nummer++;

        $rechnung = Invoice::create([
            'brand_id' => $marke->id,
            'number' => sprintf('R-%d-%04d', $marke->id, $this->nummer),
            'kind' => Invoice::KIND_INVOICE,
            'issued_at' => now(),
            'currency' => 'EUR',
            'buyer_name' => 'Maria Beispiel',
            'buyer_email' => $mail,
            'buyer_country' => 'DE',
            'seller' => ['name' => 'Verkäufer '.$marke->handle, 'email' => 'rechnung@'.$marke->handle.'.test'],
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

    // ── invoices:pending: the seller on the document ─────────────────────────

    #[Test]
    public function pending_writes_each_brands_invoice_with_that_brands_saved_seller(): void
    {
        $this->speichere($this->eins, ['seller.name' => 'Chorwerk Eins', 'seller.address' => 'Einsweg 1']);
        $this->speichere($this->zwei, ['seller.name' => 'Studio Zwei', 'seller.address' => 'Zweiweg 2']);

        $zahlungEins = $this->zahlung($this->eins);
        $zahlungZwei = $this->zahlung($this->zwei);

        $this->assertSame(0, Artisan::call('invoices:pending', ['--write' => true]), Artisan::output());

        $this->assertSame(
            'Chorwerk Eins',
            Invoice::where('payment_id', $zahlungEins->id)->firstOrFail()->seller['name'],
            'Die Rechnung von Marke Eins trägt nicht die gespeicherten Verkäuferangaben von Marke Eins.'
        );
        $this->assertSame(
            'Studio Zwei',
            Invoice::where('payment_id', $zahlungZwei->id)->firstOrFail()->seller['name'],
            'Die Rechnung von Marke Zwei trägt nicht die gespeicherten Verkäuferangaben von Marke Zwei.'
        );
    }

    #[Test]
    public function pending_with_a_brand_option_touches_only_that_brand(): void
    {
        $this->speichere($this->eins, ['seller.name' => 'Chorwerk Eins', 'seller.address' => 'Einsweg 1']);
        $this->speichere($this->zwei, ['seller.name' => 'Studio Zwei', 'seller.address' => 'Zweiweg 2']);

        $this->zahlung($this->eins);
        $zahlungZwei = $this->zahlung($this->zwei);

        Artisan::call('invoices:pending', ['--write' => true, '--brand' => 'zwei']);

        $this->assertSame([$zahlungZwei->id], Invoice::pluck('payment_id')->all());
    }

    #[Test]
    public function pending_leaves_the_current_brand_as_it_found_it(): void
    {
        $this->speichere($this->eins, ['seller.name' => 'Chorwerk Eins', 'seller.address' => 'Einsweg 1']);
        $this->zahlung($this->eins);

        Artisan::call('invoices:pending');

        $this->assertFalse(app('brand-context')->hasCurrent());
    }

    // ── invoices:retry: delivery settings ────────────────────────────────────

    #[Test]
    public function retry_honours_each_brands_saved_delivery_settings(): void
    {
        $eins = $this->rechnung($this->eins, 'eins@example.com');
        $zwei = $this->rechnung($this->zwei, 'zwei@example.com');

        // Both sends fail first, with nothing saved yet.
        $this->renderFails = true;
        InvoiceIssued::dispatch($eins);
        InvoiceIssued::dispatch($zwei);
        $this->assertSame(2, DB::table('invoice_deliveries')->where('status', 'failed')->count());

        DB::table('invoice_deliveries')->update(['updated_at' => now()->subMinutes(11)]);
        $this->renderFails = false;

        // Marke Eins has switched delivery off; Marke Zwei has its own subject.
        $this->speichere($this->eins, ['delivery.enabled' => false]);
        $this->speichere($this->zwei, ['delivery.subject' => 'Beleg :number von Zwei']);

        $this->assertSame(0, Artisan::call('invoices:retry'), Artisan::output());

        $post = Mail::mailer()->getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage())->all();

        $this->assertCount(1, $post, 'Marke Eins hat den Versand abgeschaltet, Marke Zwei nicht: genau eine Mail.');
        $this->assertSame('Beleg '.$zwei->number.' von Zwei', $post[0]->getSubject());
    }

    // ── invoices:recheck-vat-ids ─────────────────────────────────────────────

    #[Test]
    public function recheck_asks_each_brands_invoices_under_that_brands_settings(): void
    {
        $this->speichere($this->eins, ['tax.vat_id_check.timeout' => 11]);
        $this->speichere($this->zwei, ['tax.vat_id_check.timeout' => 22]);

        $offen = fn (Brand $marke, string $nr) => Invoice::create([
            'brand_id' => $marke->id,
            'number' => $nr,
            'kind' => Invoice::KIND_INVOICE,
            'issued_at' => now()->subDay(),
            'currency' => 'EUR',
            'buyer_name' => 'Wiener Werkstatt GmbH',
            'buyer_country' => 'AT',
            'buyer_vat_id' => 'ATU12345678',
            'buyer_vat_id_status' => 'pending',
            'seller' => ['name' => 'X'],
            'net_cent' => 100,
            'tax_cent' => 0,
            'gross_cent' => 100,
        ]);
        $offen($this->eins, 'E-1');
        $offen($this->zwei, 'Z-1');

        $gesehen = [];
        $this->app->bind(VatIdVerifier::class, function ($app) use (&$gesehen) {
            $gesehen[] = (int) $app['config']->get('invoices.tax.vat_id_check.timeout');

            return new class implements VatIdVerifier
            {
                public function name(): string
                {
                    return 'probe';
                }

                public function verify(string $vatId, ?string $requesterVatId = null): VatIdCheck
                {
                    return VatIdCheck::unchecked($vatId);
                }
            };
        });

        Artisan::call('invoices:recheck-vat-ids');

        $this->assertSame([11, 22], $gesehen, 'Jede Marke fragt mit ihrem eigenen gespeicherten Wert.');
    }

    // ── invoices:export ──────────────────────────────────────────────────────

    #[Test]
    public function export_writes_one_file_per_brand_with_only_that_brands_documents(): void
    {
        $eins = $this->rechnung($this->eins);
        $zwei = $this->rechnung($this->zwei);
        $ziel = sys_get_temp_dir().'/invoices-'.bin2hex(random_bytes(4)).'.csv';

        try {
            $this->assertSame(0, Artisan::call('invoices:export', [
                'what' => 'csv', '--month' => now()->format('Y-m'), '--output' => $ziel,
            ]), Artisan::output());

            $a = (string) file_get_contents(str_replace('.csv', '_marke-1.csv', $ziel));
            $b = (string) file_get_contents(str_replace('.csv', '_marke-2.csv', $ziel));

            $this->assertStringContainsString($eins->number, $a);
            $this->assertStringNotContainsString($zwei->number, $a);
            $this->assertStringContainsString($zwei->number, $b);
            $this->assertStringNotContainsString($eins->number, $b);
        } finally {
            foreach (['_marke-1', '_marke-2', ''] as $suffix) {
                @unlink(str_replace('.csv', $suffix.'.csv', $ziel));
            }
        }
    }

    #[Test]
    public function export_to_standard_output_needs_a_brand_on_a_multi_brand_host(): void
    {
        $this->rechnung($this->eins);

        $ziel = sys_get_temp_dir().'/invoices-'.bin2hex(random_bytes(4)).'.csv';

        try {
            $this->assertSame(1, Artisan::call('invoices:export', ['what' => 'csv', '--month' => now()->format('Y-m')]));
            $this->assertSame(0, Artisan::call('invoices:export', [
                'what' => 'csv', '--month' => now()->format('Y-m'), '--brand' => 'eins', '--output' => $ziel,
            ]));
            // One brand named: the file is called what the operator said.
            $this->assertFileExists($ziel);
        } finally {
            @unlink($ziel);
        }
    }

    #[Test]
    public function export_says_so_when_a_document_belongs_to_no_brand(): void
    {
        $this->rechnung($this->eins);
        $this->rechnung($this->zwei)->forceFill(['brand_id' => 0])->saveQuietly();
        DB::table('invoices')->where('number', 'like', 'R-2-%')->update(['brand_id' => 0]);
        $ziel = sys_get_temp_dir().'/invoices-'.bin2hex(random_bytes(4)).'.csv';

        try {
            $this->assertSame(1, Artisan::call('invoices:export', [
                'what' => 'csv', '--month' => now()->format('Y-m'), '--output' => $ziel,
            ]));
            $this->assertStringContainsString('gehören zu keiner Marke', Artisan::output());
        } finally {
            foreach (['_marke-1', '_marke-2'] as $suffix) {
                @unlink(str_replace('.csv', $suffix.'.csv', $ziel));
            }
        }
    }

    // ── invoices:release ─────────────────────────────────────────────────────

    #[Test]
    public function release_sends_under_the_invoices_brand_settings(): void
    {
        $zwei = $this->rechnung($this->zwei, 'zwei@example.com');

        $this->renderFails = true;
        InvoiceIssued::dispatch($zwei);
        $this->renderFails = false;

        $this->speichere($this->zwei, ['delivery.subject' => 'Beleg :number von Zwei']);

        $this->assertSame(0, Artisan::call('invoices:release', ['number' => $zwei->number]), Artisan::output());

        $post = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage())->all();
        $this->assertCount(1, $post);
        $this->assertSame('Beleg '.$zwei->number.' von Zwei', $post[0]->getSubject());
    }

    #[Test]
    public function release_of_an_invoice_without_a_brand_uses_the_default_brands_settings(): void
    {
        $alt = $this->rechnung($this->eins, 'alt@example.com');
        DB::table('invoices')->where('id', $alt->id)->update(['brand_id' => 0]);

        $this->renderFails = true;
        InvoiceIssued::dispatch($alt->fresh(['items']));
        $this->renderFails = false;

        $this->speichere($this->eins, ['delivery.subject' => 'Beleg :number von Eins']);

        $this->assertSame(0, Artisan::call('invoices:release', ['number' => $alt->number]), Artisan::output());

        $post = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage())->all();
        $this->assertCount(1, $post);
        $this->assertSame('Beleg '.$alt->number.' von Eins', $post[0]->getSubject());
    }

    #[Test]
    public function pending_gives_a_payment_without_a_brand_to_the_default_brand_only(): void
    {
        $this->speichere($this->eins, ['seller.name' => 'Chorwerk Eins', 'seller.address' => 'Einsweg 1']);
        $this->speichere($this->zwei, ['seller.name' => 'Studio Zwei', 'seller.address' => 'Zweiweg 2']);

        $waise = $this->zahlung($this->zwei);
        DB::table('payments')->where('id', $waise->id)->update(['brand_id' => 0]);

        $this->assertSame(0, Artisan::call('invoices:pending', ['--write' => true, '--brand' => 'zwei']), Artisan::output());
        $this->assertSame(0, Invoice::count(), 'Marke Zwei darf eine Zahlung ohne Marke nicht nehmen.');

        $this->assertSame(0, Artisan::call('invoices:pending', ['--write' => true, '--brand' => 'eins']), Artisan::output());
        $this->assertSame('Chorwerk Eins', Invoice::firstOrFail()->seller['name']);
    }

    #[Test]
    public function an_unknown_brand_option_is_a_message_not_a_stack_trace(): void
    {
        $this->assertSame(1, Artisan::call('invoices:pending', ['--brand' => 'gibt-es-nicht']));
        $this->assertStringContainsString('gibt es nicht', Artisan::output());
    }

    // ── a failing brand does not stop the next ───────────────────────────────

    #[Test]
    public function pending_goes_on_with_the_next_brand_after_one_fails(): void
    {
        // Marke Eins has no seller saved: its payment cannot be written.
        $this->speichere($this->zwei, ['seller.name' => 'Studio Zwei', 'seller.address' => 'Zweiweg 2']);

        $this->zahlung($this->eins);
        $zahlungZwei = $this->zahlung($this->zwei);

        $this->assertSame(1, Artisan::call('invoices:pending', ['--write' => true]));

        $this->assertSame([$zahlungZwei->id], Invoice::pluck('payment_id')->all(), 'Marke Zwei kam nicht mehr dran.');
    }

    // ── single brand: nothing changes ────────────────────────────────────────

    #[Test]
    public function on_a_single_brand_host_pending_runs_once_over_everything(): void
    {
        config(['brand-context.multi_brand' => false, 'invoices.seller' => ['name' => 'Allein', 'address' => 'Weg 1']]);
        BrandContext::forget();

        $a = $this->zahlung($this->eins);
        $b = $this->zahlung($this->zwei);

        $this->assertSame(0, Artisan::call('invoices:pending', ['--write' => true]), Artisan::output());
        $this->assertStringNotContainsString('Brand:', Artisan::output());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], Invoice::pluck('payment_id')->all());
    }

    // ── invoices:brand-check ─────────────────────────────────────────────────

    #[Test]
    public function brand_check_stays_a_cross_brand_comparison(): void
    {
        // It reads no brand setting and its subject is the mismatch BETWEEN
        // brands: a per-brand run would print the same table twice, or lose the
        // payments that belong to no brand. It must keep finding both.
        $zahlung = $this->zahlung($this->zwei);
        $rechnung = $this->rechnung($this->eins);
        $rechnung->forceFill(['payment_id' => $zahlung->id])->saveQuietly();
        DB::table('invoices')->where('id', $rechnung->id)->update(['payment_id' => $zahlung->id]);

        $exit = Artisan::call('invoices:brand-check');
        $ausgabe = Artisan::output();

        $this->assertSame(1, $exit, $ausgabe);
        $this->assertStringContainsString($rechnung->number, $ausgabe);
    }

    // ── a job pushed from the console carries the brand ──────────────────────

    #[Test]
    public function a_job_queued_from_the_console_sees_the_saved_value_in_the_worker(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'database', 'queue.connections.database' => [
            'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90,
        ]]);
        Schema::create('jobs', function ($table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $this->speichere($this->eins, ['seller.name' => 'Chorwerk Eins']);
        $this->speichere($this->zwei, ['seller.name' => 'Studio Zwei']);

        $this->rechnung($this->eins);
        $this->rechnung($this->zwei);

        $this->assertSame(0, Artisan::call('invoices:export', [
            'what' => 'pdf', '--month' => now()->format('Y-m'), '--queue' => true,
        ]), Artisan::output());

        $this->assertSame(2, DB::table('jobs')->count());
        foreach (DB::table('jobs')->pluck('payload') as $payload) {
            $this->assertStringContainsString('brandContextBrandId', $payload, 'Der Job trägt keine Marke im Payload.');
        }

        // The worker is a different process: no brand current, package values.
        BrandContext::forget();
        $this->assertNull(config('invoices.seller.name'));

        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

        $this->assertEqualsCanonicalizing(
            ['Chorwerk Eins', 'Studio Zwei'],
            array_column($this->archiveBuilds, 'seller'),
            'Der Worker hat den gespeicherten Wert der Marke nicht gesehen.'
        );
    }
}
