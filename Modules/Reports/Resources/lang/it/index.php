<?php

declare(strict_types=1);

return [
    'title' => 'Report',
    'page_title' => 'Report · Beatrax',
    'saved_report' => 'report salvato|report salvati',
    'pinned_count' => 'fissati',
    'dismiss' => 'Ignora',

    'build_new' => 'Crea un nuovo report',
    'view_mode_aria' => 'Modalità di visualizzazione',
    'cards' => 'Schede',
    'list' => 'Elenco',

    'empty' => [
        'heading' => 'Nessun report salvato',
        'body' => 'Creane uno qui sotto e salvalo per vederlo qui.',
        'cta' => 'Crea il tuo primo report →',
    ],

    'pin' => [
        'pinned_aria' => 'Fissato — rimuovi dalla dashboard',
        'pin_aria' => 'Fissa — fissa alla dashboard',
        'pinned_title' => 'Fissato',
        'pin_title' => 'Fissa alla dashboard',
        'pinned_label' => 'Fissato',
        'pin_label' => 'Fissa',
    ],

    'open' => 'Apri',
    'edit' => 'Modifica',

    'delete_confirm' => 'Eliminare «:name»?',
    'delete_report' => 'Elimina report',
    'cancel' => 'Annulla',
    'delete' => 'Elimina',
    'delete_aria' => 'Elimina :name',

    'col' => [
        'name' => 'Nome',
        'summary' => 'Riepilogo',
        'pinned' => 'Fissato',
        'actions' => 'Azioni',
    ],

    'flash' => [
        'not_found' => "Report non trovato (potrebbe essere stato eliminato in un'altra scheda).",
        'deleted' => 'Report eliminato.',
    ],
    'pin_cap' => 'Puoi fissare fino a 3 report. Rimuovine uno per aggiungere questo.',

    'summary' => [
        'metric' => [
            'spend' => 'Spese',
            'income' => 'Entrate',
            'net' => 'Netto',
            'net_worth' => 'Patrimonio netto',
            'fallback' => 'Importo',
        ],
        'dimension' => [
            'category' => 'categoria',
            'time_bucket' => 'intervallo temporale',
            'counterparty' => 'controparte',
            'account' => 'conto',
            'fallback' => 'categoria',
        ],
        'period' => [
            'this_month' => 'Questo mese',
            'last_3_months' => 'Ultimi 3 mesi',
            'last_6_months' => 'Ultimi 6 mesi',
            'last_12_months' => 'Ultimi 12 mesi',
            'ytd' => 'Da inizio anno',
            'this_year' => "Quest'anno",
            'custom' => 'Intervallo personalizzato',
        ],
        'with_dimension' => ':metric · per :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
