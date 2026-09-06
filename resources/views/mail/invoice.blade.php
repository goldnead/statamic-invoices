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
<body style="margin:0; padding:32px 20px; background:{{ $marke['paper'] }}; font: 15px/1.65 {{ $marke['font'] }}; color: {{ $marke['ink'] }};">

{{-- Tabellen-Layout, keine Flexbox: Outlook rendert kein modernes Layout, und
     eine Mail, die beim Empfänger auseinanderfällt, ist keine Mail. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
<tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="520" style="width:520px; max-width:100%; border-collapse:collapse; background:#ffffff;">
<tr><td style="padding:34px 36px 38px;">

    @if($marke['logo'] || $marke['name'])
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse; margin-bottom:28px;">
            <tr><td style="padding-bottom:14px; border-bottom:2px solid {{ $marke['accent'] }};">
                @if($marke['logo'])
                    {{-- `$message->embed()` hängt die Datei an die Mail und gibt
                         eine `cid:`-Adresse zurück. Das Bild reist damit MIT der
                         Mail: keine URL, die ein Client blockiert, und kein
                         `data:`-URI, den viele herauswerfen. --}}
                    <img src="{{ $message->embed($marke['logo']) }}" alt="" width="30" height="30" style="display:block; width:30px; height:30px; border:0; margin-bottom:8px;">
                @endif
                @if($marke['name'])
                    <div style="font-size:14px; font-weight:700; letter-spacing:-0.005em; color:{{ $marke['accent'] }};">{{ $marke['name'] }}</div>
                @endif
            </td></tr>
        </table>
    @endif

    <p style="margin:0 0 20px;">Guten Tag{{ $invoice->buyer_name ? ' '.$invoice->buyer_name : '' }},</p>

    <p style="margin:0 0 24px;">im Anhang finden Sie Ihre Rechnung als PDF.</p>

    {{-- Die zwei Angaben, wegen derer diese Mail geöffnet wird, aus dem Fließtext
         herausgehoben: Nummer und Betrag. Versalien mit weiter Laufweite als
         Beschriftung — dieselbe Geste wie auf der Rechnung und auf der
         Verkaufsseite, und die einzige schmückende in dieser Mail. --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse; margin:0 0 26px;">
        <tr>
            <td style="padding:14px 0; border-top:1px solid #dfe3ea; border-bottom:1px solid #dfe3ea;">
                <div style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.1em; color:{{ $marke['muted'] }}; margin-bottom:4px;">Rechnungsnummer</div>
                <div style="font-size:15px; font-weight:600;">{{ $invoice->number }}</div>
            </td>
            <td align="right" style="padding:14px 0; border-top:1px solid #dfe3ea; border-bottom:1px solid #dfe3ea;">
                <div style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.1em; color:{{ $marke['muted'] }}; margin-bottom:4px;">Betrag</div>
                <div style="font-size:19px; font-weight:700; letter-spacing:-0.02em; white-space:nowrap;">{{ $betrag }}</div>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 24px; color:{{ $marke['muted'] }}; font-size:14px;">Bitte bewahren Sie die Rechnung auf. Sie ist zugleich Ihr Beleg für die Zahlung.</p>

    <p style="margin:0;">
        Freundliche Grüße<br>
        {{ $seller['name'] ?? '' }}
    </p>

</td></tr>
</table>
</td></tr>
</table>

</body>
</html>
