<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Tipo di controparte: :type',
        'merchant' => 'Esercente',
        'personal' => 'Personale',
        'bank' => 'Banca',
        'government' => 'Ente pubblico',
        'self' => 'Propria',
        'unknown' => 'Sconosciuta',
    ],

    'filter_chips' => [
        'aria' => 'Filtra per tipo',
        'all' => 'Tutte',
        'merchant' => 'Esercenti',
        'personal' => 'Personali',
        'bank' => 'Banche',
        'government' => 'Enti pubblici',
        'self' => 'Proprie',
        'unknown' => 'Sconosciute',
    ],

    'default_name' => [
        'bank_fee' => 'Commissione bancaria',
        'account_maintenance' => 'Spese di tenuta conto',
        'monthly_fee' => 'Canone mensile',
        'quarterly_fee' => 'Spese trimestrali',
        'annual_fee' => 'Canone annuo',
        'card_fee' => 'Canone carta',
        'transaction_fee' => 'Spese per operazione',
        'transfer_fee' => 'Spese di bonifico',
        'withdrawal_fee' => 'Spese di prelievo',
        'transaction_levy' => 'Imposta sulle transazioni',
        'foreign_transaction_fee' => 'Commissione di cambio',
        'commission' => 'Commissioni',
        'debit_interest' => 'Interessi debitori',
        'overdraft' => 'Spese di scoperto',
        'overdraft_interest' => 'Interessi di scoperto',
        'insufficient_funds' => 'Spese per insoluto',
        'penalty_fee' => 'Penale',
        'loan_arrangement_fee' => 'Spese di istruttoria',
    ],

    'cp_card' => [
        'aria' => 'Controparte: :name',
        'recent_aria' => 'Attività recente',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Catena di finanziamento: ',
        'join' => ' a ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN nascosto — fai clic su Mostra IBAN per vederlo',
        // i18n-review: it · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN nascosto — tocca su Mostra IBAN per vederlo',
        'show' => 'Mostra IBAN',
        'hide' => 'Nascondi IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Avviso di privacy per i contatti personali',
        'body' => "🔒 Questo è un contatto personale. L'IBAN resta nascosto finché non lo mostri, e non entra nelle esportazioni. Il suo nome compare comunque ovunque compaiano le sue transazioni.",
    ],

    'self_stub' => [
        'aria' => 'Non è una controparte reale',
        'heading' => 'Questa non è davvero una controparte',

        'body_rest_html' => ' compare qui perché nelle tue transazioni figura come passaggio di finanziamento tra conti. Ma è <strong>il tuo conto</strong>, non qualcuno con cui fai operazioni.',
        'body2' => 'Apri la vista del conto per saldo, estratti conto e cronologia completa delle transazioni.',
        'open_cta' => 'Apri la vista del conto :name →',
        'hide_cta' => 'Nascondi da questo elenco',
        'recent_legs' => 'Passaggi recenti tra conti',
    ],
];
