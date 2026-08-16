<?php

declare(strict_types=1);

return [
    'page_title' => 'Transazioni',
    'heading' => 'Transazioni',

    'subtitle_searching' => 'Ricerca in tutta la cronologia',
    'subtitle_full' => 'Cronologia completa.',
    'subtitle_recent' => 'Transazioni recenti (ultimi 90 giorni).',

    'currency_aria' => 'Vista valuta',
    'currency_eur' => 'Solo EUR',
    'currency_original' => 'Valuta originale',

    'show_recent' => 'Mostra solo le recenti',
    'show_full' => 'Mostra tutta la cronologia',

    'empty_period' => "Non c'è nulla per questo periodo.",

    'loading_more' => 'Caricamento di altre transazioni',
    'load_more' => 'Carica altre',

    'split_badge' => 'Suddivisa · :count',
    'split_expand_aria' => 'Suddivisa in :count categorie — espandi per vedere',

    'chain_badge' => 'catena',
    'chain_title' => 'Fa parte di una catena — apri questa riga per vedere',

    'table' => [
        'date' => 'Data',
        'counterparty' => 'Controparte',
        'category' => 'Categoria',
        'tax' => 'Fisco',
        'status' => 'Stato',
        'amount' => 'Importo',
    ],

    'search' => [
        'placeholder' => 'Cerca esercente, descrizione, note…',
        'placeholder_short' => 'Cerca transazioni…',
        'aria' => 'Cerca transazioni',
        'clear_all' => 'Cancella tutto',
        'filters' => 'Filtri',
        'open_filters_aria' => 'Apri i filtri',
        'apply' => 'Applica',
        'clear' => 'Cancella',

        'count' => ':count transazione|:count transazioni',
        'matching_suffix' => 'corrispondono ai filtri',
        'flow' => ':out in uscita / :in in entrata',
    ],

    'no_results' => [
        'heading' => 'Nessuna corrispondenza',
        'remove_prompt' => 'Prova a rimuovere un filtro che potrebbe restringere i risultati:',
        'no_match_query' => 'Nessuna transazione corrisponde a «:query» in tutta la cronologia.',
        'no_match_filters' => 'Nessuna transazione corrisponde ai filtri applicati.',
        'did_you_mean' => 'Forse cercavi:',
        'account_fallback' => 'Conto :id',
        'category_fallback' => 'Categoria :id',
    ],

    'filter' => [
        'date' => 'Data',
        'account' => 'Conto',
        'amount' => 'Importo',
        'category' => 'Categoria',
        'date_range' => 'Intervallo di date',
        'from' => 'Dal',
        'to' => 'Al',
        'custom_range' => 'Intervallo personalizzato ×',
        'after' => 'Dopo il :date ×',
        'before' => 'Prima del :date ×',
        'dir_both' => 'Entrambi',
        'dir_in' => 'Entrate',
        'dir_out' => 'Uscite',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Importo minimo',
        'max_aria' => 'Importo massimo',
        'after_aria' => 'Dopo la data',
        'before_aria' => 'Prima della data',
        'acct' => ':count conto|:count conti',
        'cat' => ':count categoria|:count categorie',
        'date_dialog' => 'Filtro data',
        'account_dialog' => 'Filtro conto',
        'amount_dialog' => 'Filtro importo',
        'category_dialog' => 'Filtro categoria',
        'remove_date_aria' => 'Rimuovi il filtro data',
        'remove_account_aria' => 'Rimuovi il filtro conto',
        'remove_amount_aria' => 'Rimuovi il filtro importo',
        'remove_category_aria' => 'Rimuovi il filtro categoria',

        'remove_named_aria' => 'Rimuovi il filtro :name',
    ],

    'date_preset' => [
        'this_month' => 'Questo mese',
        'last_month' => 'Il mese scorso',
        'this_year' => "Quest'anno",
        'last_year' => "L'anno scorso",
    ],
];
