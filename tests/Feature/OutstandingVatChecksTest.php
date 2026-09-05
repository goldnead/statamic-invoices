<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Console\Commands\RecheckVatIds;
use Goldnead\Invoices\Cp\OutstandingVatChecks;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\VatIdCheckRecord;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Support\VatIdStatus;
use Goldnead\Invoices\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Utility;

/**
 * What happens to a purchase that got through while the service was down.
 *
 * The fallback is only defensible with this half attached. "The invoice says
 * verification pending" is a sentence on a document; somebody has to see the
 * list, and something has to ask again. Without both, letting the sale through
 * is just losing the check quietly.
 *
 * The rule underneath every test here: **the invoice does not move.** A later
 * answer is a new row, and the document goes on saying what was known on the
 * day it was written — which is also the seller's protection under § 6a Abs. 4
 * UStG, and would be thrown away by an eager update.
 */
class OutstandingVatChecksTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('statamic.sites.sites.default.url', 'http://localhost/');
        $app['config']->set('invoices.tax.merchant_vat_id', 'DE123456789');
        $app['config']->set('invoices.tax.vat_id_check.cache_hours', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench never fires Statamic's boot callbacks, so the addon's console
        // commands are never registered. Same reason bootEvents() is called by hand
        // in the sibling tests; the command itself is the real one.
        $this->app->make(Kernel::class)->registerCommand($this->app->make(RecheckVatIds::class));
    }

    private function offeneRechnung(array $werte = []): Invoice
    {
        return Invoice::create(array_merge([
            'number' => 'RE2026-09-001',
            'issued_at' => now()->subDay(),
            'currency' => 'EUR',
            'buyer_name' => 'Wiener Werkstatt GmbH',
            'buyer_country' => 'AT',
            'buyer_vat_id' => 'ATU12345678',
            'tax_zone' => 'eu-b2b',
            'buyer_vat_id_status' => VatIdStatus::Pending->value,
            'buyer_vat_id_checked_at' => now()->subDay(),
            'buyer_vat_id_service' => 'vies',
            'net_cent' => 49900,
            'tax_cent' => 0,
            'gross_cent' => 49900,
        ], $werte));
    }

    /**
     * The screen's content, rendered.
     *
     * Through the real query and the real template, but not through the Control
     * Panel's middleware — that stack wants an Inertia-shaped app around it, and
     * this package's test bench has none. What is proved here is the half that
     * belongs to this addon: which rows the query returns and what the table makes
     * of them. That a utility's view gets rendered at all is Statamic's business,
     * and the test above checks that the utility is registered to do it.
     */
    private function bildschirm(): string
    {
        return view('invoices::cp.pending-vat-checks', app(OutstandingVatChecks::class)())->render();
    }

    // ── The screen ──────────────────────────────────────────────────────────

    #[Test]
    public function the_control_panel_lists_what_is_still_outstanding(): void
    {
        $this->offeneRechnung();

        $bildschirm = $this->bildschirm();

        $this->assertStringContainsString('RE2026-09-001', $bildschirm);
        $this->assertStringContainsString('ATU12345678', $bildschirm);
        $this->assertStringContainsString('EU, Unternehmen', $bildschirm);
        $this->assertStringContainsString('noch nicht', $bildschirm, 'ungeprüft heißt: noch nicht nachgesehen');
    }

    #[Test]
    public function an_ordinary_invoice_does_not_appear_on_that_list(): void
    {
        // The distinction the screen lives or dies by. A domestic invoice never had
        // a check to be outstanding, and putting it on a list of outstanding work
        // is how a list stops being read.
        $this->offeneRechnung([
            'number' => 'RE2026-09-002',
            'buyer_country' => 'DE',
            'buyer_vat_id' => null,
            'tax_zone' => 'de',
            'buyer_vat_id_status' => null,
            'buyer_vat_id_service' => null,
        ]);

        $bildschirm = $this->bildschirm();

        $this->assertStringNotContainsString('RE2026-09-002', $bildschirm);
        $this->assertStringContainsString('Nichts offen', $bildschirm);
    }

    #[Test]
    public function an_eu_invoice_nobody_ever_asked_about_does_appear(): void
    {
        // The class that used to be invisible: a number on a reverse-charge document
        // with no verdict behind it at all — an older invoice, or a payment that
        // reached the writer past the checkout. Nothing counted those, so nothing
        // knew they existed.
        $this->offeneRechnung([
            'number' => 'RE2026-09-004',
            'buyer_vat_id_status' => null,
            'buyer_vat_id_service' => null,
        ]);

        $this->assertStringContainsString('RE2026-09-004', $this->bildschirm());
    }

    #[Test]
    public function a_number_that_is_deliberately_never_confirmed_stays_off_the_list(): void
    {
        // Domestic and third-country numbers are recorded and not checked, on
        // purpose. Putting them on a list of outstanding work would fill it with
        // rows nobody can ever close, and a list like that stops being read.
        $this->offeneRechnung([
            'number' => 'RE2026-09-005',
            'buyer_country' => 'DE',
            'buyer_vat_id' => 'DE987654321',
            'tax_zone' => 'de',
            'buyer_vat_id_status' => VatIdStatus::Unchecked->value,
        ]);

        $this->offeneRechnung([
            'number' => 'RE2026-09-006',
            'buyer_country' => 'US',
            'buyer_vat_id' => 'US123456789',
            'tax_zone' => 'third-country-b2b',
            'buyer_vat_id_status' => VatIdStatus::Unchecked->value,
        ]);

        $bildschirm = $this->bildschirm();

        $this->assertStringNotContainsString('RE2026-09-005', $bildschirm);
        $this->assertStringNotContainsString('RE2026-09-006', $bildschirm);
        $this->assertStringContainsString('Nichts offen', $bildschirm);
    }

    #[Test]
    public function a_confirmed_invoice_does_not_appear_either(): void
    {
        $this->offeneRechnung([
            'number' => 'RE2026-09-003',
            'buyer_vat_id_status' => VatIdStatus::Valid->value,
        ]);

        $this->assertStringNotContainsString('RE2026-09-003', $this->bildschirm());
    }

    #[Test]
    public function the_screen_is_actually_registered_as_a_utility(): void
    {
        // Otherwise the query, the template and the tests above all work and the
        // screen exists nowhere a person can open it. Registration runs from
        // bootAddon(), which testbench does not fire, so it is invoked here.
        $this->app->getProvider(ServiceProvider::class)?->bootAddon();

        // `extend()` only queues; the Control Panel runs the queue through
        // BootUtilities on a request, and there is no such request here.
        Utility::boot();

        $utility = Utility::find('vat-checks');

        $this->assertNotNull($utility, 'die Utility ist nicht angemeldet');
        $this->assertSame('invoices::cp.pending-vat-checks', $utility->view());

        // And its data callback is the real one, so the screen cannot be wired to
        // an empty array while every other test passes.
        $daten = $utility->viewData(Request::create('/'));

        $this->assertArrayHasKey('invoices', $daten);
        $this->assertArrayHasKey('total', $daten);
    }

    #[Test]
    public function a_number_that_later_turned_out_invalid_stands_out_on_the_list(): void
    {
        // The one row on this screen that is a task rather than a note. If it looked
        // like the others, the screen would be a list nobody finishes reading.
        $rechnung = $this->offeneRechnung();

        $rechnung->vatIdChecks()->create([
            'vat_id' => 'ATU12345678',
            'status' => VatIdStatus::Invalid->value,
            'checked_at' => now(),
            'service' => 'vies',
        ]);

        $this->assertStringContainsString('ungültig, bitte ansehen', $this->bildschirm());
    }

    // ── The second look ─────────────────────────────────────────────────────

    #[Test]
    public function the_command_asks_again_and_writes_a_row_without_touching_the_invoice(): void
    {
        $rechnung = $this->offeneRechnung();
        // Read back rather than taken off the freshly created object: that one has
        // no database defaults on it yet, so half the columns would compare as
        // "unchanged" by being absent from both sides.
        $vorher = $rechnung->fresh()->getAttributes();

        Http::fake(['*' => Http::response([
            'countryCode' => 'AT',
            'vatNumber' => 'U12345678',
            'valid' => true,
            'requestIdentifier' => 'WAPIAAAAX0000777',
        ], 200)]);

        $this->artisan('invoices:recheck-vat-ids')->assertSuccessful();

        $zeile = VatIdCheckRecord::firstOrFail();

        $this->assertSame($rechnung->id, $zeile->invoice_id);
        $this->assertSame(VatIdStatus::Valid, $zeile->verdict());
        $this->assertSame('WAPIAAAAX0000777', $zeile->reference);

        // And the document is exactly as it was. Not "mostly": every column, because
        // a single quietly updated field is the whole failure this table exists to
        // avoid.
        $this->assertSame($vorher, $rechnung->fresh()->getAttributes());
    }

    #[Test]
    public function a_number_that_now_comes_back_invalid_is_named_and_fails_the_run(): void
    {
        $this->offeneRechnung();

        Http::fake(['*' => Http::response(['valid' => false], 200)]);

        $this->artisan('invoices:recheck-vat-ids')
            ->expectsOutputToContain('RE2026-09-001')
            ->assertFailed();

        // Still pending on the document. What to do about the contradiction is a
        // decision, and the run has just put it in front of a person.
        $this->assertSame(VatIdStatus::Pending, Invoice::firstOrFail()->vatIdStatus());
        $this->assertTrue(VatIdCheckRecord::firstOrFail()->contradicts(Invoice::firstOrFail()));
    }

    #[Test]
    public function a_service_that_is_still_down_is_not_a_failed_run(): void
    {
        // Otherwise a week-long outage sends a week of alerts about nothing, and the
        // one run that finds a real contradiction arrives in a folder nobody opens
        // any more.
        $this->offeneRechnung();

        Http::fake(['*' => Http::response('', 503)]);

        $this->artisan('invoices:recheck-vat-ids')->assertSuccessful();

        $this->assertSame(VatIdStatus::Pending, VatIdCheckRecord::firstOrFail()->verdict());
    }

    #[Test]
    public function every_run_leaves_a_trace_even_when_nothing_changed(): void
    {
        $rechnung = $this->offeneRechnung();

        Http::fake(['*' => Http::response('', 503)]);

        $this->artisan('invoices:recheck-vat-ids')->assertSuccessful();
        $this->artisan('invoices:recheck-vat-ids')->assertSuccessful();

        // "Was this one ever looked at again" is the question a tax office asks, and
        // a run that only records the interesting cases cannot answer it.
        $this->assertSame(2, $rechnung->vatIdChecks()->count());
    }

    #[Test]
    public function nothing_outstanding_is_a_quiet_success(): void
    {
        Http::fake();

        $this->artisan('invoices:recheck-vat-ids')
            ->expectsOutputToContain('Nothing outstanding')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
