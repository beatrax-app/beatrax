<?php

declare(strict_types=1);

return [
    'title' => 'Berichte',
    'page_title' => 'Berichte · Beatrax',
    'subtitle' => 'Stelle einen Bericht aus deinem Hauptbuch zusammen.',
    'controls_aria' => 'Berichtseinstellungen',
    'result_aria' => 'Berichtsergebnis',
    'dismiss' => 'Ausblenden',

    'metric' => [
        'heading' => 'Kennzahl',
        'spend' => 'Ausgaben',
        'income' => 'Einnahmen',
        'net' => 'Netto',
        'net_worth' => 'Nettovermögen',
        'fallback' => 'Betrag',
    ],

    'group_by' => 'Gruppieren nach',

    'dimension' => [
        'category' => 'Kategorie',
        'time_bucket' => 'Zeitfenster',
        'counterparty' => 'Zahlungspartner',
        'account' => 'Konto',
    ],

    'period' => [
        'heading' => 'Zeitraum',
        'this_month' => 'Dieser Monat',
        'last_3_months' => 'Letzte 3 Monate',
        'last_6_months' => 'Letzte 6 Monate',
        'last_12_months' => 'Letzte 12 Monate',
        'ytd' => 'Seit Jahresbeginn',
        'this_year' => 'Dieses Jahr',
        'custom' => 'Eigener Zeitraum',
        'from' => 'Von',
        'to' => 'Bis',
    ],

    'currency' => [
        'heading' => 'Währung',
        'aria' => 'Währungsmodus',
        'base' => 'Basis',
        'original' => 'Original',
    ],

    'granularity' => [
        'heading' => 'Granularität',
        'aria' => 'Zeitliche Granularität',
        'monthly' => 'Monatlich',
        'weekly' => 'Wöchentlich',
    ],

    'filters' => [
        'heading' => 'Filter',
    ],

    'compare' => 'Mit vorherigem Zeitraum vergleichen',

    'viz' => [
        'heading' => 'Visualisierung',
        'table' => 'Tabelle',
        'bar' => 'Balken',
        'line' => 'Linie',
        'donut' => 'Ring',
    ],

    'actions' => [
        'update_report' => 'Bericht aktualisieren',
        'save_report' => 'Bericht speichern',
        'report_name' => 'Berichtsname',
        'update' => 'Aktualisieren',
        'save' => 'Speichern',
        'cancel' => 'Abbrechen',
        'export_csv' => 'CSV exportieren',
    ],

    'updating' => '… Wird aktualisiert',

    'empty' => [
        'heading' => 'Für diese Auswahl gibt es nichts anzuzeigen',
        'body' => 'Erweitere den Zeitraum oder entferne einen Filter.',
    ],

    'total_prefix' => 'Gesamt',
    'total' => 'Gesamt',
    'vs_previous' => 'vs. vorheriger Zeitraum',
    'view_transactions' => 'Transaktionen ansehen',

    'fx_excluded' => ':count Konto nicht umgerechnet — kein Kurs verfügbar|:count Konten nicht umgerechnet — kein Kurs verfügbar',

    'group_header' => [
        'category' => 'Kategorie',
        'counterparty' => 'Zahlungspartner',
        'account' => 'Konto',
        'month' => 'Monat',
        'default' => 'Gruppe',
    ],

    'chart' => [
        'bar_title' => 'Klicke auf einen Balken, um die zugehörigen Transaktionen zu sehen',
        'line_title' => 'Klicke auf einen Punkt, um die zugehörigen Transaktionen zu sehen',
        'donut_title' => 'Klicke auf ein Segment, um die zugehörigen Transaktionen zu sehen',
    ],

    'flash' => [
        'saved' => 'Bericht gespeichert.',
        'updated' => 'Bericht aktualisiert.',
    ],

    'filter' => [
        'account' => 'Konto',
        'account_count' => ':count Konto|:count Konten',
        'remove_account' => 'Kontofilter entfernen',
        'account_dialog' => 'Kontofilter',

        'category' => 'Kategorie',
        'category_count' => ':count Kategorie|:count Kategorien',
        'remove_category' => 'Kategoriefilter entfernen',
        'category_dialog' => 'Kategoriefilter',

        'counterparty' => 'Zahlungspartner',
        'counterparty_count' => ':count Zahlungspartner|:count Zahlungspartner',
        'remove_counterparty' => 'Zahlungspartnerfilter entfernen',
        'counterparty_dialog' => 'Zahlungspartnerfilter',

        'amount' => 'Betrag',
        'remove_amount' => 'Betragsfilter entfernen',
        'amount_dialog' => 'Betragsfilter',
        'dir_both' => 'Beide',
        'dir_in' => 'Ein',
        'dir_out' => 'Aus',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Mindestbetrag',
        'max_aria' => 'Höchstbetrag',
    ],
];
