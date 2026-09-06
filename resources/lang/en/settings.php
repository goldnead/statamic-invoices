<?php

return [

    // Labels for the settings screen. The screen itself belongs to
    // statamic-brand-context; this addon supplies only the field list
    // (Support\Settings) and the words for it.

    'permission_group' => 'Invoices',
    'permission_manage_settings' => 'Manage invoice settings',

    'groups' => [

        'seller' => [
            'title' => 'Seller',
            'description' => 'Who is issuing the invoice. These details are frozen onto every document as it is written and cannot be corrected afterwards — a German invoice without them is not an invoice (§ 14 UStG). On a multi-brand host what is set here applies to the brand in the switcher, which makes `seller_per_brand` in config/invoices.php redundant.',
        ],

        'number' => [
            'title' => 'Number series',
            'description' => 'The series invoice numbers are handed out from, one per brand: two brands sharing a counter give each of them a series with holes in it, and each brand has to answer for its own numbering. Period and separator stay in config/invoices.php — they are decided once at setup, and "unset" could not be told apart from "empty" in a form.',
        ],

        'issuing' => [
            'title' => 'Issuing and delivery',
            'description' => 'When a document comes into being and how it reaches the buyer.',
        ],

        'small_business' => [
            'title' => 'Small business scheme, § 19 UStG',
            'description' => 'The switch that suspends everything below it: no tax, on anything, with the reason on the invoice. The other two fields matter only for a consumer in another EU country. This is the addon\'s reading of the law, not tax advice.',
        ],

        'buyers' => [
            'title' => 'Buyers and VAT ID',
            'description' => 'Who may buy, and how a buyer\'s VAT ID is confirmed.',
        ],

        'zones' => [
            'title' => 'Tax zones and price basis',
            'description' => 'The switches that decide which zone a line falls into. The zones themselves — `tax.zones` — along with `tax.product_classes` and `tax.exemptions` are nested maps and stay in config/invoices.php; only single values live here. The price basis (`tax.prices_include_tax`) stays there too: it has three states — gross, net and "not answered yet" — and the fields on this screen carry two. Until it is set, the first invoice refuses the split rather than quietly assuming net; a switch would have resolved that state silently on the first save.',
        ],

        'texts' => [
            'title' => 'Sentences on the document',
            'description' => 'The sentences printed instead of a tax amount. The wording is the operator\'s call together with their tax adviser; § 14a Abs. 5 UStG prescribes the phrase "Steuerschuldnerschaft des Leistungsempfängers" for reverse charge. The matching citations (`tax.legal_bases`) stay in config/invoices.php: they belong to the rules that produce them, and changing a citation without its rule makes the audit trail lie.',
        ],

    ],

    'fields' => [

        'seller_name' => [
            'label' => 'Name',
            'description' => 'Company or name of the supplier, as it should read on the invoice.',
        ],
        'seller_address' => [
            'label' => 'Address',
            'description' => 'Multi-line. Line breaks are kept on the document.',
        ],
        'seller_vat_id' => [
            'label' => 'VAT ID',
            'description' => 'Needed on the document for reverse charge, § 14a UStG.',
        ],
        'seller_tax_number' => [
            'label' => 'Tax number',
            'description' => 'An alternative to the VAT ID; § 14 Abs. 4 Nr. 2 UStG wants one of the two.',
        ],
        'seller_email' => [
            'label' => 'Email',
            'description' => 'The address on the document for questions.',
        ],
        'seller_iban' => [
            'label' => 'IBAN',
            'description' => 'It is printed on every invoice this addon writes, so it is not a secret.',
        ],

        'number_prefix' => [
            'label' => 'Prefix',
            'description' => 'Precedes every number of this brand, e.g. RE.',
        ],
        'number_pad' => [
            'label' => 'Digits',
            'description' => 'How many digits the running counter is padded to with zeros.',
        ],

        'auto_issue' => [
            'label' => 'Issue automatically',
            'description' => 'An invoice on every paid payment, a credit note on every full refund. Off means the host decides when, through the Invoices facade.',
        ],
        'small_amount_cent' => [
            'label' => 'Small-amount threshold (cents)',
            'description' => 'Below this gross amount § 33 UStDV allows an invoice without the recipient\'s name and address. Above it § 14 UStG wants both, and without them no document is written.',
        ],
        'delivery_enabled' => [
            'label' => 'Send to the buyer',
            'description' => 'Off means the host sends them itself; the InvoiceIssued event stays.',
        ],
        'delivery_subject' => [
            'label' => 'Subject',
            'description' => ':number is replaced by the invoice number.',
        ],
        'delivery_filename' => [
            'label' => 'File name',
            'description' => ':number is replaced by the invoice number.',
        ],

        'tax_small_business_enabled' => [
            'label' => 'Apply the small business scheme',
            'description' => 'On means no VAT is shown and the § 19 UStG note goes on the invoice.',
        ],
        'tax_small_business_eu_scheme' => [
            'label' => 'EU small business scheme, § 19a UStG',
            'description' => 'On if you take part in the EU scheme (the "EX" number, since 2025). Affected lines then carry the § 19a note instead of the § 19 one.',
        ],
        'tax_small_business_eu_threshold_mode' => [
            'label' => 'EU threshold of €10,000',
            'description' => 'A fact about your year, not about one line: whether your EU-wide B2C turnover is below or above the threshold. Above it and without the EU scheme, the result carries a warning that 0 % is probably wrong.',
        ],

        'tax_business_only_enabled' => [
            'label' => 'Sell to businesses only',
            'description' => 'Removes the whole apparatus for consumers abroad: the threshold, OSS, registrations, consumer-protection duties. Off means the gate stops asking and the ordinary rules answer consumers again.',
        ],
        'tax_business_only_require_company' => [
            'label' => 'Require a company name',
            'description' => 'The evidence the business-only rule costs — one field.',
        ],
        'tax_vat_id_check_enabled' => [
            'label' => 'Confirm the VAT ID',
            'description' => 'Off leaves every check at "unchecked", which means no EU sale gets through the gate. Off is for a test bench.',
        ],
        'tax_vat_id_check_timeout' => [
            'label' => 'Timeout (seconds)',
            'description' => 'Past it the check is pending, not invalid.',
        ],
        'tax_vat_id_check_cache_hours' => [
            'label' => 'Remember a confirmation (hours)',
            'description' => 'Only confirmed numbers are remembered; pending and invalid never are. 0 remembers nothing.',
        ],

        'tax_merchant_country' => [
            'label' => 'Country of establishment',
            'description' => 'Where the seller sits. Decides what counts as domestic, as EU and as export. Two letters, e.g. DE.',
        ],
        'tax_merchant_vat_id' => [
            'label' => 'Own VAT ID for the tax rules',
            'description' => 'Needed on the document for reverse charge, § 14a UStG. As a rule the same number as the seller\'s above.',
        ],
        'tax_default_product_class' => [
            'label' => 'Fallback tax class',
            'description' => 'Used for products not listed in `tax.product_classes` (config/invoices.php). Empty is the honest default: it makes an unconfigured product visible instead of silently taxing it.',
        ],
        'tax_assume_country_when_missing' => [
            'label' => 'Assume a country when none is recorded',
            'description' => 'Payments from before the buyer-country column existed carry no country. Empty means those come back undetermined and get looked at. A country here is an assumption, and it is yours — set it only if you know every one of them was domestic.',
        ],
        'tax_oss_destination_taxation' => [
            'label' => 'Destination taxation, OSS',
            'description' => 'On once you are registered for OSS: B2C sales into the EU then carry the recipient country\'s rate. That needs the zones for those countries filled in in config/invoices.php, or those lines come back undetermined.',
        ],

        'tax_texts_small_business' => [
            'label' => 'Small business, § 19 UStG',
            'description' => '',
        ],
        'tax_texts_small_business_eu' => [
            'label' => 'EU small business scheme, § 19a UStG',
            'description' => '',
        ],
        'tax_texts_reverse_charge' => [
            'label' => 'Reverse charge',
            'description' => '§ 14a Abs. 5 UStG prescribes the phrase "Steuerschuldnerschaft des Leistungsempfängers".',
        ],
        'tax_texts_intra_community_supply' => [
            'label' => 'Intra-community supply',
            'description' => '',
        ],
        'tax_texts_export' => [
            'label' => 'Export',
            'description' => '',
        ],
        'tax_texts_outside_scope' => [
            'label' => 'Outside the scope',
            'description' => '',
        ],
        'tax_texts_zero_rate' => [
            'label' => 'No tax shown',
            'description' => '',
        ],

    ],

    'options' => [

        'eu_threshold_mode' => [
            'below' => 'Below the threshold',
            'above' => 'Above the threshold',
        ],

    ],

];
