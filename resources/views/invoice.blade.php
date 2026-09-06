{{--
    Die Rechnung, als HTML.

    Dasselbe Dokument, das gedruckt wird — es gibt keine zweite, nachgebaute
    Vorschau, die auseinanderlaufen kann. Das ist der eine Kniff, den der
    invoice-generator richtig gemacht hat und der hier übernommen wird.

    Bewusst ohne externe Schriften und ohne nachgeladene Bilder: eine Rechnung,
    die von einem CDN abhängt, ist in fünf Jahren eine Rechnung ohne Layout, und
    aufbewahren muss man sie zehn.

    **Seit 06.09.2026 trägt sie die Marke — ohne diese Regel zu brechen.**
    Die Werte kommen aus `$marke` (`Support\MarkenBild`, dahinter
    `brand-context`), das Logo als *eingebettetes SVG* aus einer Datei auf der
    Platte, nie als URL und nie als `data:`-URI. Fehlt brand-context oder ist es
    älter, greifen neutrale Vorgaben: Schwarz auf Weiß, kein Logo. Das ist kein
    Mangel, das ist eine Rechnung.

    Der Grund steht im Ticket: Adrian ging am 05.09.2026 einen Testkauf durch,
    und die drei Dinge, die der Käufer danach in der Hand hält, sahen nach
    nichts aus.

    **Hell bleibt hell.** Der Markengrund `--ink #0a0f1e` trägt am Bildschirm;
    dieses Dokument wird gedruckt, und eine ganzflächige Tönung kostet dort
    Toner und Lesbarkeit. Die Marke trägt über Logo, Linien und die
    Akzentfarbe — nicht über den Untergrund.
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
        /* Schrift und Schriftfarbe aus der Marke. Der Grund bleibt weiss:
           gedruckt ist jede Tönung Toner, und `paper` ist am Bildschirm
           gedacht. Wer eine getönte Rechnung will, sagt es ausdrücklich — hier
           entscheidet der Drucker mit. */
        body { font: 10pt/1.55 {{ $marke['font'] }}; color: {{ $marke['ink'] }}; margin: 0; padding: 20mm 18mm; max-width: 210mm; box-sizing: border-box; }
        @media print { body { padding: 0; max-width: none; } }
        /* Zwei Spalten als Tabelle, nicht als Flexbox. Die Vorlage wird
           gedruckt, und keine der reinen PHP-Druckmaschinen kennt Flexbox:
           dort faellt sie auf untereinander stehende Bloecke zurueck, und der
           Absender landet unter dem Empfaenger statt neben ihm. Eine Tabelle
           verstehen beide Seiten gleich. */
        .kopf { width: 100%; margin-bottom: 2.5rem; }
        .kopf td { border: 0; padding: 0; vertical-align: top; font-size: 10pt; }
        .absender { font-size: 8.5pt; line-height: 1.5; color: #555; white-space: pre-line; text-align: right; width: 45%; }
        .absender strong { display: block; color: #1a1a1a; font-size: 10pt; }
        .empfaenger { white-space: pre-line; }
        h1 { font-size: 15pt; margin: 0 0 .2rem; }
        .kennzahlen { font-size: 9pt; color: #555; margin-bottom: 1.8rem; }
        .kennzahlen span { margin-right: 2rem; }
        table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
        /* 700, nicht 600. Die Druckmaschine hat von einer Schrift genau zwei
           Schnitte, und ein Zwischengewicht, das sie nicht findet, laesst sie
           auf ihre Standardschrift zurueckfallen — die Kopfzeile stand dann
           als Serifenschrift ueber einer serifenlosen Tabelle. */
        th { text-align: left; font-weight: 700; border-bottom: 1.5px solid {{ $marke['accent'] }}; padding: .5rem .4rem; }
        td { padding: .5rem .4rem; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
        .zahl { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .summen { margin-left: 48%; width: 52%; margin-top: 1rem; }
        .summen td { border: 0; padding: .25rem .4rem; }
        .summen .gesamt td { border-top: 1.5px solid {{ $marke['accent'] }}; font-weight: 700; font-size: 11pt; padding-top: .5rem; }
        /* Die Marke im Kopf. Ein schmaler Streifen, keine Fläche: gedruckt
           kostet eine Fläche Toner und gewinnt nichts. */
        .marke { margin-bottom: 1.6rem; padding-bottom: .9rem; border-bottom: 2px solid {{ $marke['accent'] }}; }
        .marke__logo { max-height: 34px; width: auto; display: block; margin-bottom: .35rem; }
        .marke__logo svg { max-height: 34px; width: auto; }
        .marke__wort { font-size: 11pt; font-weight: 700; letter-spacing: .01em; color: {{ $marke['accent'] }}; }
        .hinweis { margin-top: 2.2rem; font-size: 9pt; color: #444; }
        .fuss { margin-top: 3rem; padding-top: .8rem; border-top: 1px solid #e5e5e5; font-size: 8pt; color: #666; }
        .fuss span { margin-right: 2rem; }
    </style>
</head>
<body>

{{-- Die Marke, ganz oben. Nur wenn es eine gibt: ohne brand-context steht hier
     nichts, und die Rechnung fängt an wie bisher. --}}
@if($marke['logoSvg'] || $marke['name'])
    <div class="marke">
        @if($marke['logoSvg'])
            {{-- Eingebettet, nicht verlinkt. Ausgegeben mit `{!! !!}`, weil ein
                 SVG Markup IST — die Datei kommt aus einer Einstellung des
                 Betreibers, nicht aus einer Eingabe von außen, und
                 `BrandIdentity::logoSvg()` gibt nur zurück, was auf der Platte
                 liegt und mit `<svg` beginnt. --}}
            <div class="marke__logo">{!! $marke['logoSvg'] !!}</div>
        @endif
        @if($marke['name'])
            <div class="marke__wort">{{ $marke['name'] }}</div>
        @endif
    </div>
@endif

<table class="kopf">
    <tr>
        <td class="empfaenger">{{ $empfaenger }}</td>
        <td class="absender"><strong>{{ $seller['name'] ?? '' }}</strong>{{ $seller['address'] ?? '' }}
@if(!empty($seller['vat_id']))USt-IdNr. {{ $seller['vat_id'] }}@endif</td>
    </tr>
</table>

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
    {{-- Was ueber diese Nummer bekannt war, als das Dokument entstand — nicht
         mehr und nicht weniger. Ein Reverse-Charge-Hinweis neben einer Nummer,
         die niemand geprueft hat, behauptet eine Pruefung; ein bestaetigter
         Beleg ohne Datum und Dienst laesst sich Jahre spaeter nicht nachvollziehen.
         Deshalb steht hier genau der eingefrorene Zustand, in beiden Sprachen. --}}
    @php
        $pruefstand = $invoice->buyer_vat_id ? $invoice->vatIdStatus() : null;
        $geprueftAm = $invoice->buyer_vat_id_checked_at;
        $bestaetigt = null;

        if ($pruefstand === \Goldnead\Invoices\Support\VatIdStatus::Valid) {
            $bestaetigt = 'Nummer bestätigt'
                .($invoice->buyer_vat_id_service ? ' ('.$invoice->buyer_vat_id_service.')' : '')
                .($geprueftAm ? ' am '.$geprueftAm->format('d.m.Y') : '')
                .($invoice->buyer_vat_id_reference ? ', Nachweis '.$invoice->buyer_vat_id_reference : '')
                .'. VAT ID confirmed'.($geprueftAm ? ' on '.$geprueftAm->format('Y-m-d') : '').'.';
        }
    @endphp
    @if($bestaetigt)
        <span>{{ $bestaetigt }}</span>
    @elseif($pruefstand === \Goldnead\Invoices\Support\VatIdStatus::Pending)
        <span>USt-IdNr. angegeben, Bestätigung ausstehend. VAT ID provided, verification pending.</span>
    @elseif($pruefstand === \Goldnead\Invoices\Support\VatIdStatus::Unchecked)
        {{-- Auch das gehoert hin. Ohne diese Zeile sieht eine ungeprüfte Nummer
             genauso aus wie eine bestaetigte, naemlich wie gar nichts — und wer den
             Beleg liest, kann die beiden nicht unterscheiden. --}}
        <span>USt-IdNr. angegeben, nicht bestätigt. VAT ID provided, not verified.</span>
    @endif
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
