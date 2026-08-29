<?php

return [

    // Die Kennzahlen, die dieses Addon dem Insights-Dashboard anbietet. Sonst
    // hat das Paket noch keine sichtbaren Texte: eine Rechnung rendert aus
    // ihren eigenen eingefrorenen Feldern, und die Steuersaetze sind die
    // Formulierungen des Betreibers aus der Konfiguration.
    'metric_group' => 'Rechnungen',

    'metric_issued' => 'Ausgestellte Dokumente',
    'metric_issued_description' => 'Rechnungen und Stornos mit Rechnungsdatum in diesem Zeitraum. Eine Anzahl Dokumente, ein Storno zaehlt also als eines davon.',

    'metric_net' => 'Netto berechnet',
    'metric_net_description' => 'Nettobetraege mit Rechnungsdatum in diesem Zeitraum, abzueglich dessen, was Stornos zurueckgenommen haben.',

    'metric_gross' => 'Brutto berechnet',
    'metric_gross_description' => 'Bruttobetraege mit Rechnungsdatum in diesem Zeitraum, abzueglich dessen, was Stornos zurueckgenommen haben.',

    'metric_tax' => 'Umsatzsteuer berechnet',
    'metric_tax_description' => 'Die ausgewiesene Steuer der Dokumente dieses Zeitraums, abzueglich dessen, was Stornos zurueckgenommen haben.',

    'metric_breakdown_kind' => 'Art',
    'metric_breakdown_buyer_country' => 'Land',
    'metric_breakdown_tax_rate_bp' => 'Steuersatz',

    'metric_kind_invoice' => 'Rechnung',
    'metric_kind_credit_note' => 'Storno',

    // Der Satz, mit dem eine Zeile besteuert wurde, aus Basispunkten gelesen:
    // 1900 sind 19 %.
    'metric_tax_rate' => ':rate %',

    // Wie die Zeilen heissen, die in der Dimension keinen Wert haben. Ein
    // Dokument ohne Land ist eine Zeile, keine Auslassung.
    'metric_no_kind' => 'Ohne Art',
    'metric_no_buyer_country' => 'Ohne Land',
    'metric_no_tax_rate_bp' => 'Ohne Satz',

];
