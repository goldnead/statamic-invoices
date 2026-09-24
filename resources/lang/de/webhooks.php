<?php

/*
| Die Auslöser im Webhook-Manager. „Rechnungen: …", damit sie in der Auswahl
| neben den Auslösern der anderen Addons als Gruppe lesbar sind.
*/

return [
    'triggers' => [
        'issued' => 'Rechnungen: Rechnung ausgestellt',
        'credit_note_issued' => 'Rechnungen: Gutschrift ausgestellt',
        'delivered' => 'Rechnungen: Rechnung zugestellt',
    ],

    'descriptions' => [
        'issued' => 'Wenn eine Rechnung mit Nummer geschrieben ist, meist direkt nach der Zahlung.',
        'credit_note_issued' => 'Wenn eine Rechnung storniert wird, etwa nach einer Erstattung. Enthält die Nummer der stornierten Rechnung.',
        'delivered' => 'Wenn die Rechnung per Mail an die Käuferin gegangen ist.',
    ],
];
