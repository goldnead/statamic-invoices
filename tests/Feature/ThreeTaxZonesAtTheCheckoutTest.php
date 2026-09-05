<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Contracts\VatIdVerifier;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Support\Renderer;
use Goldnead\Invoices\Support\TaxZone;
use Goldnead\Invoices\Support\VatIdStatus;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * The three zones, walked from an HTTP request to the document that comes out.
 *
 * Every case here goes in through a route with the gate on it, through the real
 * `Checkout::start`, through the real `PaymentPaid` listener, and ends by reading
 * the invoice row and the rendered document. Nothing calls `TaxRules` directly.
 *
 * That is deliberate, and it is the lesson from `insights`: a test that asks the
 * calculation class what it thinks proves the calculation class agrees with
 * itself. The wiring between the checkout, the payment's meta, the writer and
 * the template is where a case like "the confirmation was frozen but never
 * reached the invoice" lives, and only a test that walks the whole way sees it.
 *
 * The two things faked are the confirmation service, because it is somebody
 * else's server, and the payment provider, because it takes money.
 */
class ThreeTaxZonesAtTheCheckoutTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Statamic resolves the site from the request URL and answers 404 when no
        // site matches. The base TestCase sets app.url to a host the test client
        // never calls, which is invisible until a test makes an actual request —
        // as every test in this file does.
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('statamic.sites.sites.default.url', 'http://localhost/');

        // Adrian's actual setup: a small business seller (§ 19 UStG) with a VAT ID
        // of his own, selling to businesses only. Both matter — without the number
        // an EU invoice is incomplete, and with § 19 on, the whole point is that it
        // must not swallow the cross-border cases.
        $app['config']->set('invoices.tax', [
            'small_business' => ['enabled' => true, 'eu_scheme' => false, 'eu_threshold_mode' => 'below'],
            'merchant_country' => 'DE',
            'merchant_vat_id' => 'DE123456789',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard', 'noten' => 'reduced'],
            'zones' => ['de' => ['countries' => ['DE'], 'rates' => ['standard' => 1900, 'reduced' => 700]]],
            'vat_id_check' => ['enabled' => true, 'service' => 'vies', 'timeout' => 2, 'cache_hours' => 0],
            'business_only' => ['enabled' => true, 'require_company' => true],
        ]);

        $app['config']->set('invoices.seller', [
            'name' => 'Adrian Goldner',
            'address' => "Beispielweg 1\n60313 Frankfurt",
            'vat_id' => 'DE123456789',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench never fires Statamic's boot callbacks, so the listener that
        // writes the invoice hangs off nothing — and going through the event is
        // exactly the path these tests are about. Same reason as in
        // TheBrandComesFromThePaymentTest.
        $this->app->getProvider(ServiceProvider::class)?->bootEvents();

        $this->fakeGateway();
    }

    // ── The route the buyer's form asks while typing ─────────────────────────

    #[Test]
    public function the_form_is_told_no_before_the_buyer_reaches_the_card_form(): void
    {
        // The whole reason this endpoint exists: the refusal has to arrive while
        // the field can still be corrected, not after a provider has been called.
        $antwort = $this->postJson('/!/invoices/buyer-check', [
            'country' => 'AT',
            'company' => 'Wiener Werkstatt GmbH',
        ]);

        $antwort->assertStatus(422)
            ->assertJsonPath('admitted', false)
            ->assertJsonPath('code', 'vat_id_missing');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function the_form_is_told_yes_with_the_zone_it_would_land_in(): void
    {
        $this->viesSays(true, 'WAPIAAAAX0000001');

        $this->postJson('/!/invoices/buyer-check', [
            'country' => 'AT',
            'company' => 'Wiener Werkstatt GmbH',
            'vat_id' => 'ATU12345678',
        ])
            ->assertOk()
            ->assertJsonPath('admitted', true)
            ->assertJsonPath('zone', 'eu-b2b')
            ->assertJsonPath('vat_id_status', 'valid')
            // The reference is evidence for the seller and stays out of a response
            // anybody can ask for.
            ->assertJsonMissingPath('vat_id_reference');
    }

    // ── Case 1: a German business ────────────────────────────────────────────

    #[Test]
    public function a_german_business_gets_an_invoice_without_vat_and_the_paragraph_19_note(): void
    {
        $this->kaufen(['country' => 'DE', 'company' => 'Chorwerk GmbH'])->assertOk();

        $rechnung = Invoice::firstOrFail();

        $this->assertSame(0, $rechnung->tax_cent);
        $this->assertSame(24900, $rechnung->gross_cent, 'der Preis aus dem Katalog');
        $this->assertSame(24900, $rechnung->net_cent, 'ohne Steuer ist netto gleich brutto');
        $this->assertSame(TaxZone::Domestic->value, $rechnung->tax_zone);
        $this->assertStringContainsString('§ 19 UStG', (string) $rechnung->tax_reason);

        // No number was given, so nothing was checked — and the document says
        // nothing rather than something reassuring.
        $this->assertNull($rechnung->buyer_vat_id_status);
        $this->assertStringNotContainsString('verification pending', $this->beleg($rechnung));
    }

    #[Test]
    public function the_invoice_is_made_out_to_the_company_not_to_whoever_typed(): void
    {
        // § 14 Abs. 4 Nr. 1 UStG wants the recipient of the supply named. The gate
        // insists on a company name, and for a while that name got no further than
        // the gate: the document went out to the person who filled in the form, and
        // the buyer's accountant could not book it against their business.
        $this->kaufen(['country' => 'DE', 'company' => 'Chorwerk GmbH'])->assertOk();

        $rechnung = Invoice::firstOrFail();

        $this->assertSame('Chorwerk GmbH', $rechnung->buyer_name);
        $this->assertStringContainsString('Chorwerk GmbH', $this->beleg($rechnung));

        // And the person is not lost, they are just not the recipient.
        $this->assertSame('Bärbel Öztürk-Weiß', $rechnung->meta['buyer_contact'] ?? null);
    }

    #[Test]
    public function a_buyer_without_a_company_name_does_not_buy(): void
    {
        // "Businesses only" with a blank company field is a claim nobody checked.
        $this->kaufen(['country' => 'DE'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'company_missing');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function a_domestic_vat_id_is_recorded_and_deliberately_not_confirmed(): void
    {
        // It decides nothing about the tax here, and asking costs something real:
        // an outage would freeze "pending" onto a domestic invoice and leave it on
        // the outstanding list for a question nobody had.
        Http::fake();

        $this->kaufen([
            'country' => 'DE',
            'company' => 'Chorwerk GmbH',
            'vat_id' => 'DE987654321',
        ])->assertOk();

        Http::assertNothingSent();

        $rechnung = Invoice::firstOrFail();

        $this->assertSame('DE987654321', $rechnung->buyer_vat_id);
        $this->assertSame(VatIdStatus::Unchecked->value, $rechnung->buyer_vat_id_status);
        // Said out loud on the document: unconfirmed must not look like confirmed by
        // both of them saying nothing.
        $this->assertStringContainsString('nicht bestätigt', $this->beleg($rechnung));
    }

    #[Test]
    public function a_third_country_tax_number_is_never_sent_to_the_eu_service(): void
    {
        // VIES only knows EU numbers. Asking it about a US one gets an answer to a
        // question it was never asked — most likely "invalid", which would then sit
        // frozen on an invoice as a verdict nobody is entitled to.
        Http::fake();

        $this->kaufen([
            'country' => 'US',
            'company' => 'Austin Web Co',
            'business_confirmed' => true,
            'vat_id' => 'US12-3456789',
        ])->assertOk();

        Http::assertNothingSent();

        $this->assertSame(VatIdStatus::Unchecked->value, Invoice::firstOrFail()->buyer_vat_id_status);
    }

    #[Test]
    public function a_form_post_is_sent_back_with_the_message_the_buyer_needs(): void
    {
        // Not a 422 carrying a Location header: a browser does not follow that, the
        // buyer gets a blank page, and the message that tells them what to fix sits
        // in the session until they navigate somewhere by hand.
        $antwort = $this->post('/test-checkout', ['country' => 'AT', 'company' => 'Wiener Werkstatt GmbH']);

        $antwort->assertRedirect();
        $antwort->assertSessionHasErrors(['vat_id']);

        $this->assertSame(0, Payment::count());
    }

    // ── Case 2: an EU business with a confirmed number ───────────────────────

    #[Test]
    public function an_eu_business_gets_reverse_charge_with_both_numbers_and_the_frozen_check(): void
    {
        $this->viesSays(true, 'WAPIAAAAX0000042');

        $this->kaufen([
            'country' => 'AT',
            'company' => 'Wiener Werkstatt GmbH',
            'vat_id' => 'ATU12345678',
        ])->assertOk();

        $rechnung = Invoice::firstOrFail();

        $this->assertSame(0, $rechnung->tax_cent);
        $this->assertSame(TaxZone::EuBusiness->value, $rechnung->tax_zone);

        // § 14a Abs. 5 UStG prescribes the German phrase; the English one is what
        // makes the document usable for the buyer's own accountant.
        $this->assertStringContainsString('Steuerschuldnerschaft des Leistungsempfängers', (string) $rechnung->tax_reason);
        $this->assertStringContainsString('Reverse charge', (string) $rechnung->tax_reason);

        // The check, frozen. Not a flag — the verdict, the moment, the service and
        // the reference, because that is what makes it followable years later.
        $this->assertSame(VatIdStatus::Valid->value, $rechnung->buyer_vat_id_status);
        $this->assertSame('vies', $rechnung->buyer_vat_id_service);
        $this->assertSame('WAPIAAAAX0000042', $rechnung->buyer_vat_id_reference);
        $this->assertNotNull($rechnung->buyer_vat_id_checked_at);

        $beleg = $this->beleg($rechnung);

        // § 14a Abs. 1 UStG: both numbers on the document.
        $this->assertStringContainsString('ATU12345678', $beleg);
        $this->assertStringContainsString('DE123456789', $beleg);
        $this->assertStringContainsString('Nummer bestätigt', $beleg);
        $this->assertStringNotContainsString('verification pending', $beleg);
    }

    #[Test]
    public function paragraph_19_does_not_swallow_the_reverse_charge_case(): void
    {
        // The bug this ticket exists to fix. § 19 UStG is a domestic rule; for a
        // supply to a business abroad the place of supply is theirs, so a § 19 note
        // is not merely unhelpful, it is the wrong mandatory particular.
        $this->viesSays(true);

        $this->kaufen([
            'country' => 'AT',
            'company' => 'Wiener Werkstatt GmbH',
            'vat_id' => 'ATU12345678',
        ])->assertOk();

        $rechnung = Invoice::firstOrFail();

        $this->assertStringNotContainsString('§ 19', (string) $rechnung->tax_reason);
        $this->assertStringNotContainsString('§ 19', $this->beleg($rechnung));
    }

    // ── Case 3: a business outside the EU ────────────────────────────────────

    #[Test]
    public function a_third_country_business_gets_not_taxable_in_germany(): void
    {
        Http::fake();

        $this->kaufen([
            'country' => 'US',
            'company' => 'Austin Web Co',
            'business_confirmed' => true,
        ])->assertOk();

        $rechnung = Invoice::firstOrFail();

        $this->assertSame(0, $rechnung->tax_cent);
        $this->assertSame(TaxZone::ThirdCountryBusiness->value, $rechnung->tax_zone);
        $this->assertStringContainsString('Nicht im Inland steuerbar', (string) $rechnung->tax_reason);
        $this->assertStringContainsString('Not taxable in Germany', (string) $rechnung->tax_reason);

        // Reverse charge is not the phrase outside the EU. Whether the buyer's own
        // country shifts the liability is that country's business.
        $this->assertStringNotContainsString('Steuerschuldnerschaft', (string) $rechnung->tax_reason);

        // No confirmation service was involved, so the document claims none.
        Http::assertNothingSent();
    }

    #[Test]
    public function a_third_country_buyer_who_did_not_say_they_are_a_business_is_refused(): void
    {
        $antwort = $this->kaufen(['country' => 'US', 'company' => 'Austin Web Co']);

        $antwort->assertStatus(422)->assertJsonPath('code', 'business_not_confirmed');
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Invoice::count());
    }

    // ── Case 4: an EU buyer without a valid number does not buy ──────────────

    #[Test]
    public function an_eu_buyer_without_a_number_never_reaches_the_provider(): void
    {
        $antwort = $this->kaufen(['country' => 'AT', 'company' => 'Wiener Werkstatt GmbH']);

        $antwort->assertStatus(422)->assertJsonPath('code', 'vat_id_missing');

        // The refusal is worth nothing if a payment row already exists behind it.
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Invoice::count());
    }

    #[Test]
    public function an_eu_buyer_whose_number_the_service_rejects_does_not_buy(): void
    {
        $this->viesSays(false);

        $antwort = $this->kaufen([
            'country' => 'AT',
            'company' => 'Wiener Werkstatt GmbH',
            'vat_id' => 'ATU99999999',
        ]);

        $antwort->assertStatus(422)->assertJsonPath('code', 'vat_id_not_confirmed');
        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function a_number_from_the_wrong_country_is_refused_before_anybody_is_asked(): void
    {
        Http::fake();

        $antwort = $this->kaufen([
            'country' => 'AT',
            'company' => 'Wiener Werkstatt GmbH',
            'vat_id' => 'FR12345678901',
        ]);

        $antwort->assertStatus(422)->assertJsonPath('code', 'vat_id_country_mismatch');
        Http::assertNothingSent();
    }

    #[Test]
    public function a_client_cannot_post_its_own_confirmation(): void
    {
        // The gate has to be the only source of that value. Otherwise "confirmed by
        // VIES" is a field anybody can type, and the invoice says it anyway.
        $this->viesSays(false);

        $antwort = $this->kaufen([
            'country' => 'AT',
            'company' => 'Wiener Werkstatt GmbH',
            'vat_id' => 'ATU99999999',
            'vat_id_check' => ['vat_id' => 'ATU99999999', 'status' => 'valid', 'service' => 'vies'],
        ]);

        $antwort->assertStatus(422);
        $this->assertSame(0, Invoice::count());
    }

    // ── The rule, switched off ───────────────────────────────────────────────

    #[Test]
    public function turning_the_business_only_rule_off_lets_a_consumer_buy(): void
    {
        // The switch exists in the config and promised exactly this. It promised it
        // for a while without doing it — the gate read only `require_company` — so
        // an operator who set INVOICES_BUSINESS_ONLY=false went on losing every
        // consumer order to a 422 with no hint that the flag was dead.
        config(['invoices.tax.business_only.enabled' => false]);

        $this->kaufen(['country' => 'AT'])->assertOk();

        $rechnung = Invoice::firstOrFail();

        // Admitted, and without a zone: a consumer in Vienna is none of the three
        // cases, and calling them domestic would be the invoice claiming one.
        $this->assertNull($rechnung->tax_zone);
        $this->assertNull($rechnung->buyer_vat_id_status, 'ohne Nummer gibt es nichts zu prüfen');
    }

    #[Test]
    public function the_rule_still_stands_when_only_the_company_field_is_waived(): void
    {
        // The two flags are separate on purpose, and this is the pair that would go
        // unnoticed if one of them silently answered for both.
        config(['invoices.tax.business_only.require_company' => false]);

        $this->kaufen(['country' => 'AT'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'vat_id_missing');
    }

    // ── The cache in front of the service ────────────────────────────────────

    #[Test]
    public function a_confirmed_number_is_asked_about_once(): void
    {
        // The production default is a week of caching, and no test used to run
        // through that code at all — both test classes set it to zero.
        config(['invoices.tax.vat_id_check.cache_hours' => 168]);
        $this->viesSays(true);

        $eingaben = ['country' => 'AT', 'company' => 'Wiener Werkstatt GmbH', 'vat_id' => 'ATU12345678'];

        $this->postJson('/!/invoices/buyer-check', $eingaben)->assertOk();
        $this->postJson('/!/invoices/buyer-check', $eingaben)->assertOk();

        Http::assertSentCount(1);
    }

    #[Test]
    public function an_outage_is_never_remembered(): void
    {
        // Caching a non-answer would turn one minute of downtime into a week of
        // invoices that all say "verification pending" for a service that came back
        // straight away.
        config(['invoices.tax.vat_id_check.cache_hours' => 168]);
        Http::fake(['*' => Http::response('', 503)]);

        $eingaben = ['country' => 'AT', 'company' => 'Wiener Werkstatt GmbH', 'vat_id' => 'ATU12345678'];

        $this->postJson('/!/invoices/buyer-check', $eingaben)->assertOk();
        $this->postJson('/!/invoices/buyer-check', $eingaben)->assertOk();

        Http::assertSentCount(2);
    }

    #[Test]
    public function a_rejected_number_is_never_remembered_either(): void
    {
        // The usual cause is a typo, and the buyer's second attempt has to reach the
        // service — otherwise correcting it does nothing for a week.
        config(['invoices.tax.vat_id_check.cache_hours' => 168]);
        $this->viesSays(false);

        $eingaben = ['country' => 'AT', 'company' => 'Wiener Werkstatt GmbH', 'vat_id' => 'ATU99999999'];

        $this->postJson('/!/invoices/buyer-check', $eingaben)->assertStatus(422);
        $this->postJson('/!/invoices/buyer-check', $eingaben)->assertStatus(422);

        Http::assertSentCount(2);
    }

    // ── The fallback: the service is down and the sale still happens ─────────

    #[Test]
    public function an_unreachable_service_does_not_cost_the_sale_and_the_invoice_says_so(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->kaufen([
            'country' => 'AT',
            'company' => 'Wiener Werkstatt GmbH',
            'vat_id' => 'ATU12345678',
        ])->assertOk();

        $rechnung = Invoice::firstOrFail();

        // The sale went through, and reverse charge still applies: the buyer gave a
        // number, and losing a paid order to somebody else's outage is the worse
        // failure. What changes is the sentence, not the mechanism.
        $this->assertSame(TaxZone::EuBusiness->value, $rechnung->tax_zone);
        $this->assertStringContainsString('Steuerschuldnerschaft des Leistungsempfängers', (string) $rechnung->tax_reason);

        $this->assertSame(VatIdStatus::Pending->value, $rechnung->buyer_vat_id_status);
        $this->assertNotNull($rechnung->buyer_vat_id_checked_at, 'auch ein Fehlversuch hat einen Zeitpunkt');
        $this->assertNull($rechnung->buyer_vat_id_reference, 'ohne Antwort gibt es kein Aktenzeichen');

        $beleg = $this->beleg($rechnung);

        $this->assertStringContainsString('VAT ID provided, verification pending', $beleg);
        $this->assertStringContainsString('Bestätigung ausstehend', $beleg);
        // The one thing the document must never say when nothing was confirmed.
        $this->assertStringNotContainsString('Nummer bestätigt', $beleg);
    }

    /**
     * Every shape of non-answer ends as "pending", and none of them as "invalid".
     *
     * Filing an outage as "this number is not valid" would refuse a legitimate
     * business at the checkout and tell them their correct number is wrong. Each
     * of these arrives differently, and four of the six inside HTTP 200.
     */
    #[Test]
    public function no_shape_of_broken_answer_becomes_a_verdict(): void
    {
        $faelle = [
            'HTTP 500' => Http::response('', 500),
            'kein JSON' => Http::response('<html>Gateway Timeout</html>', 200),
            'leerer Körper' => Http::response('', 200),
            'Mitgliedstaat offline' => Http::response(['userError' => 'MS_UNAVAILABLE'], 200),
            'Antwort ohne Urteil' => Http::response(['countryCode' => 'AT', 'vatNumber' => 'U12345678'], 200),
            'valid ist keine Wahrheit' => Http::response(['valid' => 'yes'], 200),
        ];

        foreach ($faelle as $name => $antwort) {
            Http::fake(['*' => $antwort]);

            $pruefung = app(VatIdVerifier::class)->verify('ATU12345678', 'DE123456789');

            $this->assertSame(
                VatIdStatus::Pending,
                $pruefung->status,
                sprintf('"%s" hätte ein Urteil vorgetäuscht statt eine offene Prüfung', $name),
            );
            $this->assertNotNull($pruefung->failure, sprintf('"%s" schweigt über den Grund', $name));
        }
    }

    #[Test]
    public function a_service_that_rejects_the_number_is_an_answer_and_not_an_outage(): void
    {
        // The other side of the same coin: INVALID_INPUT is the service answering,
        // and treating it as an outage would let a nonsense number buy.
        Http::fake(['*' => Http::response(['userError' => 'INVALID_INPUT'], 200)]);

        $pruefung = app(VatIdVerifier::class)->verify('ATU00000000', 'DE123456789');

        $this->assertSame(VatIdStatus::Invalid, $pruefung->status);
    }

    #[Test]
    public function the_enquiry_carries_the_sellers_own_number_so_the_answer_is_quotable(): void
    {
        $this->viesSays(true);

        $this->kaufen([
            'country' => 'AT',
            'company' => 'Wiener Werkstatt GmbH',
            'vat_id' => 'ATU12345678',
        ])->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();

            // Without these two the enquiry is a simple one, and a simple one gives
            // a yes with nothing behind it — no reference, nothing to quote later.
            return ($body['requesterMemberStateCode'] ?? null) === 'DE'
                && ($body['requesterNumber'] ?? null) === '123456789'
                && ($body['countryCode'] ?? null) === 'AT';
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * A checkout route with the gate on it, as a host would wire it.
     *
     * Behind the gate it does what a real checkout does: start the payment through
     * `Checkout::start` with the frozen check in its meta, then let the provider's
     * confirmation arrive as `PaymentPaid`. Nothing here reaches into this package.
     */
    protected function defineWebRoutes($router): void
    {
        // The sibling's webhook route, if nobody else has it.
        //
        // `Checkout::start` builds the callback URL from this name before it calls
        // the provider. The sibling registers it through Statamic's addon boot,
        // which only runs once the addon manifest resolves the package — and that
        // resolution reads a composer.lock the test harness copies into place. It is
        // therefore present on a machine that has run the suite before and absent on
        // a fresh checkout, which is exactly the shape of failure that passes locally
        // and fails in CI.
        //
        // Standing in for it here keeps the subject of these tests the gate in front
        // of the checkout, rather than the sibling's registration order.
        if (! Route::has('statamic-payments.webhook')) {
            Route::post('/test-webhook', fn () => response()->noContent())
                ->name('statamic-payments.webhook');
        }

        Route::post('/test-checkout', function (Request $request) {
            $ergebnis = app(Checkout::class)->start(
                products: 'kurs',
                buyer: [
                    'email' => 'wer@example.com',
                    'name' => 'Bärbel Öztürk-Weiß',
                    'country' => $request->input('country'),
                ],
                details: ['meta' => array_filter([
                    'vat_id' => $request->input('vat_id'),
                    'company' => $request->input('company'),
                    'business_confirmed' => $request->boolean('business_confirmed'),
                    // Put there by the gate, never by the client.
                    'vat_id_check' => $request->input('vat_id_check'),
                ], fn ($v) => $v !== null && $v !== false)],
            );

            $ergebnis->payment->update(['status' => Payment::STATUS_PAID, 'paid_at' => now()]);

            PaymentPaid::dispatch($ergebnis->payment->fresh());

            return response()->json(['number' => Invoice::first()?->number]);
        })->middleware(['web', 'invoices.business-buyer']);
    }

    private function kaufen(array $felder)
    {
        return $this->postJson('/test-checkout', $felder);
    }

    private function viesSays(bool $valid, ?string $reference = 'WAPIAAAAX0000001'): void
    {
        Http::fake(['*' => Http::response(array_filter([
            'countryCode' => 'AT',
            'vatNumber' => 'U12345678',
            'requestDate' => '2026-09-05+02:00',
            'valid' => $valid,
            'name' => 'Wiener Werkstatt GmbH',
            'address' => 'Ringstraße 1, 1010 Wien',
            'requestIdentifier' => $valid ? $reference : null,
        ], fn ($v) => $v !== null), 200)]);
    }

    /**
     * The document as the buyer sees it, through the package's own renderer.
     *
     * Not `view('invoices::invoice', …)` with hand-assembled data: the renderer is
     * what builds that data, and a test that assembles it itself would keep
     * passing while the real document lost a field.
     */
    private function beleg(Invoice $rechnung): string
    {
        return app(Renderer::class)->html($rechnung->load('items'));
    }

    private function fakeGateway(): void
    {
        $this->app->bind(PaymentGateway::class, fn () => new class implements PaymentGateway
        {
            public function createPayment(array $payload): CheckoutSession
            {
                return new CheckoutSession('tr_'.bin2hex(random_bytes(4)), 'https://pay.test/redirect');
            }

            public function fetch(string $providerId): RemotePayment
            {
                throw new \RuntimeException('Der Test holt keine Zahlung nach.');
            }

            public function provider(): string
            {
                return 'fake';
            }
        });
    }
}
