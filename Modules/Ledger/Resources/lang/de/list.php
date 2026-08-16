<?php

declare(strict_types=1);

return [
    'page_title' => 'Transaktionen',
    'heading' => 'Transaktionen',

    'subtitle_searching' => 'Gesamte Historie wird durchsucht',
    'subtitle_full' => 'Gesamte Historie.',
    'subtitle_recent' => 'Aktuelle Transaktionen (letzte 90 Tage).',

    'currency_aria' => 'Währungsansicht',
    'currency_eur' => 'Nur EUR',
    'currency_original' => 'Originalwährung',

    'show_recent' => 'Nur aktuelle anzeigen',
    'show_full' => 'Gesamte Historie anzeigen',

    'empty_period' => 'Nichts in diesem Zeitraum.',

    'loading_more' => 'Weitere Transaktionen werden geladen',
    'load_more' => 'Mehr laden',

    'split_badge' => 'Aufteilung · :count',
    'split_expand_aria' => 'Auf :count Kategorien aufgeteilt — zum Ansehen aufklappen',

    'chain_badge' => 'Kette',
    'chain_title' => 'Teil einer Kette — öffne diese Zeile zum Ansehen',

    'table' => [
        'date' => 'Datum',
        'counterparty' => 'Zahlungspartner',
        'category' => 'Kategorie',
        'tax' => 'Steuer',
        'status' => 'Status',
        'amount' => 'Betrag',
    ],

    'search' => [
        'placeholder' => 'Händler, Beschreibung, Notizen suchen…',
        'placeholder_short' => 'Transaktionen suchen…',
        'aria' => 'Transaktionen suchen',
        'clear_all' => 'Alles zurücksetzen',
        'filters' => 'Filter',
        'open_filters_aria' => 'Filter öffnen',
        'apply' => 'Anwenden',
        'clear' => 'Zurücksetzen',

        'count' => ':count Transaktion|:count Transaktionen',
        'matching_suffix' => 'passen zu den Filtern',
        'flow' => ':out aus / :in ein',
    ],

    'no_results' => [
        'heading' => 'Keine Treffer',
        'remove_prompt' => 'Entferne einen Filter, der die Ergebnisse einschränken könnte:',
        'no_match_query' => 'Keine Transaktionen in der gesamten Historie passen zu „:query“.',
        'no_match_filters' => 'Keine Transaktionen passen zu den angewendeten Filtern.',
        'did_you_mean' => 'Meintest du:',
        'account_fallback' => 'Konto :id',
        'category_fallback' => 'Kategorie :id',
    ],

    'filter' => [
        'date' => 'Datum',
        'account' => 'Konto',
        'amount' => 'Betrag',
        'category' => 'Kategorie',
        'date_range' => 'Zeitraum',
        'from' => 'Von',
        'to' => 'Bis',
        'custom_range' => 'Eigener Zeitraum ×',
        'after' => 'Nach :date ×',
        'before' => 'Vor :date ×',
        'dir_both' => 'Beide',
        'dir_in' => 'Ein',
        'dir_out' => 'Aus',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Mindestbetrag',
        'max_aria' => 'Höchstbetrag',
        'after_aria' => 'Nach Datum',
        'before_aria' => 'Vor Datum',
        'acct' => ':count Konto|:count Konten',
        'cat' => ':count Kategorie|:count Kategorien',
        'date_dialog' => 'Datumsfilter',
        'account_dialog' => 'Kontofilter',
        'amount_dialog' => 'Betragsfilter',
        'category_dialog' => 'Kategoriefilter',
        'remove_date_aria' => 'Datumsfilter entfernen',
        'remove_account_aria' => 'Kontofilter entfernen',
        'remove_amount_aria' => 'Betragsfilter entfernen',
        'remove_category_aria' => 'Kategoriefilter entfernen',

        'remove_named_aria' => 'Filter :name entfernen',
    ],

    'date_preset' => [
        'this_month' => 'Dieser Monat',
        'last_month' => 'Letzter Monat',
        'this_year' => 'Dieses Jahr',
        'last_year' => 'Letztes Jahr',
    ],
];
