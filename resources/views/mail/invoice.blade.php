{{--
    Das Anschreiben zur Rechnung.

    Kurz gehalten: das Dokument ist die Nachricht. Wer hier erklärt, dankt oder
    verkauft, stellt sich zwischen den Käufer und die Datei, die er tatsächlich
    braucht — und diese Mail liegt zehn Jahre neben ihr im Postfach.

    Ohne Bilder und ohne externe Schriften, aus demselben Grund wie die Rechnung
    selbst. Wer sie ändern will, veröffentlicht sie mit
    `php artisan vendor:publish --tag=invoices-views`.
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
</head>
<body style="font: 15px/1.6 -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1a1a1a;">

<p>Guten Tag{{ $invoice->buyer_name ? ' '.$invoice->buyer_name : '' }},</p>

<p>im Anhang finden Sie die Rechnung {{ $invoice->number }} über {{ $betrag }}.</p>

<p>Bitte bewahren Sie sie auf. Sie ist zugleich Ihr Beleg für die Zahlung.</p>

<p>
    Freundliche Grüße<br>
    {{ $seller['name'] ?? '' }}
</p>

</body>
</html>
