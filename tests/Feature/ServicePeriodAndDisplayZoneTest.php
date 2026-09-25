<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\DisplayTime;
use Goldnead\Invoices\Support\Renderer;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Leistungszeitraum and the day on the document (Gesamtprüfung 25.09.2026).
 *
 * The annual plan's receipt showed one "Leistungsdatum" (shots/G-09e). An
 * annual plan is a supply over a period, and § 14 Abs. 4 Nr. 6 UStG wants the
 * period stated. It comes from the plan's rhythm: from the day paid to the day
 * before the next charge.
 *
 * And the day itself is the shop's day, not the server's: a purchase at 00:30
 * in Berlin is dated that day, although UTC still says the evening before. The
 * dates are frozen onto the invoice when it is written, so changing the zone
 * later does not move a single byte of a document already issued.
 */
class ServicePeriodAndDisplayZoneTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.display_timezone', 'Europe/Berlin');
        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900, 'reduced' => 700]]],
        ]);
        $app['config']->set('statamic-payments.products', [
            'kurs' => ['name' => 'Chorleitungskurs', 'amount_cent' => 24900, 'digital' => true],
            'chortarif' => ['name' => 'Chortarif (jährlich)', 'amount_cent' => 7900, 'digital' => true, 'interval' => '12 months'],
            'monatsabo' => ['name' => 'Monatsabo', 'amount_cent' => 900, 'digital' => true, 'interval' => '1 month'],
        ]);
    }

    private function payment(string $product, string $paidAtUtc, array $extra = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => $product,
            'amount_cent' => 7900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'chor@example.com',
            'name' => 'Testchor 2 e.V.',
            'country' => 'DE',
            'paid_at' => Carbon::parse($paidAtUtc, 'UTC'),
        ], $extra));
    }

    private function html(Invoice $invoice): string
    {
        return app(Renderer::class)->html($invoice->fresh(['items']));
    }

    #[Test]
    public function the_guard_display_zone_and_application_zone_differ(): void
    {
        $this->assertNotSame(config('app.timezone'), DisplayTime::zone());
        $this->assertSame('Europe/Berlin', DisplayTime::zone());
    }

    #[Test]
    public function an_annual_plan_states_a_service_period_from_its_rhythm(): void
    {
        $invoice = app(InvoiceWriter::class)->forPayment($this->payment('chortarif', '2026-09-25 19:06:00'));

        $this->assertSame(['from' => '2026-09-25', 'to' => '2027-09-24'], $invoice->meta['service_period'] ?? null);

        $html = $this->html($invoice);
        // The labels, not the words: a CSS comment in the template mentions both.
        $this->assertStringContainsString('<span class="marke-label">Leistungszeitraum</span>', $html);
        $this->assertStringContainsString('25.09.2026 bis 24.09.2027', $html);
        $this->assertStringNotContainsString('<span class="marke-label">Leistungsdatum</span>', $html);
    }

    #[Test]
    public function a_cycle_takes_the_rhythm_of_its_subscription(): void
    {
        // The oldest payments this addon accepts (^1.14) cannot link a
        // payment to its subscription yet; there a cycle falls back to the
        // catalogue's rhythm, which the first test covers.
        if (! method_exists(Payment::class, 'subscription') || ! class_exists(Subscription::class)) {
            $this->markTestSkipped('this statamic-payments has no payment→subscription link');
        }

        $subscription = Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_1',
            'customer_reference' => 'cst_1',
            'product' => 'kurs',
            'amount_cent' => 900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 1,
            'status' => Subscription::STATUS_ACTIVE,
            'email' => 'chor@example.com',
        ]);

        $invoice = app(InvoiceWriter::class)->forPayment($this->payment('kurs', '2026-01-31 10:00:00', [
            'amount_cent' => 900,
            'subscription_id' => $subscription->id,
        ]));

        // No overflow: 31 January plus a month is 28 February, the period
        // ends the day before.
        $this->assertSame(['from' => '2026-01-31', 'to' => '2026-02-27'], $invoice->meta['service_period'] ?? null);
    }

    #[Test]
    public function a_one_off_sale_keeps_its_service_date(): void
    {
        $invoice = app(InvoiceWriter::class)->forPayment($this->payment('kurs', '2026-09-25 19:06:00', ['amount_cent' => 24900]));

        $this->assertArrayNotHasKey('service_period', $invoice->meta ?? []);

        $html = $this->html($invoice);
        $this->assertStringContainsString('<span class="marke-label">Leistungsdatum</span>', $html);
        $this->assertStringNotContainsString('<span class="marke-label">Leistungszeitraum</span>', $html);
    }

    #[Test]
    public function the_date_is_the_shops_day_not_the_servers(): void
    {
        // 22:30 UTC on the 25th is 00:30 on the 26th in Berlin.
        $invoice = app(InvoiceWriter::class)->forPayment($this->payment('kurs', '2026-09-25 22:30:00', ['amount_cent' => 24900]));

        $html = $this->html($invoice);
        $this->assertStringContainsString('26.09.2026', $html);
        $this->assertStringNotContainsString('25.09.2026', $html);
    }

    #[Test]
    public function changing_the_zone_later_does_not_change_an_issued_document(): void
    {
        $invoice = app(InvoiceWriter::class)->forPayment($this->payment('chortarif', '2026-09-25 22:30:00'));
        $before = $this->html($invoice);

        config(['invoices.display_timezone' => 'Pacific/Honolulu']);

        $this->assertSame($before, $this->html($invoice));
    }
}
