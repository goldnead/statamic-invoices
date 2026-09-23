<?php

return [

    // Beschriftungen der Einstellungs-Seite. Die Seite selbst gehoert
    // statamic-brand-context; dieses Addon liefert nur die Feldliste
    // (Support\Settings) und die Woerter dazu.

    'permission_group' => 'Rechnungen',
    'permission_manage_settings' => 'Rechnungs-Einstellungen verwalten',

    'groups' => [

        'seller' => [
            'title' => 'Verkaeufer',
            'description' => 'Wer die Rechnung stellt. Diese Angaben werden beim Ausstellen auf jedes Dokument eingefroren und lassen sich danach nicht mehr korrigieren — eine Rechnung ohne sie ist nach § 14 UStG keine Rechnung. Auf einem Mehrmarken-Host gilt, was hier steht, fuer die Marke im Markenwaehler; `seller_per_brand` in config/invoices.php wird dadurch ueberfluessig.',
        ],

        'number' => [
            'title' => 'Nummernkreis',
            'description' => 'Die Serie, aus der Rechnungsnummern vergeben werden. Je Marke eine eigene: zwei Marken an einem Zaehler geben beiden eine Serie mit Luecken, und jede Marke muss fuer ihre eigene Nummerierung geradestehen. Zeitraum und Trennzeichen bleiben in config/invoices.php — sie werden einmal beim Einrichten entschieden, und „nicht gesetzt" liesse sich im Formular nicht von „leer" unterscheiden.',
        ],

        'issuing' => [
            'title' => 'Ausstellen und Versand',
            'description' => 'Wann ein Dokument entsteht und wie es beim Kaeufer ankommt.',
        ],

        'small_business' => [
            'title' => 'Kleinunternehmer, § 19 UStG',
            'description' => 'Der Schalter, der alles darunter aussetzt: keine Steuer, auf nichts, und der Grund steht auf der Rechnung. Die beiden weiteren Felder betreffen nur Verbraucher in anderen EU-Laendern. Das ist die Lesart dieses Addons, keine Steuerberatung.',
        ],

        'buyers' => [
            'title' => 'Kaeuferkreis und USt-IdNr.',
            'description' => 'Wer kaufen darf und wie die USt-IdNr. des Kaeufers bestaetigt wird.',
        ],

        'zones' => [
            'title' => 'Steuerzonen und Preisbasis',
            'description' => 'Die Schalter, die entscheiden, welche Zone eine Zeile trifft. Die Zonen selbst — `tax.zones` — sowie `tax.product_classes` und `tax.exemptions` sind verschachtelte Abbildungen und stehen weiterhin in config/invoices.php; hier steht nur, was ein einzelner Wert ist. Auch die Preisbasis (`tax.prices_include_tax`) bleibt dort: sie hat drei Zustaende — brutto, netto und „noch nicht beantwortet" —, und die Felder dieser Seite tragen zwei. Solange nichts gesetzt ist, verweigert die erste Rechnung die Aufteilung, statt still netto anzunehmen; ein Schalter haette diesen Zustand beim ersten Speichern lautlos aufgeloest.',
        ],

        'texts' => [
            'title' => 'Saetze auf dem Beleg',
            'description' => 'Die Saetze, die statt eines Steuerbetrags auf der Rechnung stehen. Die Formulierung ist Sache des Betreibers und seines Steuerberaters; § 14a Abs. 5 UStG schreibt fuer die Umkehr der Steuerschuld den Wortlaut „Steuerschuldnerschaft des Leistungsempfaengers" vor. Die zugehoerigen Fundstellen (`tax.legal_bases`) bleiben in config/invoices.php: sie gehoeren zu den Regeln, die sie erzeugen, und eine Fundstelle ohne ihre Regel zu aendern, macht die Beleglage falsch.',
        ],

    ],

    'fields' => [

        'seller_name' => [
            'label' => 'Name',
            'description' => 'Firma oder Name des leistenden Unternehmers, so wie er auf der Rechnung stehen soll.',
        ],
        'seller_address' => [
            'label' => 'Anschrift',
            'description' => 'Mehrzeilig. Zeilenumbrueche werden auf dem Dokument uebernommen.',
        ],
        'seller_vat_id' => [
            'label' => 'USt-IdNr.',
            'description' => 'Wird bei Reverse Charge auf dem Dokument gebraucht, § 14a UStG.',
        ],
        'seller_tax_number' => [
            'label' => 'Steuernummer',
            'description' => 'Alternativ zur USt-IdNr., § 14 Abs. 4 Nr. 2 UStG verlangt eine von beiden.',
        ],
        'seller_email' => [
            'label' => 'E-Mail',
            'description' => 'Rueckfrageadresse auf dem Dokument.',
        ],
        'seller_iban' => [
            'label' => 'IBAN',
            'description' => 'Steht auf jeder Rechnung, die dieses Addon schreibt, und ist damit kein Geheimnis.',
        ],

        'number_prefix' => [
            'label' => 'Praefix',
            'description' => 'Steht vor jeder Nummer dieser Marke, zum Beispiel RE.',
        ],
        'number_pad' => [
            'label' => 'Stellen',
            'description' => 'Auf wie viele Stellen der laufende Zaehler mit Nullen aufgefuellt wird.',
        ],

        'auto_issue' => [
            'label' => 'Automatisch ausstellen',
            'description' => 'Bei jeder bezahlten Zahlung eine Rechnung, bei jeder vollen Erstattung ein Storno. Aus heisst: der Host entscheidet ueber die Invoices-Fassade, wann.',
        ],
        'small_amount_cent' => [
            'label' => 'Grenze Kleinbetragsrechnung (Cent)',
            'description' => 'Bis zu diesem Bruttobetrag erlaubt § 33 UStDV eine Rechnung ohne Name und Anschrift des Empfaengers. Darueber verlangt § 14 UStG beides, und ohne diese Angaben wird kein Dokument geschrieben.',
        ],
        'delivery_enabled' => [
            'label' => 'An den Kaeufer senden',
            'description' => 'Aus heisst: der Host versendet selbst, das Ereignis InvoiceIssued bleibt.',
        ],
        'delivery_subject' => [
            'label' => 'Betreff',
            'description' => ':number wird durch die Rechnungsnummer ersetzt.',
        ],
        'delivery_filename' => [
            'label' => 'Dateiname',
            'description' => ':number wird durch die Rechnungsnummer ersetzt.',
        ],

        'tax_small_business_enabled' => [
            'label' => 'Kleinunternehmerregelung anwenden',
            'description' => 'An heisst: keine Umsatzsteuer wird ausgewiesen, und der Hinweis nach § 19 UStG steht auf der Rechnung.',
        ],
        'tax_small_business_eu_scheme' => [
            'label' => 'EU-Kleinunternehmerregelung, § 19a UStG',
            'description' => 'An, wenn Sie an der EU-Regelung teilnehmen (die „EX"-Nummer, seit 2025). Betroffene Zeilen tragen dann den Hinweis nach § 19a statt nach § 19.',
        ],
        'tax_small_business_eu_threshold_mode' => [
            'label' => 'EU-Schwelle 10.000 EUR',
            'description' => 'Eine Tatsache ueber Ihr Jahr, nicht ueber eine Zeile: liegt Ihr EU-weiter B2C-Umsatz unter oder ueber der Schwelle. Darueber und ohne EU-Regelung traegt das Ergebnis eine Warnung, dass 0 % wahrscheinlich falsch ist.',
        ],

        'tax_business_only_enabled' => [
            'label' => 'Nur an Unternehmen verkaufen',
            'description' => 'Nimmt den ganzen Apparat fuer Verbraucher im Ausland heraus: Schwelle, OSS, Registrierungen, Verbraucherschutzpflichten. Aus heisst: die Sperre fragt nicht mehr, die gewoehnlichen Regeln antworten wieder auch Verbrauchern.',
        ],
        'tax_business_only_require_company' => [
            'label' => 'Firmenname verlangen',
            'description' => 'Der Nachweis, den die Unternehmensbeschraenkung kostet — ein Feld.',
        ],
        'tax_vat_id_check_enabled' => [
            'label' => 'USt-IdNr. bestaetigen',
            'description' => 'Aus laesst jede Pruefung auf „ungeprueft" stehen, womit kein EU-Verkauf durch die Sperre kommt. Aus ist fuer eine Testumgebung.',
        ],
        'tax_vat_id_check_timeout' => [
            'label' => 'Zeitlimit (Sekunden)',
            'description' => 'Danach gilt die Pruefung als ausstehend, nicht als ungueltig.',
        ],
        'tax_vat_id_check_cache_hours' => [
            'label' => 'Bestaetigung merken (Stunden)',
            'description' => 'Nur bestaetigte Nummern werden gemerkt; ausstehend und ungueltig nie. 0 merkt nichts.',
        ],

        'tax_merchant_country' => [
            'label' => 'Sitzland',
            'description' => 'Wo der Verkaeufer sitzt. Entscheidet, was Inland, was EU und was Ausfuhr ist. Zwei Buchstaben, zum Beispiel DE.',
        ],
        'tax_merchant_vat_id' => [
            'label' => 'Eigene USt-IdNr. fuer die Steuerlogik',
            'description' => 'Wird fuer Reverse Charge auf dem Dokument gebraucht, § 14a UStG. In aller Regel dieselbe Nummer wie oben beim Verkaeufer.',
        ],
        'tax_default_product_class' => [
            'label' => 'Steuerklasse als Rueckfall',
            'description' => 'Gilt fuer Produkte, die in `tax.product_classes` (config/invoices.php) nicht aufgefuehrt sind. Leer ist die ehrliche Vorgabe: sie macht ein unkonfiguriertes Produkt sichtbar, statt es still zu besteuern.',
        ],
        'tax_assume_country_when_missing' => [
            'label' => 'Land annehmen, wenn keins vorliegt',
            'description' => 'Zahlungen aus der Zeit vor der Laenderspalte tragen kein Land. Leer heisst: sie kommen unbestimmt zurueck und werden angesehen. Ein Land hier ist eine Annahme, und zwar Ihre — setzen Sie es nur, wenn Sie wissen, dass jede dieser Zahlungen inlaendisch war.',
        ],
        'tax_oss_destination_taxation' => [
            'label' => 'Bestimmungslandprinzip, OSS',
            'description' => 'An, wenn Sie fuer OSS registriert sind: B2C-Verkaeufe in die EU tragen dann den Satz des Empfaengerlandes. Dafuer muessen die Zonen der Laender, in die Sie verkaufen, in config/invoices.php gefuellt sein oder die mitgelieferten EU-Normalsätze (Schalter darunter) an, sonst kommen diese Zeilen unbestimmt zurueck.',
        ],
        'tax_oss_shipped_rates' => [
            'label' => 'Mitgelieferte EU-Normalsätze',
            'description' => 'Lässt die Tabelle, die dieses Addon mitbringt, den Normalsatz jedes EU-Landes liefern, für das in config/invoices.php keine eigene Zone steht. Wirkt nur zusammen mit dem Bestimmungslandprinzip. Nur Normalsätze, Stand siehe Support\EuStandardRates; die Sätze der Länder, in die Sie verkaufen, bitte prüfen. Eine eigene Zone geht immer vor.',
        ],

        'tax_texts_small_business' => [
            'label' => 'Kleinunternehmer, § 19 UStG',
            'description' => '',
        ],
        'tax_texts_small_business_eu' => [
            'label' => 'EU-Kleinunternehmerregelung, § 19a UStG',
            'description' => '',
        ],
        'tax_texts_reverse_charge' => [
            'label' => 'Umkehr der Steuerschuld',
            'description' => '§ 14a Abs. 5 UStG schreibt den Wortlaut „Steuerschuldnerschaft des Leistungsempfaengers" vor.',
        ],
        'tax_texts_intra_community_supply' => [
            'label' => 'Innergemeinschaftliche Lieferung',
            'description' => '',
        ],
        'tax_texts_export' => [
            'label' => 'Ausfuhrlieferung',
            'description' => '',
        ],
        'tax_texts_outside_scope' => [
            'label' => 'Nicht im Inland steuerbar',
            'description' => '',
        ],
        'tax_texts_zero_rate' => [
            'label' => 'Kein Steuerausweis',
            'description' => '',
        ],

    ],

    'options' => [

        'eu_threshold_mode' => [
            'below' => 'Unter der Schwelle',
            'above' => 'Ueber der Schwelle',
        ],

    ],

];
