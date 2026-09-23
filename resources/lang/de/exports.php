<?php

return [

    // Die Utility „Rechnungsexport“ im Control Panel und die Texte, die der
    // Export selbst schreibt. Die Spaltenköpfe der CSV stehen nicht hier: sie
    // sind fest, weil ein gespeichertes Import-Schema in DATEV oder Lexware an
    // ihnen hängt (Export\CsvExport).

    'title' => 'Rechnungsexport',
    'nav_title' => 'Rechnungsexport',
    'description' => 'Rechnungen und Stornos eines Zeitraums für Steuer und Buchhaltung: als CSV, als ZIP mit allen PDFs und als Steuerbericht.',
    'intro' => 'Alle Zahlen kommen aus den ausgestellten Belegen, Stornos mit Minus. Nichts wird neu berechnet. Auf der Kommandozeile: php artisan invoices:export',

    'period_label' => ':from bis :to',
    'period_heading' => 'Zeitraum',
    'period_current' => 'Gewählt: :period',
    'period_from' => 'Erster Tag',
    'period_to' => 'Letzter Tag',
    'period_apply' => 'Anzeigen',
    'error_order' => 'Der Zeitraum endet (:to), bevor er beginnt (:from).',
    'error_month' => '„:value“ ist kein Monat. Erwartet wird JJJJ-MM, zum Beispiel 2026-08.',
    'error_quarter' => '„:value“ ist kein Quartal. Erwartet wird JJJJ-Q1 bis JJJJ-Q4.',
    'error_year' => '„:value“ ist kein Jahr. Erwartet wird JJJJ.',
    'error_date' => '„:value“ ist kein Datum. Erwartet wird JJJJ-MM-TT oder TT.MM.JJJJ.',
    'error_incomplete' => 'Ein Zeitraum braucht einen ersten und einen letzten Tag.',
    'error_csv_option' => 'Für :key gibt es „:value“ nicht. Möglich: :allowed.',
    'error_csv_comma' => 'Ein Komma als Trennzeichen und als Dezimalzeichen lässt sich nicht auseinanderhalten. Bitte Semikolon oder Dezimalpunkt wählen.',
    'period_invalid' => 'Dieser Zeitraum lässt sich nicht lesen: :reason Angezeigt wird deshalb der Vormonat.',
    'preset_last_month' => 'Letzter Monat',
    'preset_this_month' => 'Dieser Monat',
    'preset_last_quarter' => 'Letztes Quartal',
    'preset_this_quarter' => 'Dieses Quartal',
    'preset_last_year' => 'Letztes Jahr',
    'preset_this_year' => 'Dieses Jahr',
    'brand_scope' => 'Nur Belege der Marke :brand.',

    'report_heading' => 'Steuerbericht',
    'report_subheading' => '{0} Keine Belege|{1} Ein Beleg|[2,*] :count Belege',
    'report_subheading_credit_notes' => '{1} , davon ein Storno|[2,*] , davon :count Stornos',
    'report_empty' => 'In diesem Zeitraum wurde weder eine Rechnung noch ein Storno ausgestellt.',
    'report_treatment' => 'Steuerart',
    'report_country' => 'Leistungsort',
    'report_rate' => 'Satz',
    'report_documents' => 'Belege',
    'report_net' => 'Netto',
    'report_tax' => 'Steuer',
    'report_gross' => 'Brutto',
    'report_total' => 'Summe',
    'report_oss' => 'Davon Steuer in anderen EU-Ländern (One-Stop-Shop): :amount',
    'report_derived' => '{1} Eine Zeile stammt aus der Zeit vor Version 2.2 und trägt Steuerart und Leistungsort nicht selbst. Beides ist aus dem Beleg abgeleitet.|[2,*] :count Zeilen stammen aus der Zeit vor Version 2.2 und tragen Steuerart und Leistungsort nicht selbst. Beides ist aus dem Beleg abgeleitet.',
    'report_currencies' => 'Der Zeitraum enthält Belege in mehreren Währungen (:currencies). Die Summen addieren sie ohne Umrechnung.',
    'report_small_business' => 'Kleinunternehmer nach § 19 UStG: der Umsatz steht im Bericht, Steuer fällt keine an.',
    'report_note' => 'Die Zahlen, aus denen eine Meldung ausgefüllt wird. Welche Zahl in welche Zeile gehört, entscheidet Ihre Steuerberatung.',

    'mechanism' => [
        'standard' => 'Steuerpflichtig',
        'small_business' => 'Kleinunternehmer',
        'exempt' => 'Steuerfrei',
        'reverse_charge' => 'Reverse Charge',
        'intra_community_supply' => 'Innergemeinschaftliche Lieferung',
        'outside_scope' => 'Nicht steuerbar',
        'export' => 'Ausfuhr',
    ],

    'download_heading' => 'Herunterladen',
    'download_subheading' => 'Eine Zeile je Beleg und Steuersatz, Stornos mit Minus.',
    'download_csv' => 'Belege als CSV',
    'download_report_csv' => 'Steuerbericht als CSV',
    'format_excel' => 'Semikolon, UTF-8 (Excel, DATEV, Lexware Office)',
    'format_ansi' => 'Semikolon, Windows-1252 (ältere Desktop-Programme)',
    'format_intl' => 'Komma, UTF-8, Dezimalpunkt',

    'archive_heading' => 'PDF-Archiv',
    'archive_subheading' => 'Alle Belege des Zeitraums als PDF in einer ZIP-Datei. Das Archiv entsteht im Hintergrund und steht danach hier bereit.',
    'archive_build' => 'Archiv erstellen',
    'archive_queued' => 'Das Archiv für :period wird erstellt.',
    'archive_none' => 'Noch kein Archiv erstellt.',
    'archive_pending' => 'wird erstellt',
    'archive_failed' => 'fehlgeschlagen',
    'archive_stale' => 'Seit mehr als :minutes Minuten nicht fertig geworden. Der Job wurde vermutlich abgebrochen; bitte neu erstellen.',
    'archive_download' => 'Herunterladen',
    'archive_empty_file' => 'Im Zeitraum :period wurde kein Beleg ausgestellt.',

];
