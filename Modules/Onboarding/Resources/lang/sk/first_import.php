<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Kontrola a potvrdenie',
    'h1' => 'Skontroluj všetko, čo sme našli',

    'lede_across' => 'transakcií z',
    'source' => 'zdroja|zdrojov|zdrojov',
    'lede_confirm' => 'Skontroluj počiatočné zostatky a potom všetko potvrď.',

    'empty' => 'Zatiaľ nie je čo kontrolovať. Vlož výpis v predchádzajúcich krokoch a transakcie sa zobrazia tu.',

    'sb_eyebrow_label' => '🧮 POČIATOČNÉ ZOSTATKY ·',
    'account_detected' => 'ZISTENÝ ÚČET|ZISTENÉ ÚČTY|ZISTENÝCH ÚČTOV',
    'sb_lede' => 'Pre každý účet sme zistili počiatočný zostatok. Pred potvrdením ho skontroluj alebo uprav.',

    'txn' => 'transakcia|transakcie|transakcií',
    'to_commit' => 'na potvrdenie ·',
    'already_imported' => 'už importovaných',
    'commit_committing' => 'Potvrdzuje sa…',
    'commit_count' => 'Potvrdiť všetko (transakcie: :count) →',
    'commit_empty' => 'Potvrdiť všetko (—) →',

    'errors' => [
        'nothing_to_commit' => 'Niet čo potvrdiť.',
        'commit_failed' => 'Tvoje výpisy sa nepodarilo potvrdiť. Nič sa nezmenilo — skús to znova.',
    ],

    'section' => [
        'from_prefix' => 'ZDROJ: ',
        'from_bank' => 'ZDROJ: TVOJ BANKOVÝ VÝPIS',
        'from_ics' => 'ZDROJ: TVOJE VÝPISY KARTY ICS',
        'from_paypal' => 'ZDROJ: PAYPAL',
        'row' => 'RIADOK|RIADKY|RIADKOV',
        'badge_ready' => '✓ PRIPRAVENÉ',
        'badge_empty' => 'PRÁZDNE',
        'badge_error' => 'TREBA NAHRAŤ ZNOVA',
        'badge_filtered' => 'UŽ IMPORTOVANÉ',
        'error_body' => 'Nepodarilo sa nám prečítať všetky súbory z tohto zdroja. Skús iný súbor →',
        'empty_body' => 'Tento výpis je prázdny.',
        'filtered_body' => 'Tento výpis už bol importovaný inde — vynechali sme ho.',
        'col_date' => 'Dátum',
        'col_type' => 'Typ',
        'col_counterparty' => 'Protistrana',
        'col_amount' => 'Suma',
        'load_more' => 'Načítať ďalšie (zostáva :remaining)',
        'rows_shown' => 'Zobrazené riadky: :count',
    ],
];
