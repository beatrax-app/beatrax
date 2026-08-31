<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Senza categoria',
    'no_counterparty' => 'Nessuna controparte',
    'unavailable_counterparty' => 'Controparte non presente su questo dispositivo',
    'title' => 'Report',
    'page_title' => 'Report · Beatrax',
    'subtitle' => 'Componi un report a partire dal tuo registro.',
    'controls_aria' => 'Controlli del report',
    'result_aria' => 'Risultato del report',
    'dismiss' => 'Ignora',

    'metric' => [
        'heading' => 'Metrica',
        'spend' => 'Spese',
        'income' => 'Entrate',
        'net' => 'Netto',
        'net_worth' => 'Patrimonio netto',
        'fallback' => 'Importo',
    ],

    'group_by' => 'Raggruppa per',

    'dimension' => [
        'category' => 'Categoria',
        'time_bucket' => 'Intervallo temporale',
        'counterparty' => 'Controparte',
        'account' => 'Conto',
    ],

    'period' => [
        'heading' => 'Periodo',
        'this_month' => 'Questo mese',
        'last_3_months' => 'Ultimi 3 mesi',
        'last_6_months' => 'Ultimi 6 mesi',
        'last_12_months' => 'Ultimi 12 mesi',
        'ytd' => 'Da inizio anno',
        'this_year' => "Quest'anno",
        'custom' => 'Intervallo personalizzato',
        'from' => 'Da',
        'to' => 'A',
        'error' => [
            'incomplete' => 'Scegli sia una data di inizio sia una di fine.',
            'malformed' => 'Usa una data valida nel formato AAAA-MM-GG.',
            'inverted' => 'La data di fine precede quella di inizio.',
        ],
    ],

    'currency' => [
        'heading' => 'Valuta',
        'aria' => 'Modalità valuta',
        'base' => 'Base',
        'original' => 'Originale',
    ],

    'granularity' => [
        'heading' => 'Granularità',
        'aria' => 'Granularità temporale',
        'monthly' => 'Mensile',
        'weekly' => 'Settimanale',
    ],

    'filters' => [
        'heading' => 'Filtri',
        'net_worth_note' => 'Il patrimonio netto è un saldo: si applica solo il filtro conto.',
    ],

    'compare' => 'Confronta con il periodo precedente',

    'viz' => [
        'heading' => 'Visualizzazione',
        'table' => 'Tabella',
        'bar' => 'Barre',
        'line' => 'Linee',
        'donut' => 'Anello',
    ],

    'actions' => [
        'update_report' => 'Aggiorna report',
        'save_report' => 'Salva report',
        'report_name' => 'Nome del report',
        'update' => 'Aggiorna',
        'save' => 'Salva',
        'cancel' => 'Annulla',
        'export_csv' => 'Esporta CSV',
    ],

    'updating' => '… Aggiornamento',

    'empty' => [
        'heading' => 'Niente da mostrare per questa selezione',
        'body' => "Prova ad ampliare l'intervallo di date o a rimuovere un filtro.",
    ],

    'total_prefix' => 'Totale',
    'total' => 'Totale',
    'vs_previous' => 'rispetto al periodo precedente',
    'view_transactions' => 'Vedi le transazioni',

    'fx_excluded' => ':count conto non convertito — nessun tasso disponibile|:count conti non convertiti — nessun tasso disponibile',

    'group_header' => [
        'category' => 'Categoria',
        'counterparty' => 'Controparte',
        'account' => 'Conto',
        'month' => 'Mese',
        'default' => 'Gruppo',
    ],

    'chart' => [
        'other_currencies' => 'Grafico in :currency — :list non rappresentato',
        'undrawn' => 'Fuori dall’anello — :amount va nella direzione opposta',
        'bar_title' => 'Fai clic su una barra per vedere le sue transazioni',
        'line_title' => 'Fai clic su un punto per vedere le sue transazioni',
        'donut_title' => 'Fai clic su un segmento per vedere le sue transazioni',
    ],

    'flash' => [
        'saved' => 'Report salvato.',
        'updated' => 'Report aggiornato.',
    ],

    'filter' => [
        'account' => 'Conto',
        'account_count' => ':count conto|:count conti',
        'remove_account' => 'Rimuovi il filtro conto',
        'account_dialog' => 'Filtro conto',

        'category' => 'Categoria',
        'category_count' => ':count categoria|:count categorie',
        'remove_category' => 'Rimuovi il filtro categoria',
        'category_dialog' => 'Filtro categoria',

        'counterparty' => 'Controparte',
        'counterparty_count' => ':count controparte|:count controparti',
        'remove_counterparty' => 'Rimuovi il filtro controparte',
        'counterparty_dialog' => 'Filtro controparte',

        'amount' => 'Importo',
        'remove_amount' => 'Rimuovi il filtro importo',
        'amount_dialog' => 'Filtro importo',
        'dir_both' => 'Entrambi',
        'dir_in' => 'Entrate',
        'dir_out' => 'Uscite',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Importo minimo',
        'max_aria' => 'Importo massimo',
    ],

    'other_movement' => 'Commissioni e rettifiche (non conteggiate sopra)',
    'other_movement_with_refunds' => 'Commissioni, rimborsi e rettifiche (non conteggiate sopra)',
];
