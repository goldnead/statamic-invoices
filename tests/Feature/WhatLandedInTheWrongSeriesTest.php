<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Console\Commands\BrandCheck;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The measurement after the repair.
 *
 * Invoices written before `brandIdFor()` read the brand off the payment carry
 * no mark of it: no error was raised, nothing was logged, and the row looks
 * exactly like a correct one. The only surviving evidence is a disagreement
 * between two tables — the invoice says one brand, the payment it belongs to
 * says another — and this command is that comparison.
 *
 * It has to find a real one, and it has to leave everything where it is: the
 * number came out of one brand's gapless counter and was counted there. Nothing
 * in this file may end with a written row.
 */
class WhatLandedInTheWrongSeriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // In Produktion findet ihn derselbe Provider, der auch `invoices:pending`
        // findet; unter testbench laeuft `bootCommands()` nicht, also wird er
        // hier registriert statt ungeprueft zu bleiben.
        $this->app[Kernel::class]->registerCommand($this->app->make(BrandCheck::class));
    }

    /**
     * Die Spalte, die statamic-payments seit 1.13 mitbringt — wortgleich zur
     * dortigen Migration. Der Befehl vergleicht gegen sie; ohne sie gibt es
     * nichts zu vergleichen, und auch das ist unten ein Fall.
     */
    private function zahlungenTragenEineMarke(): void
    {
        if (Schema::hasColumn('payments', 'brand_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $tabelle) {
            $tabelle->unsignedBigInteger('brand_id')->default(0)->index();
        });
    }

    /** Der Stand vor jener Migration, den eine Installation heute noch haben darf. */
    private function zahlungenTragenKeineMarke(): void
    {
        if (! Schema::hasColumn('payments', 'brand_id')) {
            return;
        }

        // Erst der Index, dann die Spalte: SQLite loescht keine Spalte, auf die
        // noch ein Index zeigt. Dieselbe Reihenfolge wie im `down()` drueben.
        Schema::table('payments', fn (Blueprint $tabelle) => $tabelle->dropIndex('payments_brand_id_index'));
        Schema::table('payments', fn (Blueprint $tabelle) => $tabelle->dropColumn('brand_id'));
    }

    private function zahlung(int $brandId): Payment
    {
        $zahlung = Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs',
            'amount_cent' => 11900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'baerbel@example.com',
            'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE',
            'paid_at' => now(),
        ]);

        // Gesetzt statt gestempelt: das Stempeln gehoert statamic-payments, und
        // dessen Modell fragt hier nichts, wenn der Wert schon dasteht.
        if (Schema::hasColumn('payments', 'brand_id')) {
            Payment::query()->whereKey($zahlung->getKey())->update(['brand_id' => $brandId]);
        }

        return $zahlung->refresh();
    }

    private function rechnung(Payment $zahlung, int $brandId, string $nummer, string $kind = Invoice::KIND_INVOICE): Invoice
    {
        return Invoice::create([
            'brand_id' => $brandId,
            'number' => $nummer,
            'payment_id' => $zahlung->getKey(),
            'kind' => $kind,
            'issued_at' => now(),
            'currency' => 'EUR',
            'buyer_name' => $zahlung->name,
            'buyer_email' => $zahlung->email,
            'buyer_country' => 'DE',
            'seller' => ['name' => 'Nordlicht Studio'],
            'net_cent' => 10000,
            'tax_cent' => 1900,
            'gross_cent' => 11900,
        ]);
    }

    /**
     * Den Befehl laufen lassen und beides zurueckgeben, Code und Ausgabe.
     *
     * Nicht `$this->artisan(...)->expectsOutputToContain(...)`: das prueft je
     * Schreibvorgang, und zwei erwartete Stuecke in derselben Tabellenzeile
     * bekommt nur das erste zu sehen. Eine Zusicherung, die an der Mechanik des
     * Pruefwerkzeugs scheitert statt am Befehl, ist keine.
     *
     * @return array{0: int, 1: string}
     */
    private function lauf(): array
    {
        $code = Artisan::call('invoices:brand-check');

        return [$code, Artisan::output()];
    }

    #[Test]
    public function it_names_the_invoice_that_sits_in_another_brands_series(): void
    {
        $this->zahlungenTragenEineMarke();

        // Marke Zwei hat verkauft, die Rechnung steht in der Reihe von Marke
        // Eins: genau das, was der stille Weg hinterlassen hat.
        $falsch = $this->zahlung(brandId: 2);
        $this->rechnung($falsch, brandId: 1, nummer: 'AA2026-08-007');

        // Und eine, bei der alles stimmt — sie darf nicht auftauchen.
        $richtig = $this->zahlung(brandId: 2);
        $this->rechnung($richtig, brandId: 2, nummer: 'BB2026-08-001');

        [$code, $ausgabe] = $this->lauf();

        $this->assertSame(1, $code, 'ein Pruefbefehl, der bei einem Fund 0 zurueckgibt, kann nicht ueberwacht werden');
        $this->assertStringContainsString('AA2026-08-007', $ausgabe);
        $this->assertStringContainsString('1 Rechnungen stehen in der Reihe einer anderen Marke', $ausgabe);
        $this->assertStringNotContainsString('BB2026-08-001', $ausgabe, 'eine richtig abgelegte Rechnung gehört nicht in die Liste');

        // Nichts umgeschrieben. Eine Nummer aus der falschen Reihe laesst sich
        // nicht heilen, und der Befehl darf es nicht einmal versuchen.
        $this->assertSame(1, (int) Invoice::query()->where('number', 'AA2026-08-007')->value('brand_id'));
    }

    #[Test]
    public function a_payment_without_a_brand_is_shown_as_the_one_that_has_none(): void
    {
        $this->zahlungenTragenEineMarke();

        // Der Fall aus dem Rueckfall im InvoiceWriter: die Zahlung gehoert
        // keiner Marke, die Rechnung steht trotzdem in einer Reihe. Sie ist
        // nicht falsch abgelegt, sie ist unbelegt — und muss unterscheidbar
        // bleiben von "in der Reihe der falschen Marke".
        $ohne = $this->zahlung(brandId: 0);
        $this->rechnung($ohne, brandId: 1, nummer: 'AA2026-08-009');

        [$code, $ausgabe] = $this->lauf();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('AA2026-08-009', $ausgabe);
        $this->assertStringContainsString('0 (keine Marke)', $ausgabe);
    }

    #[Test]
    public function a_credit_note_is_checked_like_the_invoice_it_reverses(): void
    {
        $this->zahlungenTragenEineMarke();

        $zahlung = $this->zahlung(brandId: 2);
        $this->rechnung($zahlung, brandId: 1, nummer: 'AA2026-08-011');
        $this->rechnung($zahlung, brandId: 1, nummer: 'AA2026-08-012', kind: Invoice::KIND_CREDIT_NOTE);

        [$code, $ausgabe] = $this->lauf();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('AA2026-08-012', $ausgabe);
        $this->assertStringContainsString('Storno', $ausgabe);
        $this->assertStringContainsString('2 Rechnungen stehen in der Reihe einer anderen Marke', $ausgabe);
    }

    #[Test]
    public function an_installation_where_everything_matches_says_so(): void
    {
        $this->zahlungenTragenEineMarke();

        $zahlung = $this->zahlung(brandId: 2);
        $this->rechnung($zahlung, brandId: 2, nummer: 'BB2026-08-001');

        [$code, $ausgabe] = $this->lauf();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Jede Rechnung steht in der Reihe der Marke, die verkauft hat.', $ausgabe);
    }

    #[Test]
    public function it_refuses_to_look_like_an_all_clear_when_it_cannot_compare(): void
    {
        // Ohne die Spalte kann der Vergleich nicht stattfinden. Eine leere
        // Liste waere hier eine Entwarnung, die niemand geprueft hat — der
        // teuerste Fehler dieses Addons in klein.
        $this->zahlungenTragenKeineMarke();

        [$code, $ausgabe] = $this->lauf();

        $this->assertSame(1, $code, 'nicht pruefen koennen ist kein Erfolg');
        $this->assertStringContainsString('Die Zahlungen tragen noch keine Marke', $ausgabe);
    }
}
