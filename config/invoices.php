<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Write an invoice by itself
    |--------------------------------------------------------------------------
    |
    | On every paid payment, and a credit note on every full refund. Off means
    | the host decides when — through the `Invoices` facade — which is what an
    | installation wants when not every payment is a sale.
    |
    */

    'auto_issue' => env('INVOICES_AUTO_ISSUE', true),

    /*
    |--------------------------------------------------------------------------
    | The number series
    |--------------------------------------------------------------------------
    |
    | German law wants a series that is unique and continuous. This addon gives
    | out numbers from a locked counter row rather than from `MAX() + 1`,
    | because both of those properties are about concurrency: two checkouts
    | finishing together would otherwise read the same maximum.
    |
    | `period` is a date format, and it decides how often the series restarts —
    | `Y-m` monthly, `Y` yearly, an empty string never. Changing it later does
    | not renumber anything: the resolved series is stored on the counter.
    |
    | `prefix_per_brand` maps a brand id to its own prefix. One series per brand
    | is the point: two brands sharing a counter give each of them a series with
    | holes in it, and it is each brand that has to answer for its own numbering.
    |
    */

    'number' => [
        'prefix' => env('INVOICES_PREFIX', 'RE'),
        'period' => env('INVOICES_PERIOD', 'Y-m'),
        'separator' => '-',
        'pad' => 3,
        'prefix_per_brand' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Who is sending the invoice
    |--------------------------------------------------------------------------
    |
    | Frozen onto every invoice at the moment it is written, because an invoice
    | that changes when somebody edits a setting is not an invoice. Fill this in
    | before the first one goes out — a document missing the sender's details is
    | not a valid invoice in Germany, and it cannot be corrected afterwards.
    |
    */

    'seller' => [
        'name' => env('INVOICES_SELLER_NAME'),
        'address' => env('INVOICES_SELLER_ADDRESS'),
        'vat_id' => env('INVOICES_SELLER_VAT_ID'),
        'tax_number' => env('INVOICES_SELLER_TAX_NUMBER'),
        'email' => env('INVOICES_SELLER_EMAIL'),
        'iban' => env('INVOICES_SELLER_IBAN'),
    ],

    'seller_per_brand' => [],

    /*
    |--------------------------------------------------------------------------
    | The document as a file
    |--------------------------------------------------------------------------
    |
    | Rendered from the same Blade template the preview shows — there is no
    | second layout that can drift away from the first. The engine behind it is
    | bound to `Contracts\PdfRenderer`; a host that already runs a headless
    | browser rebinds that interface and this section stops mattering.
    |
    */

    'pdf' => [
        'paper' => 'A4',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sending it to the buyer
    |--------------------------------------------------------------------------
    |
    | Triggered by the `InvoiceIssued` event, so exactly the invoices that were
    | written get sent, once each. Off means the host sends them itself — the
    | event is still there to hang a listener on.
    |
    | The mail leaves through brand-context's BrandMailer, which means a brand
    | that declared its own sender identity uses it, and a brand that declared a
    | broken one sends nothing at all rather than borrowing another brand's
    | name. `:number` is the invoice number in both strings below.
    |
    */

    'delivery' => [
        'enabled' => env('INVOICES_DELIVER', true),
        'subject' => 'Ihre Rechnung :number',
        'filename' => 'Rechnung-:number.pdf',
    ],

    /*
    |--------------------------------------------------------------------------
    | Where a Kleinbetragsrechnung ends
    |--------------------------------------------------------------------------
    |
    | Below this gross amount, § 33 UStDV allows an invoice without the
    | recipient's name and address — which is the ordinary case for a digital
    | product bought by an address and nothing else. Above it, § 14 UStG wants
    | both, and this addon refuses to write the document without them rather
    | than issue something that is not an invoice.
    |
    */

    'small_amount_cent' => 25000,

    /*
    |--------------------------------------------------------------------------
    | VAT
    |--------------------------------------------------------------------------
    |
    | Everything `TaxRules` needs to answer which rate applies to a line, and why.
    | It never guesses: a case this block has no rule for comes back undetermined
    | rather than at 19 %, because a wrong rate looks like an answer and a missing
    | one does not. That is why `product_classes` and `zones` ship empty — fill
    | them in before the first invoice goes out.
    |
    | Not in here: the OSS threshold (a state over time, see `oss` below) and the
    | VIES lookup (a network call, and this is a calculation). Both are explained
    | in the docblock of `TaxRules`.
    |
    */

    'tax' => [

        // § 19 UStG. A switch that suspends all of the below: no tax is shown, on
        // anything, and the reason goes on the invoice. German law wants the note.
        //
        // The two keys underneath only matter for a consumer in another EU country.
        // § 19 is a domestic rule: for a digital supply to such a buyer the place of
        // supply is in their country (§ 3a Abs. 5 UStG; § 3c for goods), and once the
        // seller's EU-wide B2C turnover passes 10.000 € a year (§ 3a Abs. 5 Satz 3,
        // § 3c Abs. 4 UStG) that country's VAT is due — unless the seller takes part
        // in the EU small business scheme (§ 19a UStG, since 2025, the "EX" number).
        //
        //   eu_scheme          true if you use the EU small business scheme, § 19a UStG.
        //                      Such lines then carry the § 19a note instead of § 19.
        //   eu_threshold_mode  'below' (default) or 'above' the 10.000 € threshold.
        //                      Below it the place of supply stays at home and § 19
        //                      answers. Above it, without `eu_scheme`, the result
        //                      carries a warning that 0 % is probably wrong.
        //
        // Neither is computed: both are facts about your year, not about one line.
        // This is the addon's reading of the law, not tax advice — confirm it.
        'small_business' => [
            'enabled' => env('INVOICES_SMALL_BUSINESS', false),
            'eu_scheme' => env('INVOICES_SMALL_BUSINESS_EU_SCHEME', false),
            'eu_threshold_mode' => env('INVOICES_SMALL_BUSINESS_EU_THRESHOLD', 'below'),
        ],

        // Where the seller sits. Decides what counts as domestic, as EU, and as export.
        'merchant_country' => env('INVOICES_MERCHANT_COUNTRY', 'DE'),

        // The seller's own VAT ID. Needed on the document for reverse charge, § 14a UStG.
        'merchant_vat_id' => env('INVOICES_SELLER_VAT_ID'),

        // Are the prices stored on products gross or net? Global, as in Cargo.
        /*
         * Whether the prices in your product catalogue already contain tax.
         *
         * Deliberately unset. There is no safe default: 1900 is either €19.00
         * gross or €19.00 net, the two produce different invoices for the same
         * payment, and both look right. The first invoice refuses until you
         * have answered — once, here.
         *
         * `true` is the usual answer for a German consumer shop: under the
         * Preisangabenverordnung the price a buyer is shown is the final price
         * including VAT, so a catalogue entry of 19 € means 19 € gross.
         */
        'prices_include_tax' => env('INVOICES_PRICES_INCLUDE_TAX'),

        // Payments from before the buyer-country column existed have no country.
        // Null means those come back undetermined and have to be looked at. Set a
        // country here only if you know every one of them was domestic: it is an
        // assumption, and it is the operator's, not the calculation's.
        'assume_country_when_missing' => null,

        // Which tax class a product belongs to. The handle is the product's handle.
        // A handle that is not listed has no class, and no class means no rate.
        'product_classes' => [
            // 'cw-kurs' => 'standard',
            // 'chorwerk-noten' => 'reduced',
            // 'einzelunterricht' => 'teaching',
        ],

        // Used when a product handle is not listed above. Null is the honest default:
        // it makes an unconfigured product visible instead of silently taxing it.
        'default_product_class' => null,

        // Tax classes that are exempt rather than rated, each with the note that has
        // to appear on the invoice (§ 14 Abs. 4 Nr. 8 UStG). An exemption without a
        // reason is refused. `domestic_only` says the exemption is a German rule and
        // stops it from being applied to a supply whose place is abroad.
        'exemptions' => [
            // 'teaching' => [
            //     'reason' => 'Steuerfrei nach § 4 Nr. 20 Buchst. a UStG.',
            //     'legal_basis' => '§ 4 Nr. 20 Buchst. a UStG',
            //     'domestic_only' => true,
            // ],
        ],

        // Rates per zone, in basis points: 1900 is 19 %, 700 is 7 %, 0 is zero-rated.
        // A zone lists the countries it covers; `'*'` is the placeholder for all the
        // rest, and an explicitly named country always beats it. Zones do not stack —
        // the most specific match wins.
        //
        // Only Germany ships filled in, on purpose. Foreign rates change, and a rate
        // that is stale here is worse than one that is missing: missing says so.
        'zones' => [
            'de' => [
                'countries' => ['DE'],
                'rates' => [
                    'standard' => 1900,
                    'reduced' => 700,
                ],
            ],
            // 'at' => ['countries' => ['AT'], 'rates' => ['standard' => 2000, 'reduced' => 1000]],
            // 'rest' => ['countries' => ['*'], 'rates' => ['standard' => 1900, 'reduced' => 700]],
        ],

        // Point 6 of the ticket, as far as a calculation can carry it. Below the
        // 10.000 € threshold a B2C sale into the EU carries the seller's own rate;
        // above it, the recipient country's. Which side you are on is a fact about
        // your turnover over the year, not something this class can see — so it is a
        // switch. Flip it when you register for OSS, and fill in the zones for the
        // countries you sell to, or those lines come back undetermined.
        'oss' => [
            'destination_taxation' => env('INVOICES_OSS_DESTINATION', false),
        ],

        // The sentences that go on the invoice. German, because the invoice is.
        // Wording is the operator's call together with their tax adviser; these are
        // the customary formulations, and § 14a Abs. 5 UStG prescribes the phrase
        // "Steuerschuldnerschaft des Leistungsempfängers" for reverse charge.
        'texts' => [
            'small_business' => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.',
            'small_business_eu' => 'Steuerfrei nach der EU-Kleinunternehmerregelung, § 19a UStG.',
            'reverse_charge' => 'Steuerschuldnerschaft des Leistungsempfängers.',
            'intra_community_supply' => 'Steuerfreie innergemeinschaftliche Lieferung.',
            'export' => 'Steuerfreie Ausfuhrlieferung.',
            'outside_scope' => 'Nicht im Inland steuerbar; der Leistungsort liegt im Land des Empfängers.',
            'zero_rate' => 'Kein Umsatzsteuerausweis.',
        ],

        // Stored alongside each decision so an audit can follow it years later.
        'legal_bases' => [
            'small_business' => '§ 19 UStG',
            'small_business_eu' => '§ 19a UStG i. V. m. Art. 284 MwStSystRL',
            'reverse_charge' => '§ 3a Abs. 2 UStG i. V. m. Art. 196 MwStSystRL, Hinweispflicht § 14a Abs. 5 UStG',
            'intra_community_supply' => '§ 4 Nr. 1 Buchst. b i. V. m. § 6a UStG',
            'export' => '§ 4 Nr. 1 Buchst. a i. V. m. § 6 UStG',
            'outside_scope' => '§ 3a UStG',
        ],

    ],

];
