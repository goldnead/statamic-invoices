{{--
    Das Anschreiben zur Rechnung.

    Kurz gehalten: das Dokument ist die Nachricht. Wer hier erklärt, dankt oder
    verkauft, stellt sich zwischen den Käufer und die Datei, die er tatsächlich
    braucht — und diese Mail liegt zehn Jahre neben ihr im Postfach.

    Ohne externe Schriften und ohne nachgeladene Bilder, aus demselben Grund wie
    die Rechnung selbst. Wer sie ändern will, veröffentlicht sie mit
    `php artisan vendor:publish --tag=invoices-views`.

    **Seit 06.09.2026 trägt sie die Marke — ohne diese Regel zu brechen.**
    Das Logo kommt als CID-Anhang (`$logoCid`), also mit der Mail statt aus dem
    Netz: eine `https://`-Quelle blockieren Mail-Clients standardmäßig, und beim
    ersten Öffnen stünde da ein leerer Kasten. Ein `data:`-URI werfen viele
    Clients heraus. Farben stehen inline, weil ein Stylesheet-Link wieder ein
    externer Abruf wäre, und die Schrift ist eine Systemliste — Bricolage
    Grotesque und Hanken Grotesk gibt es im Postfach nicht.

    Kurz bleibt sie trotzdem. Das Dokument ist die Nachricht; die Marke steht
    darüber, nicht dazwischen.
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
</head>
<body style="margin:0; padding:24px; background:{{ $marke['paper'] }}; font: 15px/1.6 {{ $marke['font'] }}; color: {{ $marke['ink'] }};">

<div style="max-width:34em; margin:0 auto; background:#ffffff; padding:28px 30px; border-radius:6px;">

    @if($marke['logo'] || $marke['name'])
        <div style="padding-bottom:14px; margin-bottom:22px; border-bottom:2px solid {{ $marke['accent'] }};">
            @if($marke['logo'])
                {{-- `$message->embed()` hängt die Datei an die Mail und gibt
                     eine `cid:`-Adresse zurück. Das Bild reist damit MIT der
                     Mail: keine URL, die ein Client blockiert, und kein
                     `data:`-URI, den viele herauswerfen. --}}
                <img src="{{ $message->embed($marke['logo']) }}" alt="" height="32" style="display:block; height:32px; width:auto; border:0; margin-bottom:6px;">
            @endif
            @if($marke['name'])
                <div style="font-size:15px; font-weight:700; color:{{ $marke['accent'] }};">{{ $marke['name'] }}</div>
            @endif
        </div>
    @endif

<p>Guten Tag{{ $invoice->buyer_name ? ' '.$invoice->buyer_name : '' }},</p>

<p>im Anhang finden Sie die Rechnung {{ $invoice->number }} über {{ $betrag }}.</p>

<p>Bitte bewahren Sie sie auf. Sie ist zugleich Ihr Beleg für die Zahlung.</p>

<p>
    Freundliche Grüße<br>
    {{ $seller['name'] ?? '' }}
</p>

</div>

</body>
</html>
