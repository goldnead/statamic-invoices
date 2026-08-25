<?php

namespace Goldnead\Invoices;

use Goldnead\Invoices\Events\CreditNoteIssued;
use Goldnead\Invoices\Events\InvoiceIssued;
use Goldnead\Invoices\Exceptions\RateUndetermined;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\NumberSeries;
use Goldnead\Invoices\Support\TaxRules;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a paid payment into an invoice.
 *
 * The three hard parts are all about timing and are all handled the same way:
 * inside one transaction.
 *
 * 1. **The number** is taken from a locked counter, not from `MAX() + 1`, and
 *    it is taken *here* rather than earlier — a number handed out to something
 *    that then fails leaves a gap, and a gap in a German series is a question
 *    at the next audit.
 * 2. **One invoice per payment**, enforced by a unique index rather than by
 *    looking first: two webhook deliveries arriving together both look, both
 *    see nothing, and both write.
 * 3. **Nothing is looked up later.** The rate, the reason, the buyer, the
 *    seller — all frozen as text at this moment, because every one of them can
 *    change and the invoice may not.
 */
class InvoiceWriter
{
    public function __construct(protected NumberSeries $numbers) {}

    /**
     * Write the invoice for a payment, or return the one that already exists.
     */
    public function forPayment(Payment $payment): ?Invoice
    {
        if ($payment->status !== Payment::STATUS_PAID) {
            return null;
        }

        if ($vorhanden = Invoice::query()->where('payment_id', $payment->getKey())->first()) {
            return $vorhanden;
        }

        $zeilen = $this->lines($payment);

        // Kein geratener Satz. Findet keine Regel, wird keine Rechnung
        // geschrieben — die Zahlung steht, das Dokument wartet auf einen
        // Menschen. Ein falscher Satz auf einem Steuerdokument sieht aus wie
        // eine Antwort: er ist still falsch, er ist unterschrieben, und er geht
        // an einen Kunden.
        $unbestimmt = array_values(array_filter(
            $zeilen,
            fn (array $z) => $z['tax_rate_bp'] === null,
        ));

        if ($unbestimmt !== []) {
            throw new RateUndetermined($payment, array_map(fn (array $z) => [
                'product' => $z['product'],
                'code' => $z['tax_code'] ?? null,
                'reason' => (string) ($z['tax_reason'] ?? ''),
            ], $unbestimmt));
        }

        $invoice = DB::transaction(function () use ($payment, $zeilen): Invoice {
            $brandId = $this->brandIdFor($payment);
            $issuedAt = $payment->paid_at ?? Carbon::now();

            $invoice = Invoice::create([
                'brand_id' => $brandId,
                // Genommen, waehrend die Zeile geschrieben wird. Frueher hiesse:
                // eine Nummer, die vergeben ist und auf nichts zeigt.
                'number' => $this->numbers->take($brandId, $issuedAt),
                'payment_id' => $payment->getKey(),
                'issued_at' => $issuedAt,
                'currency' => $payment->currency,
                'buyer_name' => $payment->name,
                'buyer_email' => $payment->email,
                'buyer_country' => $payment->country,
                'buyer_vat_id' => $payment->meta['vat_id'] ?? null,
                'buyer_address' => $payment->meta['address'] ?? null,
                'seller' => $this->seller($brandId),
                'net_cent' => array_sum(array_column($zeilen, 'net_cent')),
                'tax_cent' => array_sum(array_column($zeilen, 'tax_cent')),
                'gross_cent' => array_sum(array_column($zeilen, 'gross_cent')),
                'tax_reason' => $zeilen[0]['tax_reason'] ?? null,
                'tax_note' => $this->note($zeilen),
            ]);

            foreach ($zeilen as $zeile) {
                unset($zeile['tax_reason'], $zeile['tax_mechanism'], $zeile['tax_code']);
                $invoice->items()->create($zeile);
            }

            return $invoice;
        });

        InvoiceIssued::dispatch($invoice->fresh(['items']) ?? $invoice);

        return $invoice;
    }

