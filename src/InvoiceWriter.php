<?php

namespace Goldnead\Invoices;

use Goldnead\Invoices\Events\CreditNoteIssued;
use Goldnead\Invoices\Events\InvoiceIssued;
use Goldnead\Invoices\Exceptions\DetailsMissing;
use Goldnead\Invoices\Exceptions\DoesNotMatchThePayment;
use Goldnead\Invoices\Exceptions\ProductIncomplete;
use Goldnead\Invoices\Exceptions\RateUndetermined;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\Invoices\Support\NumberSeries;
use Goldnead\Invoices\Support\TaxResult;
use Goldnead\Invoices\Support\TaxRules;
use Goldnead\Invoices\Support\TaxZone;
use Goldnead\Invoices\Support\VatIdCheck;
use Goldnead\Invoices\Support\VatIdStatus;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        if ($vorhanden = $this->existing($payment, Invoice::KIND_INVOICE)) {
            return $vorhanden;
        }

        // Einmal beantwortet, zweimal gebraucht: an der Marke haengt der
        // Verkaeufer, den die Pflichtangaben pruefen, und die Reihe, aus der die
        // Nummer kommt. Zweimal fragen hiesse ausserdem, den Hinweis auf eine
        // Zahlung ohne Marke zweimal ins Log zu schreiben.
        $brandId = $this->brandIdFor($payment);

        $this->assertComplete($payment, $this->seller($brandId));

        $zeilen = $this->lines($payment);

        // Die Hinweise des Steuerrechners gehen vor allem anderen ins Log —
        // auch vor dem Abbruch wegen eines unbestimmten Satzes. Sie standen
        // frueher nur auf dem TaxResult und erreichten niemanden: die B2B-
        // Warnung unter § 19 war ein Jahr lang da, und keiner hat sie gesehen.
        $hinweise = $this->taxNotes($zeilen);

        foreach ($hinweise as $hinweis) {
            Log::warning('invoices: tax note', [
                'payment' => $payment->getKey(),
                'product' => $hinweis['product'],
                'note' => $hinweis['note'],
            ]);
        }

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

        // Drei Versuche. Unter Nebenlaeufigkeit sperrt die Zaehlerzeile, und
        // eine gesperrte Zeile ist auf MySQL ein Deadlock und auf SQLite ein
        // "database is locked" — beides ist ein Grund, es gleich noch einmal zu
        // versuchen, und keiner, eine bezahlte Bestellung ohne Beleg zu lassen.
        $invoice = DB::transaction(function () use ($payment, $zeilen, $brandId, $hinweise): Invoice {
            $issuedAt = $payment->paid_at ?? Carbon::now();

            $invoice = Invoice::create([
                'brand_id' => $brandId,
                // Genommen, waehrend die Zeile geschrieben wird. Frueher hiesse:
                // eine Nummer, die vergeben ist und auf nichts zeigt.
                'number' => $this->numbers->take($brandId, $issuedAt),
                'payment_id' => $payment->getKey(),
                'kind' => Invoice::KIND_INVOICE,
                'issued_at' => $issuedAt,
                'currency' => $payment->currency,
                'buyer_name' => $payment->name,
                'buyer_email' => $payment->email,
                'buyer_country' => $payment->country,
                'buyer_vat_id' => $payment->meta['vat_id'] ?? null,
                'buyer_address' => $payment->meta['address'] ?? null,
                // What was known about that number when this document was written,
                // and nothing that would have to be looked up to read it later.
                ...$this->vatIdColumns($payment, $zeilen),
                'seller' => $this->seller($brandId),
                'net_cent' => array_sum(array_column($zeilen, 'net_cent')),
                'tax_cent' => array_sum(array_column($zeilen, 'tax_cent')),
                'gross_cent' => $this->reconciled($payment, $zeilen),
                'tax_reason' => $zeilen[0]['tax_reason'] ?? null,
                'tax_note' => $this->note($zeilen),
                // Nicht fuer den Kaeufer, fuer die Pruefung: was der Rechner an
                // dieser Entscheidung fuer zweifelhaft hielt, steht am Beleg,
                // nicht nur in einem Log, das rotiert.
                'meta' => $hinweise === [] ? null : ['tax_notes' => $hinweise],
            ]);

            InvoiceItem::whileWriting(function () use ($invoice, $zeilen) {
                foreach ($zeilen as $zeile) {
                    unset($zeile['tax_reason'], $zeile['tax_mechanism'], $zeile['tax_code'], $zeile['tax_notes']);
                    $invoice->items()->create($zeile);
                }
            });

            return $invoice;
        }, 3);

        InvoiceIssued::dispatch($invoice->fresh(['items']) ?? $invoice);

        return $invoice;
    }

    /**
     * The mandatory details, checked before anything is written.
     *
     * Only the two this addon can actually know about. It cannot check whether
     * a description is specific enough or a date is right; it can check that
     * the sender exists at all and that a large invoice names its recipient.
     *
     * The €250 line is § 33 UStDV: below it a Kleinbetragsrechnung needs no
     * recipient address, which is the ordinary case for a digital product.
     * Demanding one there would refuse invoices the law is happy with.
     *
     * @param  array<string, mixed>  $seller
     */
    protected function assertComplete(Payment $payment, array $seller): void
    {
        $fehlt = [];

        if (! is_string($seller['name'] ?? null) || trim($seller['name']) === '') {
            $fehlt[] = 'the sender has no name (invoices.seller.name)';
        }

        if (! is_string($seller['address'] ?? null) || trim($seller['address']) === '') {
            $fehlt[] = 'the sender has no address (invoices.seller.address)';
        }

        $schwelle = (int) config('invoices.small_amount_cent', 25000);

        if ($payment->amount_cent > $schwelle) {
            if (! is_string($payment->name ?? null) || trim($payment->name) === '') {
                $fehlt[] = 'the recipient has no name, and above '.($schwelle / 100).' EUR that is mandatory';
            }

            if (! is_string($payment->meta['address'] ?? null) || trim($payment->meta['address']) === '') {
                $fehlt[] = 'the recipient has no address, and above '.($schwelle / 100).' EUR § 14 UStG requires one';
            }
        }

        if ($fehlt !== []) {
            throw new DetailsMissing($payment, $fehlt);
        }
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
            ->where('kind', Invoice::KIND_INVOICE)
            ->with('items')
            ->first();

        if ($original === null) {
            return null;
        }

        // Schon storniert: die zweite Zustellung derselben Erstattung darf
        // nicht ein zweites Dokument erzeugen.
        if ($this->existing($payment, Invoice::KIND_CREDIT_NOTE) !== null) {
            return null;
        }

        $storno = DB::transaction(function () use ($original): Invoice {
            $issuedAt = Carbon::now();

            $storno = Invoice::create([
                'brand_id' => $original->brand_id,
                'number' => $this->numbers->take($original->brand_id, $issuedAt),
                'payment_id' => $original->payment_id,
                'kind' => Invoice::KIND_CREDIT_NOTE,
                'reverses_invoice_id' => $original->getKey(),
                'issued_at' => $issuedAt,
                'currency' => $original->currency,
                'buyer_name' => $original->buyer_name,
                'buyer_email' => $original->buyer_email,
                'buyer_country' => $original->buyer_country,
                'buyer_vat_id' => $original->buyer_vat_id,
                'buyer_address' => $original->buyer_address,
                // Copied off the original, never looked up again. A credit note
                // reverses what the invoice said, including what was known about
                // the number at the time — a fresh answer here would produce two
                // documents about one purchase that disagree with each other.
                'tax_zone' => $original->tax_zone,
                'buyer_vat_id_status' => $original->buyer_vat_id_status,
                'buyer_vat_id_checked_at' => $original->buyer_vat_id_checked_at,
                'buyer_vat_id_service' => $original->buyer_vat_id_service,
                'buyer_vat_id_reference' => $original->buyer_vat_id_reference,
                'seller' => $original->seller,
                'net_cent' => $original->net_cent,
                'tax_cent' => $original->tax_cent,
                'gross_cent' => $original->gross_cent,
                'tax_reason' => $original->tax_reason,
                'tax_note' => $original->tax_note,
                // Die Hinweise wandern mit: die Gutschrift kehrt genau die
                // Entscheidung um, an der sie hingen.
                'meta' => array_filter([
                    'reverses_number' => $original->number,
                    'tax_notes' => $original->meta['tax_notes'] ?? null,
                ], fn ($wert) => $wert !== null),
            ]);

            InvoiceItem::whileWriting(function () use ($storno, $original) {
                foreach ($original->items as $zeile) {
                    $storno->items()->create($zeile->only([
                        'product', 'name', 'quantity', 'unit_net_cent', 'discount_cent',
                        'net_cent', 'tax_rate_bp', 'tax_cent', 'gross_cent',
                    ]));
                }
            });

            return $storno;
        }, 3);

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
                // The catalogue's name, not the raw handle. A payment without
                // line items used to print `kurs` where "Chorleitungskurs"
                // belongs — and `offer:fruehling-upsell` where the buyer had
                // read "Frühlings-Upsell". Pre-existing, and only visible once
                // an offer made the handle ugly enough to notice.
                $this->product($payment->product)['name'] ?? $payment->product,
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
            // The handle the tax class was declared under, which is not always
            // the handle on the line. A site maps its classes per product, and
            // an offer carries a handle of its own — so an offer for a
            // reduced-rate product would otherwise fall to the default class
            // and print the wrong rate on a document that cannot be corrected.
            productHandle: $this->taxHandle($handle),
            buyerCountry: $payment->country,
            buyerVatId: is_array($payment->meta) ? ($payment->meta['vat_id'] ?? null) : null,
            // The verdict the checkout froze onto the payment, not a fresh lookup.
            // Asking VIES again here would let a document written today and one
            // rewritten next year disagree about the same purchase — and the second
            // answer is not the one the seller relied on.
            vatIdStatus: $this->vatIdCheck($payment)?->status,
            buyerIsBusiness: $this->buyerIsBusiness($payment),
            // Nicht `?? true`. Ob eine Leistung digital oder koerperlich ist,
            // entscheidet ueber Reverse Charge gegen innergemeinschaftliche
            // Lieferung und ueber "nicht steuerbar" gegen Ausfuhr — vier
            // verschiedene Pflichthinweise. Fehlt die Angabe, gibt es keine
            // Antwort, so wie bei einem fehlenden Land.
            isDigital: $this->isDigital($handle),
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
                'tax_notes' => $satz->notes,
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
        // steht ein Bruttopreis neben einem Nettobetrag und die Zeile geht
        // nicht auf.
        //
        // Und er muss *aufgehen*: Einzel × Menge − Nachlass hat genau das Netto
        // darunter zu ergeben. Mit `round()` tut es das bei Menge > 1 nicht —
        // 3 × 10 € brutto druckte „3 × 8,40 €" über einem Netto von 25,21 €,
        // und eine Rechnung, deren sichtbare Zeile nicht aufgeht, ist
        // unbrauchbar, ohne falsch auszusehen. Deshalb wird abgerundet und der
        // Rest in den Nachlass gelegt: dort ist er eine erklärte Zahl.
        $menge = max(1, $quantity);
        $rabattNetto = $bp > 0 && $inklusive
            ? (int) round($discountCent * 10000 / (10000 + $bp))
            : $discountCent;

        // Aufgerundet, nicht abgerundet, und die Differenz geht in den
        // Nachlass. Andersherum wuerde der Rest zum Nachlass *addiert* und
        // damit ein zweites Mal abgezogen — bei 2 x 10 EUR brutto stand dann
        // 1679 unter einer Zeile, die 1681 ergeben sollte.
        //
        // Dass dabei ein Rundungscent im Nachlass landen kann, ist die
        // ehrlichere Haelfte des Tauschs: der Kaeufer zahlt tatsaechlich
        // weniger als Einzelpreis x Menge, und genau das steht dann da.
        $einzelNetto = intdiv($netto + $rabattNetto + $menge - 1, $menge);
        $rabattNetto = $einzelNetto * $menge - $netto;

        return [
            'product' => $handle,
            'name' => $name ?: ($handle ?? '—'),
            'quantity' => $menge,
            'unit_net_cent' => $einzelNetto,
            'discount_cent' => $rabattNetto,
            'net_cent' => $netto,
            'tax_rate_bp' => $bp,
            'tax_cent' => $steuer,
            'gross_cent' => $brutto,
            'tax_reason' => $satz->reason,
            'tax_mechanism' => $satz->mechanism,
            'tax_code' => null,
            'tax_notes' => $satz->notes,
        ];
    }

    /**
     * What the tax rules found doubtful about a payment, per line, without
     * writing anything. `invoices:pending` shows it; forPayment() logs it and
     * stores it under `meta.tax_notes`.
     *
     * @return list<array{product: string|null, note: string}>
     */
    public function taxNotesFor(Payment $payment): array
    {
        return $this->taxNotes($this->lines($payment));
    }

    /**
     * @param  list<array<string, mixed>>  $zeilen
     * @return list<array{product: string|null, note: string}>
     */
    protected function taxNotes(array $zeilen): array
    {
        $hinweise = [];

        foreach ($zeilen as $zeile) {
            foreach ($zeile['tax_notes'] ?? [] as $note) {
                $eintrag = ['product' => $zeile['product'] ?? null, 'note' => (string) $note];

                if (! in_array($eintrag, $hinweise, true)) {
                    $hinweise[] = $eintrag;
                }
            }
        }

        return $hinweise;
    }

    /**
     * The invoice or credit note that already exists for a payment.
     *
     * Read inside the transaction as well as before it: the check before is a
     * courtesy that saves a lock, the one inside is what actually holds. Two
     * webhook deliveries arriving together both read nothing here, and only the
     * unique index stops them both writing.
     */
    protected function existing(Payment $payment, string $kind): ?Invoice
    {
        return Invoice::query()
            ->where('payment_id', $payment->getKey())
            ->where('kind', $kind)
            ->first();
    }

    /**
     * Is this a digital service or a physical good?
     *
     * No default. The answer decides between four different mandatory notes —
     * reverse charge, intra-community supply, outside scope, export — and
     * guessing produces a wrong one on an immutable document.
     */
    /**
     * The confirmation the checkout froze onto the payment, if there was one.
     *
     * Read, never made. This class writes a document; asking a foreign server
     * mid-write is how an invoice ends up depending on somebody else's uptime,
     * which is the thing the 25.08. rule forbids. The checkout asks, because that
     * is where a buyer can still be told to fix a typo.
     */
    protected function vatIdCheck(Payment $payment): ?VatIdCheck
    {
        return is_array($payment->meta)
            ? VatIdCheck::fromArray($payment->meta['vat_id_check'] ?? null)
            : null;
    }

    /**
     * Did the buyer say they were buying as a business?
     *
     * Only consulted outside the EU, where there is no register to ask. Inside
     * it the VAT ID is the evidence and this flag would be a way to talk past it.
     */
    protected function buyerIsBusiness(Payment $payment): bool
    {
        if (! is_array($payment->meta)) {
            return false;
        }

        return (bool) ($payment->meta['business_confirmed'] ?? false);
    }

    /**
     * The five columns that say what was known, and which case this document is.
     *
     * The zone comes off the mechanism the rules landed on rather than off the
     * country, because the country alone does not distinguish a business from a
     * consumer — and those two get different documents in the same country.
     *
     * @param  list<array<string, mixed>>  $zeilen
     * @return array<string, mixed>
     */
    protected function vatIdColumns(Payment $payment, array $zeilen): array
    {
        $check = $this->vatIdCheck($payment);
        $mechanism = $zeilen[0]['tax_mechanism'] ?? null;

        $zone = match ($mechanism) {
            TaxResult::MECHANISM_REVERSE_CHARGE, TaxResult::MECHANISM_INTRA_COMMUNITY_SUPPLY => TaxZone::EuBusiness,
            TaxResult::MECHANISM_OUTSIDE_SCOPE, TaxResult::MECHANISM_EXPORT => TaxZone::ThirdCountryBusiness,
            // Everything else is a domestic document — but only if the buyer is
            // actually here. A consumer abroad has no zone in this model, and
            // labelling one "de" would be the invoice claiming a case it is not.
            default => $this->isDomestic($payment) ? TaxZone::Domestic : null,
        };

        // A buyer VAT ID with no frozen check is written as `unchecked`, never as
        // null. The two look the same on a document and are not the same fact:
        // null means there was no number to ask about, `unchecked` means there was
        // one and nobody asked. Only the second is a thing somebody should see —
        // and a column that stays null is a class of invoice no report can count,
        // because no report knows to look for it.
        $gegebeneNummer = is_array($payment->meta) ? ($payment->meta['vat_id'] ?? null) : null;

        $status = $check !== null
            ? $check->status
            : (is_string($gegebeneNummer) && trim($gegebeneNummer) !== '' ? VatIdStatus::Unchecked : null);

        return [
            'tax_zone' => $zone?->value,
            'buyer_vat_id_status' => $status?->value,
            'buyer_vat_id_checked_at' => $check?->checkedAt,
            'buyer_vat_id_service' => $check?->service,
            'buyer_vat_id_reference' => $check?->requestId,
        ];
    }

    protected function isDomestic(Payment $payment): bool
    {
        $merchant = config('invoices.tax.merchant_country', 'DE');
        $merchant = is_string($merchant) ? strtoupper($merchant) : 'DE';

        return is_string($payment->country) && strtoupper($payment->country) === $merchant;
    }

    protected function isDigital(?string $handle): bool
    {
        $product = $this->product($handle);

        if (! array_key_exists('digital', $product)) {
            throw new ProductIncomplete($handle, 'digital');
        }

        return (bool) $product['digital'];
    }

    /**
     * The invoice total, checked against the money that actually arrived.
     *
     * The only external check this document has. Everything else about it is
     * internally consistent by construction: the same code adds the lines that
     * writes them, so a wrong rate yields a wrong invoice that looks exactly
     * like a right one. The bank statement is the one witness that was not
     * asked.
     *
     * It catches a whole family in one line: the price basis pointing the wrong
     * way (€22.61 against a payment of €19.00 — arithmetically perfect and
     * wrong), a rate applied where none belongs, a discount split that lost a
     * cent, a quantity that drifted.
     *
     * Deliberately exact rather than tolerant. A cent of slack would hide
     * exactly the rounding defects this addon has already had to fix once, and
     * on a document that cannot be amended afterwards, "close enough" is not a
     * category.
     *
     * @param  list<array<string, mixed>>  $zeilen
     */
    protected function reconciled(Payment $payment, array $zeilen): int
    {
        $brutto = (int) array_sum(array_column($zeilen, 'gross_cent'));

        if ($brutto !== (int) $payment->amount_cent) {
            throw new DoesNotMatchThePayment($payment, $brutto);
        }

        return $brutto;
    }

    /**
     * The handle a tax class is declared under for this line.
     *
     * The line's own handle, unless whatever resolved it points at something
     * else. `statamic-offers` does: an offer is a presentation of a product,
     * and the site declared the class under the product.
     */
    protected function taxHandle(?string $handle): string
    {
        $underneath = $this->product($handle)['product'] ?? null;

        return is_string($underneath) && $underneath !== ''
            ? $underneath
            : (string) ($handle ?? '');
    }

    /**
     * The thing that was sold, resolved the way the checkout resolves it.
     *
     * Through the catalogue, not past it. Reading `statamic-payments.products`
     * directly skips every resolver another addon registered — and
     * `statamic-offers` registers one, under the prefix `offer:`. So every
     * payment that went through an offer carried line handles this method could
     * not find, `isDigital()` threw ProductIncomplete, and **no invoice was
     * written at all**. `statamic-funnels` uses an offer for every paid step,
     * which means the advertised chain funnel → offer → payment → invoice broke
     * at its last link on any installation using the family as documented.
     *
     * Three shipped addons, each with a green suite, none of which crossed the
     * boundary between them.
     *
     * @return array<string, mixed>
     */
    protected function product(?string $handle): array
    {
        if ($handle === null || $handle === '') {
            return [];
        }

        return (array) (app(Catalogue::class)->find($handle) ?? []);
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

    /**
     * Which brand's series this invoice belongs to.
     *
     * **The brand of the sale, not the brand of the process that writes the
     * document.** Those are the same thing in a checkout request and different
     * everywhere an invoice is actually written: a provider's webhook, a
     * console run, a follow-up charge on a subscription. None of them has a
     * brand in the environment, and `currentId()` answers with the default
     * brand there rather than with nothing — which is how a second brand's
     * invoice landed in the first brand's series, silently, and stayed there,
     * because the row is immutable a moment later.
     *
     * So the brand is read off the payment. `statamic-payments` stamps
     * `brand_id` while the row is created — in the request where the buyer
     * actually was — and a subscription cycle or a follow-up charge inherits
     * the brand of the row it belongs to. The comment that used to stand here
     * said a brand was not recoverable from the payment. That was true until
     * that column existed; it is the row that knows, not the process.
     *
     * **`brand_id = 0` while multi-brand is on** is a row that belongs to no
     * brand: it predates the column on a host whose backfill could not run, or
     * it was created while brand-context could not say who was current. It
     * still gets its invoice. Refusing would deny a tax document to somebody
     * who has already paid, and no later run could produce it either — the
     * number would then be missing from a series that has to be gapless. So
     * these fall back to exactly what happened before (the current brand if
     * there is one, otherwise the default), and say so in the log instead of
     * doing it quietly. `invoices:brand-check` lists them afterwards; nothing
     * can move them, an invoice does not change.
     *
     * A payments release older than the column behaves the same way and reaches
     * no schema query on the way there: an attribute that is not on the row is
     * simply absent, which is the same answer as an unstamped row.
     */
    protected function brandIdFor(Payment $payment): int
    {
        if (! class_exists('\Goldnead\BrandContext\Facades\BrandContext')) {
            return 0;
        }

        try {
            $manager = app('brand-context');

            // Einmarkenbetrieb bleibt bei 0, auch wenn auf der Zahlung etwas
            // anderes steht: die Zaehlerzeile, der Index und jede bestehende
            // Rechnung dieser Installation sind auf 0 gebaut.
            if (! $manager->multiBrandEnabled()) {
                return 0;
            }

            $derZahlung = (int) ($payment->getAttribute('brand_id') ?? 0);

            if ($derZahlung > 0) {
                return $derZahlung;
            }

            $ersatz = (int) $manager->currentId();

            Log::warning(
                'statamic-invoices: the payment carries no brand, so its invoice goes into another '
                .'brand\'s series and stays there. Check the extent with invoices:brand-check.',
                ['payment_id' => $payment->getKey(), 'brand_id' => $ersatz],
            );

            return $ersatz;
        } catch (\Throwable) {
            return 0;
        }
    }
}
