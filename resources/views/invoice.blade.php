{{--
    Die Rechnung, als HTML.

    Dasselbe Dokument, das gedruckt wird — es gibt keine zweite, nachgebaute
    Vorschau, die auseinanderlaufen kann. Das ist der eine Kniff, den der
    invoice-generator richtig gemacht hat und der hier übernommen wird.

    Bewusst ohne Bilder und ohne externe Schriften: eine Rechnung, die von einem
    CDN abhängt, ist in fünf Jahren eine Rechnung ohne Layout, und aufbewahren
    muss man sie zehn.
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->isCreditNote() ? 'Stornorechnung' : 'Rechnung' }} {{ $invoice->number }}</title>
    <style>
        @page { size: A4; margin: 20mm 18mm 24mm; }
        /* Der Seitenrand gilt nur beim Drucken. Ohne dieses Padding klebt die
           Vorschau am Fensterrand — und die Vorschau ist das, was jemand sieht,
           bevor er druckt. Beim Druck faellt es weg, sonst waere der Rand doppelt. */
        body { font: 10pt/1.55 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 20mm 18mm; max-width: 210mm; box-sizing: border-box; }
        @media print { body { padding: 0; max-width: none; } }
        .kopf { display: flex; justify-content: space-between; align-items: flex-start; gap: 2rem; margin-bottom: 2.5rem; }
        .absender { font-size: 8.5pt; line-height: 1.5; color: #555; white-space: pre-line; text-align: right; }
        .absender strong { display: block; color: #1a1a1a; font-size: 10pt; }
        .empfaenger { white-space: pre-line; }
        h1 { font-size: 15pt; margin: 0 0 .2rem; }
        .kennzahlen { display: flex; gap: 2rem; font-size: 9pt; color: #555; margin-bottom: 1.8rem; }
        table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
        th { text-align: left; font-weight: 600; border-bottom: 1.5px solid #1a1a1a; padding: .5rem .4rem; }
        td { padding: .5rem .4rem; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
        .zahl { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .summen { margin-left: auto; width: 52%; margin-top: 1rem; }
        .summen td { border: 0; padding: .25rem .4rem; }
        .summen .gesamt td { border-top: 1.5px solid #1a1a1a; font-weight: 700; font-size: 11pt; padding-top: .5rem; }
        .hinweis { margin-top: 2.2rem; font-size: 9pt; color: #444; }
        .fuss { margin-top: 3rem; padding-top: .8rem; border-top: 1px solid #e5e5e5; font-size: 8pt; color: #666; display: flex; gap: 2rem; }
    </style>
</head>
<body>

<div class="kopf">
    <div>
        <div class="empfaenger">{{ $empfaenger }}</div>
    </div>
    <div class="absender"><strong>{{ $seller['name'] ?? '' }}</strong>{{ $seller['address'] ?? '' }}
@if(!empty($seller['vat_id']))USt-IdNr. {{ $seller['vat_id'] }}@endif</div>
</div>

<h1>{{ $invoice->isCreditNote() ? 'Stornorechnung' : 'Rechnung' }} {{ $invoice->number }}</h1>

@if($invoice->isCreditNote() && ($invoice->meta['reverses_number'] ?? null))
    <p style="margin:.1rem 0 1.2rem">Storniert die Rechnung {{ $invoice->meta['reverses_number'] }}.</p>
@endif

<div class="kennzahlen">
    <span>Rechnungsdatum: {{ $invoice->issued_at->format('d.m.Y') }}</span>
    {{-- Pflichtangabe: der Zeitpunkt der Leistung. Bei einem Sofortkauf ist er
         das Rechnungsdatum, und das gehoert hingeschrieben statt vorausgesetzt. --}}
    <span>Leistungsdatum: {{ $invoice->issued_at->format('d.m.Y') }}</span>
    @if($invoice->buyer_vat_id)<span>USt-IdNr. des Empfängers: {{ $invoice->buyer_vat_id }}</span>@endif
</div>

<table>
    <thead>
        <tr>
            <th>Leistung</th>
            <th class="zahl">Menge</th>
            <th class="zahl">Einzelpreis</th>
            @if($hatRabatt)<th class="zahl">Nachlass</th>@endif
            <th class="zahl">USt</th>
            <th class="zahl">Netto</th>
        </tr>
    </thead>
    <tbody>
    @foreach($invoice->items as $zeile)
        <tr>
            <td>{{ $zeile->name }}</td>
            <td class="zahl">{{ $zeile->quantity }}</td>
            <td class="zahl">{{ $euro($zeile->unit_net_cent) }}</td>
            @if($hatRabatt)<td class="zahl">{{ $zeile->discount_cent > 0 ? '−'.$euro($zeile->discount_cent) : '—' }}</td>@endif
            <td class="zahl">{{ $zeile->ratePercent() }} %</td>
            <td class="zahl">{{ $euro($zeile->net_cent) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

{{--
    § 14 Abs. 4 Nr. 8 UStG verlangt das Entgelt **nach Steuersaetzen
    aufgeschluesselt**, samt dem darauf entfallenden Steuerbetrag. Eine einzige
    Nettozeile ueber einer Rechnung mit 19 % und 7 % erfuellt das nicht — und
    genau der Fall ist hier der Normalfall, sobald Noten neben einem Kurs
    stehen.
--}}
<table class="summen">
    @foreach($nachSatz as $satz)
        <tr>
            <td>Entgelt zu {{ $satz['label'] }}</td>
            <td class="zahl">{{ $euro($satz['net']) }}</td>
        </tr>
        @if($satz['tax'] > 0)
            <tr>
                <td>Umsatzsteuer {{ $satz['label'] }}</td>
                <td class="zahl">{{ $euro($satz['tax']) }}</td>
            </tr>
        @endif
    @endforeach

    @if(count($nachSatz) > 1)
        <tr><td>Nettobetrag gesamt</td><td class="zahl">{{ $euro($invoice->net_cent) }}</td></tr>
        @if($invoice->tax_cent > 0)
            <tr><td>Umsatzsteuer gesamt</td><td class="zahl">{{ $euro($invoice->tax_cent) }}</td></tr>
        @endif
    @endif

    <tr class="gesamt"><td>Gesamtbetrag</td><td class="zahl">{{ $euro($invoice->gross_cent) }}</td></tr>
</table>

@if($invoice->tax_note)
    {{-- Bei Reverse Charge und § 19 verlangt der Gesetzgeber diesen Hinweis
         ausdrücklich auf der Rechnung. Er steht als Text auf der Zeile, nicht
         als Verweis auf eine Regel, die später geändert werden kann. --}}
    <p class="hinweis">{{ $invoice->tax_note }}</p>
@endif

<div class="fuss">
    @if(!empty($seller['tax_number']))<span>Steuernummer: {{ $seller['tax_number'] }}</span>@endif
    @if(!empty($seller['email']))<span>{{ $seller['email'] }}</span>@endif
    @if(!empty($seller['iban']))<span>IBAN: {{ $seller['iban'] }}</span>@endif
</div>

</body>
</html>
