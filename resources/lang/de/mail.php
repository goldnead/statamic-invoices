<?php

/*
|--------------------------------------------------------------------------
| Rechnungsmail als Vorlage (statamic-email-templates)
|--------------------------------------------------------------------------
|
| Titel, Anlass und Platzhalter, wie das CP von email-templates sie zeigt,
| und der Wortlaut, den ein Import als Eintrag schreibt. Solange es keinen
| Eintrag gibt, geht die eingebaute Ansicht `invoices::mail.invoice` raus.
|
*/

return [
    'title' => 'Rechnung an den Käufer',
    'trigger' => 'Eine Rechnung wurde geschrieben und geht mit der PDF an den Käufer',
    'subject' => 'Ihre Rechnung {{ invoice.number }}',
    'body' => '<p>Guten Tag {{ buyer.name }},</p>'
        .'<p>im Anhang finden Sie Ihre Rechnung {{ invoice.number }} vom {{ invoice.date }} über {{ amount }} als PDF.</p>'
        .'<p>Bitte bewahren Sie die Rechnung auf. Sie ist zugleich Ihr Beleg für die Zahlung.</p>'
        .'<p>Freundliche Grüße<br>{{ seller.name }}</p>',

    'placeholders' => [
        'buyer_name' => 'Name des Käufers (ohne Namen seine E-Mail-Adresse)',
        'buyer_email' => 'E-Mail-Adresse des Käufers',
        'invoice_number' => 'Rechnungsnummer',
        'invoice_date' => 'Rechnungsdatum',
        'amount' => 'Bruttobetrag mit Währung',
        'seller_name' => 'Name des Verkäufers, wie er auf der Rechnung steht',
        'site_name' => 'Name der Website',
    ],
];
