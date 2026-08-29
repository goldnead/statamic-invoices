<?php

return [

    // The figures this addon offers the Insights dashboard. Nothing else in the
    // package is user-facing text yet: an invoice renders from its own frozen
    // fields, and the tax notes are the operator's wording from config.
    'metric_group' => 'Invoices',

    'metric_issued' => 'Documents issued',
    'metric_issued_description' => 'Invoices and credit notes dated into this period. A count of documents, so a credit note counts as one of them.',

    'metric_net' => 'Net invoiced',
    'metric_net_description' => 'Net amounts dated into this period, less what credit notes took back.',

    'metric_gross' => 'Gross invoiced',
    'metric_gross_description' => 'Gross amounts dated into this period, less what credit notes took back.',

    'metric_tax' => 'VAT invoiced',
    'metric_tax_description' => 'The tax shown on this period\'s documents, less what credit notes took back.',

    'metric_breakdown_kind' => 'Kind',
    'metric_breakdown_buyer_country' => 'Country',
    'metric_breakdown_tax_rate_bp' => 'Tax rate',

    'metric_kind_invoice' => 'Invoice',
    'metric_kind_credit_note' => 'Credit note',

    // The rate a line was taxed at, read out of basis points: 1900 is 19 %.
    'metric_tax_rate' => ':rate %',

    // What to call the rows that have no value in the dimension they are split
    // by. A document with no country is a row, not an omission.
    'metric_no_kind' => 'No kind',
    'metric_no_buyer_country' => 'No country',
    'metric_no_tax_rate_bp' => 'No rate',

];
