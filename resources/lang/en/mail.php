<?php

/*
|--------------------------------------------------------------------------
| The invoice mail as a template (statamic-email-templates)
|--------------------------------------------------------------------------
|
| Title, occasion and placeholders as email-templates' CP shows them, and the
| wording an import writes as an entry. Until there is an entry, the built-in
| view `invoices::mail.invoice` is sent.
|
*/

return [
    'title' => 'Invoice to the buyer',
    'trigger' => 'An invoice was written and goes to the buyer with its PDF',
    'subject' => 'Your invoice {{ invoice.number }}',
    'body' => '<p>Hello {{ buyer.name }},</p>'
        .'<p>attached is your invoice {{ invoice.number }} of {{ invoice.date }} for {{ amount }} as a PDF.</p>'
        .'<p>Please keep it. It is also your receipt for the payment.</p>'
        .'<p>Kind regards<br>{{ seller.name }}</p>',

    'placeholders' => [
        'buyer_name' => 'Buyer\'s name (their email address when there is none)',
        'buyer_email' => 'Buyer\'s email address',
        'invoice_number' => 'Invoice number',
        'invoice_date' => 'Invoice date',
        'amount' => 'Gross amount with currency',
        'seller_name' => 'Seller\'s name as it appears on the invoice',
        'site_name' => 'Name of the website',
    ],
];
