<?php

/*
| The triggers in the Webhook Manager. Labelled "Invoices: …" so they read as
| one group next to the other addons' triggers in the picker.
*/

return [
    'triggers' => [
        'issued' => 'Invoices: invoice issued',
        'credit_note_issued' => 'Invoices: credit note issued',
        'delivered' => 'Invoices: invoice delivered',
    ],

    'descriptions' => [
        'issued' => 'When an invoice is written with its number, usually right after the payment.',
        'credit_note_issued' => 'When an invoice is cancelled, for example after a refund. Carries the number of the cancelled invoice.',
        'delivered' => 'When the invoice was mailed to the buyer.',
    ],
];
