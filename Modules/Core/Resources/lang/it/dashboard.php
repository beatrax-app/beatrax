<?php

declare(strict_types=1);

return [
    'page_title' => 'Dashboard',
    'subtitle' => 'Questo periodo in sintesi.',

    'previous_period' => 'Periodo precedente',
    'today' => 'Oggi',
    'next_period' => 'Periodo successivo',

    'totals_aria' => 'Totali di questo periodo',
    'totals_aria_currency' => 'Totali di questo periodo — :currency',
    'in' => 'Entrate',
    'out' => 'Uscite',
    'net' => 'Netto',

    'status_tiles_aria' => 'Riquadri di stato',
    'email_scan_health' => 'Stato della scansione email — :count collegate',
    'inbox' => 'casella|caselle',

    'top_spending' => 'Spese principali',
    'no_expenses' => 'Ancora nessuna spesa categorizzata.',

    'recent_transactions' => 'Transazioni recenti',
    'view_all' => 'Vedi tutto',
    'nothing_period' => 'Niente da mostrare per questo periodo.',
    'th_date' => 'Data',
    'th_counterparty' => 'Controparte',
    'th_category' => 'Categoria',
    'th_amount' => 'Importo',
    'uncategorized' => 'Senza categoria',

    'reauth' => [
        'title' => 'Una casella deve essere ricollegata.',
        'body' => 'Una o più caselle sono state disconnesse — Beatrax non può analizzarle finché non le ricolleghi.',
        'link' => 'Vai alle caselle',
        'dismiss' => 'Ignora',
    ],

    'failed_chain' => [
        'title' => 'Risoluzione delle catene non riuscita.',
        'body' => 'Uno o più job di risoluzione delle catene hanno riscontrato un errore.',
        'link' => "Apri l'Ispettore della coda",
    ],
];
