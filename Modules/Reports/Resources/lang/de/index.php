<?php

declare(strict_types=1);

return [
    'title' => 'Berichte',
    'page_title' => 'Berichte · Beatrax',
    'saved_report' => 'gespeicherter Bericht|gespeicherte Berichte',
    'pinned_count' => 'angeheftet',
    'dismiss' => 'Ausblenden',

    'build_new' => 'Neuen Bericht erstellen',
    'view_mode_aria' => 'Ansichtsmodus',
    'cards' => 'Karten',
    'list' => 'Liste',

    'empty' => [
        'heading' => 'Noch keine gespeicherten Berichte',
        'body' => 'Erstelle unten einen und speichere ihn, damit er hier erscheint.',
        'cta' => 'Erstelle deinen ersten Bericht →',
    ],

    'pin' => [
        'pinned_aria' => 'Angeheftet — vom Dashboard lösen',
        'pin_aria' => 'Anheften — ans Dashboard anheften',
        'pinned_title' => 'Angeheftet',
        'pin_title' => 'Ans Dashboard anheften',
        'pinned_label' => 'Angeheftet',
        'pin_label' => 'Anheften',
    ],

    'open' => 'Öffnen',
    'edit' => 'Bearbeiten',

    'delete_confirm' => '":name" löschen?',
    'delete_report' => 'Bericht löschen',
    'cancel' => 'Abbrechen',
    'delete' => 'Löschen',
    'delete_aria' => ':name löschen',

    'col' => [
        'name' => 'Name',
        'summary' => 'Zusammenfassung',
        'pinned' => 'Angeheftet',
        'actions' => 'Aktionen',
    ],

    'flash' => [
        'not_found' => 'Bericht nicht gefunden (er wurde möglicherweise in einem anderen Tab gelöscht).',
        'deleted' => 'Bericht gelöscht.',
    ],
    'pin_cap' => 'Du kannst bis zu 3 Berichte anheften. Löse einen, um diesen hinzuzufügen.',

    'summary' => [
        'metric' => [
            'spend' => 'Ausgaben',
            'income' => 'Einnahmen',
            'net' => 'Netto',
            'net_worth' => 'Nettovermögen',
            'fallback' => 'Betrag',
        ],
        'dimension' => [
            'category' => 'Kategorie',
            'time_bucket' => 'Zeitfenster',
            'counterparty' => 'Zahlungspartner',
            'account' => 'Konto',
            'fallback' => 'Kategorie',
        ],
        'period' => [
            'this_month' => 'Dieser Monat',
            'last_3_months' => 'Letzte 3 Monate',
            'last_6_months' => 'Letzte 6 Monate',
            'last_12_months' => 'Letzte 12 Monate',
            'ytd' => 'Seit Jahresbeginn',
            'this_year' => 'Dieses Jahr',
            'custom' => 'Eigener Zeitraum',
        ],
        'with_dimension' => ':metric · nach :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
