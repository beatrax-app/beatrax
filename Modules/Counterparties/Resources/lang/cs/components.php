<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Typ protistrany: :type',
        'merchant' => 'Obchodník',
        'personal' => 'Soukromá osoba',
        'bank' => 'Banka',
        'government' => 'Úřad',
        'self' => 'Vlastní',
        'unknown' => 'Neznámá',
    ],

    'filter_chips' => [
        'aria' => 'Filtrovat podle typu',
        'all' => 'Vše',
        'merchant' => 'Obchodníci',
        'personal' => 'Soukromé osoby',
        'bank' => 'Banky',
        'government' => 'Úřady',
        'self' => 'Vlastní',
        'unknown' => 'Neznámé',
    ],

    'default_name' => [
        'bank_fee' => 'Bankovní poplatek',
        'account_maintenance' => 'Poplatek za vedení účtu',
        'monthly_fee' => 'Měsíční poplatek',
        'quarterly_fee' => 'Čtvrtletní poplatek',
        'annual_fee' => 'Roční poplatek',
        'card_fee' => 'Poplatek za kartu',
        'transaction_fee' => 'Poplatek za transakci',
        'transfer_fee' => 'Poplatek za převod',
        'withdrawal_fee' => 'Poplatek za výběr',
        'transaction_levy' => 'Daň z transakcí',
        'foreign_transaction_fee' => 'Poplatek za směnu měny',
        'commission' => 'Provize',
        'debit_interest' => 'Debetní úrok',
        'overdraft' => 'Poplatek za kontokorent',
        'overdraft_interest' => 'Úrok z kontokorentu',
        'insufficient_funds' => 'Poplatek za nedostatek prostředků',
        'penalty_fee' => 'Sankční poplatek',
        'loan_arrangement_fee' => 'Poplatek za poskytnutí úvěru',
    ],

    'cp_card' => [
        'aria' => 'Protistrana: :name',
        'recent_aria' => 'Nedávná aktivita',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Řetězec financování: ',
        'join' => ' do ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN je skrytý — zobrazíš ho tlačítkem Zobrazit IBAN',
        // i18n-review: cs · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN je skrytý — zobrazíš ho tlačítkem Zobrazit IBAN',
        'show' => 'Zobrazit IBAN',
        'hide' => 'Skrýt IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Upozornění na soukromí u osobního kontaktu',
        'body' => '🔒 Toto je osobní kontakt. IBAN a osobní údaje jsou ve výchozím nastavení skryté a nikdy se nesdílejí v exportech.',
    ],

    'self_stub' => [
        'aria' => 'Není to skutečná protistrana',
        'heading' => 'Tohle vlastně není protistrana',

        'body_rest_html' => ' se tu objevuje, protože v tvých transakcích vystupuje jako úsek financování mezi účty. Je to ale <strong>tvůj vlastní účet</strong>, ne někdo, s kým obchoduješ.',
        'body2' => 'Otevři pohled na účet, kde najdeš zůstatek, výpisy z účtu a celou historii transakcí.',
        'open_cta' => 'Otevřít pohled na účet: :name →',
        'hide_cta' => 'Skrýt z tohoto seznamu',
        'recent_legs' => 'Nedávné úseky mezi účty',
    ],
];
