<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Leistungszeitraum bei einem Abo mit Testphase (Entscheidung 17.11.2026,
 * Lesart b, Recherche TASKS/recherche-leistungszeitraum-abo-testphase-2026-11-17).
 *
 * Die Zahlung beim Abschluss (kostenlos oder mit `trial_amount_cent`) gehoert
 * zur Testphase und nennt sie als eigenen Zeitraum. Der erste bezahlte Takt
 * beginnt mit dem Ende der Testphase, und seine Rechnung nennt genau diesen
 * Takt, nicht den Abo-Beginn und nicht den Tag davor.
 */
class ServicePeriodTrialTest extends TestCase
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
            'probeabo' => [
                'name' => 'Probeabo', 'amount_cent' => 900, 'digital' => true,
                'interval' => '1 month', 'trial_days' => 14, 'trial_amount_cent' => 100,
            ],
        ]);
    }

    private function payment(string $paidAtUtc, array $extra = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'probeabo',
            'amount_cent' => 100,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'chor@example.com',
            'name' => 'Testchor 2 e.V.',
            'country' => 'DE',
            'paid_at' => Carbon::parse($paidAtUtc, 'UTC'),
        ], $extra));
    }

    private function subscription(): Subscription
    {
        return Subscription::create([
            'provider' => 'fake',
            'provider_id' => 'sub_'.bin2hex(random_bytes(4)),
            'customer_reference' => 'cst_1',
            'product' => 'probeabo',
            'amount_cent' => 900,
            'currency' => 'EUR',
            'interval' => '1 month',
            'times_charged' => 0,
            'status' => Subscription::STATUS_ACTIVE,
            'email' => 'chor@example.com',
        ]);
    }

    private function linkable(): void
    {
        if (! method_exists(Payment::class, 'subscription') || ! class_exists(Subscription::class)) {
            $this->markTestSkipped('this statamic-payments has no payment→subscription link');
        }
    }

    #[Test]
    public function the_payment_at_signup_names_the_trial_as_its_period(): void
    {
        $invoice = app(InvoiceWriter::class)->forPayment($this->payment('2026-11-10 10:00:00'));

        $this->assertSame(['from' => '2026-11-10', 'to' => '2026-11-23'], $invoice->meta['service_period'] ?? null);
    }

    #[Test]
    public function the_trial_payment_linked_to_its_subscription_still_names_the_trial(): void
    {
        $this->linkable();

        // Subscriptions::start() links the first payment to the agreement it
        // created, so by the time an invoice is written it can be linked.
        $invoice = app(InvoiceWriter::class)->forPayment(
            $this->payment('2026-11-10 10:00:00', ['subscription_id' => $this->subscription()->id])
        );

        $this->assertSame(['from' => '2026-11-10', 'to' => '2026-11-23'], $invoice->meta['service_period'] ?? null);
    }

    #[Test]
    public function the_first_paid_cycle_starts_when_the_trial_ends(): void
    {
        $this->linkable();

        $subscription = $this->subscription();
        $this->payment('2026-11-10 10:00:00', ['subscription_id' => $subscription->id]);

        // 14 days later: the first charge of the agreement.
        $invoice = app(InvoiceWriter::class)->forPayment(
            $this->payment('2026-11-24 04:00:00', ['amount_cent' => 900, 'subscription_id' => $subscription->id])
        );

        $this->assertSame(['from' => '2026-11-24', 'to' => '2026-12-23'], $invoice->meta['service_period'] ?? null);
    }

    #[Test]
    public function a_trial_length_given_as_text_is_read_like_payments_reads_it(): void
    {
        config(['statamic-payments.products.probeabo.trial_days' => '14']);

        $invoice = app(InvoiceWriter::class)->forPayment($this->payment('2026-11-10 10:00:00'));

        $this->assertSame(['from' => '2026-11-10', 'to' => '2026-11-23'], $invoice->meta['service_period'] ?? null);
    }

    #[Test]
    public function a_failed_earlier_payment_does_not_turn_the_signup_into_a_cycle(): void
    {
        $this->linkable();

        $subscription = $this->subscription();
        $this->payment('2026-11-10 09:00:00', ['status' => Payment::STATUS_FAILED, 'subscription_id' => $subscription->id]);

        $invoice = app(InvoiceWriter::class)->forPayment(
            $this->payment('2026-11-10 10:00:00', ['subscription_id' => $subscription->id])
        );

        $this->assertSame(['from' => '2026-11-10', 'to' => '2026-11-23'], $invoice->meta['service_period'] ?? null);
    }

    #[Test]
    public function a_plan_without_a_trial_keeps_a_full_first_period(): void
    {
        config(['statamic-payments.products.probeabo.trial_days' => 0]);

        $invoice = app(InvoiceWriter::class)->forPayment($this->payment('2026-11-10 10:00:00', ['amount_cent' => 900]));

        $this->assertSame(['from' => '2026-11-10', 'to' => '2026-12-09'], $invoice->meta['service_period'] ?? null);
    }
}
