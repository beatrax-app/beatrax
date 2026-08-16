<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Calendario',
        'subtitle' => 'Pagamenti in arrivo e il tuo saldo giornaliero previsto.',
    ],

    'summary' => [
        'computing' => 'Aggiornamento della previsione…',
        'risk' => 'Il saldo scende sotto €0 il :date.|Il saldo scende sotto €0 in :count giorni — il primo: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Mese precedente',
        'next_month' => 'Mese successivo',
        'accounts' => 'Conti',
        'popover_aria' => 'Impostazioni di visualizzazione dei conti',
        'no_accounts' => 'Nessun conto trovato.',
        'col_account' => 'Conto',
        'col_entries' => 'Voci',
        'col_balance' => 'Saldo',
        'show_entries_aria' => 'Mostra le voci di :name',
        'count_balance_aria' => 'Conta :name nel saldo',
    ],

    'empty' => [
        'heading' => 'Nessun pagamento in arrivo',
        'body' => 'Collega un conto o approva una serie ricorrente per vedere i pagamenti previsti nel calendario.',
        'review' => 'Rivedi le ricorrenti →',
    ],

    'weekdays' => [
        'mon' => 'Lun',
        'tue' => 'Mar',
        'wed' => 'Mer',
        'thu' => 'Gio',
        'fri' => 'Ven',
        'sat' => 'Sab',
        'sun' => 'Dom',
    ],

    'grid' => [
        'aria' => 'Calendario :month',
    ],

    'cell' => [
        'entry' => 'voce|voci',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', saldo previsto meno €:amount',
        'aria_balance_positive' => ', saldo previsto €:amount',
        'overflow' => '+:count altre',
        'paid' => 'Pagato',
        'missed' => 'Previsto — non trovato',
    ],

    'panel' => [
        'aria' => 'Pannello dettagli del giorno',
        'close' => 'Chiudi il pannello del giorno',
        'start_of_day' => 'Inizio giornata',
        'no_payments' => 'Nessun pagamento in questo giorno.',
        'date_approximate' => '~ data approssimativa',
        'series' => '↗ serie',
        'counterparty' => '↗ controparte',
        'end_of_day' => 'Fine giornata',
    ],
];