    /**
     * The credit note that reverses an invoice.
     *
     * A correction is a second document, never an edit. That rule is what makes
     * a number series usable as evidence, and it is enforced on the model —
     * this method exists so there is a right way to do the thing the model
     * refuses.
     *
     * The amounts are the original's, negated in meaning rather than in sign:
     * the columns stay unsigned and the document says what it is. A credit note
     * with a minus in front of every figure reads as arithmetic; one that says
     * "Stornorechnung zu RE2026-08-004" reads as a fact.
     *
     * The tax is copied, not recalculated. The rate that applied is the rate
     * that applied — looking it up again a month later could produce a
     * different one, and then the two documents would not cancel out.
     */
    public function creditNoteFor(Payment $payment): ?Invoice
    {
        $original = Invoice::query()
            ->where('payment_id', $payment->getKey())
            ->whereNull('reverses_invoice_id')
            ->with('items')
            ->first();

        if ($original === null) {
            return null;
        }

        // Schon storniert: die zweite Zustellung derselben Erstattung darf
        // nicht ein zweites Dokument erzeugen.
        if (Invoice::query()->where('reverses_invoice_id', $original->getKey())->exists()) {
            return null;
        }

        $storno = DB::transaction(function () use ($original): Invoice {
            $issuedAt = Carbon::now();

            $storno = Invoice::create([
                'brand_id' => $original->brand_id,
                'number' => $this->numbers->take($original->brand_id, $issuedAt),
                'payment_id' => $original->payment_id,
                'reverses_invoice_id' => $original->getKey(),
                'issued_at' => $issuedAt,
                'currency' => $original->currency,
                'buyer_name' => $original->buyer_name,
                'buyer_email' => $original->buyer_email,
                'buyer_country' => $original->buyer_country,
                'buyer_vat_id' => $original->buyer_vat_id,
                'buyer_address' => $original->buyer_address,
                'seller' => $original->seller,
                'net_cent' => $original->net_cent,
                'tax_cent' => $original->tax_cent,
                'gross_cent' => $original->gross_cent,
                'tax_reason' => $original->tax_reason,
                'tax_note' => $original->tax_note,
                'meta' => ['reverses_number' => $original->number],
            ]);

            foreach ($original->items as $zeile) {
                $storno->items()->create($zeile->only([
                    'product', 'name', 'quantity', 'unit_net_cent', 'discount_cent',
                    'net_cent', 'tax_rate_bp', 'tax_cent', 'gross_cent',
                ]));
            }

            return $storno;
        });

        CreditNoteIssued::dispatch($storno->fresh(['items']) ?? $storno, $original);

        return $storno;
    }

    /**
     * The lines, each with its own rate.
     *
     * A payment written before line items existed still has its handle on the
     * payment — the same fallback the entitlements bridge makes, and for the
     * same reason: an old order is still an order.
     *
     * @return list<array<string, mixed>>
     */
    protected function lines(Payment $payment): array
    {
        $items = $payment->items;

        if ($items->isEmpty()) {
            return [$this->line(
                $payment->product,
                $payment->product,
                1,
                $payment->amount_cent + (int) $payment->discount_cent,
                (int) $payment->discount_cent,
                $payment,
            )];
        }

        return $items->map(fn ($item) => $this->line(
            $item->product,
            $item->name,
            (int) $item->quantity,
            (int) $item->amount_cent,
            (int) $item->discount_cent,
            $payment,
        ))->all();
    }

    /** @return array<string, mixed> */
    protected function line(
        ?string $handle,
        ?string $name,
        int $quantity,
        int $unitCent,
        int $discountCent,
        Payment $payment,
    ): array {
        // Statisch aufgerufen, weil die Regelauswertung keinen Zustand hat:
        // sie liest die Konfiguration und rechnet. Ein Produkt ohne Handle gibt
        // es — eine alte Zahlung ohne Positionen — und auch dafuer darf kein
        // Satz geraten werden.
        $satz = TaxRules::for(
            productHandle: (string) ($handle ?? ''),
            buyerCountry: $payment->country,
            buyerVatId: is_array($payment->meta) ? ($payment->meta['vat_id'] ?? null) : null,
            isDigital: (bool) ($this->product($handle)['digital'] ?? true),
        );

        $bp = $satz->rateBasisPoints;

        if ($bp === null) {
            // Unbestimmt. Die Zeile wird trotzdem gebaut, damit der Aufrufer
            // *alle* offenen Positionen auf einmal sieht statt bei der ersten
            // abzubrechen — Adrian soll einmal nachsehen, nicht siebenmal.
            return [
                'product' => $handle,
                'name' => $name ?: ($handle ?? '—'),
                'quantity' => max(1, $quantity),
                'unit_net_cent' => $unitCent,
                'discount_cent' => $discountCent,
                'net_cent' => 0,
                'tax_rate_bp' => null,
                'tax_cent' => 0,
                'gross_cent' => 0,
                'tax_reason' => $satz->reason,
                'tax_mechanism' => $satz->mechanism,
                'tax_code' => $satz->code,
            ];
        }

        $brutto = max(0, $unitCent * max(1, $quantity) - $discountCent);

        // Die Aufteilung macht der Steuerrechner, nicht diese Klasse.
        //
        // Zwei Gruende. Erstens hatte diese Stelle einen eigenen Default fuer
        // `prices_include_tax`, und der widersprach dem der Konfiguration —
        // eine Rechnung haette anders gerechnet als eingestellt. Zweitens
        // rundet `split()` spiegelbildlich, damit ein Storno den Cent
        // zurueckgibt, den das Original genommen hat. Zwei Rundungen an zwei
        // Stellen sind zwei Chancen, dass sich die Dokumente nicht aufheben.
        ['net' => $netto, 'tax' => $steuer, 'gross' => $brutto] = $satz->split($brutto);
        $inklusive = $satz->pricesIncludeTax;

        // Der Einzelpreis, den die Rechnung zeigt, muss netto sein — sonst
        // steht in einer Zeile ein Bruttopreis neben einem Nettobetrag und die
        // Zeile geht nicht auf. Gerechnet aus dem Zeilennetto, damit
        // Einzel × Menge − Rabatt genau das Netto ergibt, das darunter steht.
        $menge = max(1, $quantity);
        $rabattNetto = $bp > 0 && $inklusive
            ? (int) round($discountCent * 10000 / (10000 + $bp))
            : $discountCent;

        return [
            'product' => $handle,
            'name' => $name ?: ($handle ?? '—'),
            'quantity' => $menge,
            'unit_net_cent' => (int) round(($netto + $rabattNetto) / $menge),
            'discount_cent' => $rabattNetto,
            'net_cent' => $netto,
            'tax_rate_bp' => $bp,
            'tax_cent' => $steuer,
            'gross_cent' => $brutto,
            'tax_reason' => $satz->reason,
            'tax_mechanism' => $satz->mechanism,
            'tax_code' => null,
        ];
    }

    /** @return array<string, mixed> */
    protected function product(?string $handle): array
    {
        if ($handle === null) {
            return [];
        }

        $alle = (array) config('statamic-payments.products', []);

        return (array) ($alle[$handle] ?? []);
    }

    /**
     * The seller's own details, frozen onto the invoice.
     *
     * Read from the brand where one exists — a series per brand and a sender
     * per brand are the same idea — and from the configuration otherwise.
     *
     * @return array<string, mixed>
     */
    protected function seller(?int $brandId): array
    {
        $aus = (array) config('invoices.seller', []);

        if ($brandId !== null) {
            $proMarke = (array) config('invoices.seller_per_brand', []);
            $aus = array_merge($aus, (array) ($proMarke[$brandId] ?? []));
        }

        return $aus;
    }

    /**
     * The prose the law wants when a rate is not the ordinary one.
     *
     * One invoice can carry several: exempt tuition beside a course at 19%, or
     * a reverse charge line beside a domestic one. Each reason appears **once**,
     * in the order the lines do, and none is dropped — a missing note on a
     * reverse-charge or § 19 line is not a stylistic matter, it is a mandatory
     * statement under § 14a UStG.
     *
     * The ordinary domestic case says nothing here, because "Umsatzsteuer 19 %"
     * is already in the table and repeating it below adds noise to the one
     * place a reader looks for an exception.
     */
    protected function note(array $zeilen): ?string
    {
        $gewoehnlich = $this->ordinaryReasons($zeilen);

        $gruende = [];

        foreach (array_column($zeilen, 'tax_reason') as $grund) {
            if (! is_string($grund) || $grund === '' || in_array($grund, $gewoehnlich, true)) {
                continue;
            }

            if (! in_array($grund, $gruende, true)) {
                $gruende[] = $grund;
            }
        }

        return $gruende === [] ? null : implode(' ', $gruende);
    }

    /**
     * The reasons that need no note: an ordinary rate on a domestic line.
     *
     * @param  list<array<string, mixed>>  $zeilen
     * @return list<string>
     */
    protected function ordinaryReasons(array $zeilen): array
    {
        $gewoehnlich = [];

        foreach ($zeilen as $zeile) {
            if (($zeile['tax_mechanism'] ?? null) === 'standard' && is_string($zeile['tax_reason'] ?? null)) {
                $gewoehnlich[] = $zeile['tax_reason'];
            }
        }

        return $gewoehnlich;
    }

    protected function brandIdFor(Payment $payment): ?int
    {
        if (! class_exists('\Goldnead\BrandContext\Facades\BrandContext')) {
            return null;
        }

        try {
            return app('brand-context')->currentId();
        } catch (\Throwable) {
            return null;
        }
    }
}
