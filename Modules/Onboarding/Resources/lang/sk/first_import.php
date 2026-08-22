<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Kontrola a potvrdenie',
    'h1' => 'Skontroluj všetko, čo sme našli',

    'lede_counts' => ':transactions z :sources.',
    'source' => ':count zdroja|:count zdrojov|:count zdrojov',
    'lede_confirm' => 'Skontroluj počiatočné zostatky a potom všetko potvrď.',

    'empty' => 'Zatiaľ nie je čo kontrolovať. Vlož výpis v predchádzajúcich krokoch a transakcie sa zobrazia tu.',

    'sb_eyebrow_label' => '🧮 POČIATOČNÉ ZOSTATKY ·',
    'account_detected' => ':count ZISTENÝ ÚČET|:count ZISTENÉ ÚČTY|:count ZISTENÝCH ÚČTOV',
    'sb_lede' => 'Pre každý účet sme zistili počiatočný zostatok. Pred potvrdením ho skontroluj alebo uprav.',

    'txn' => ':count transakcia|:count transakcie|:count transakcií',
    'to_commit' => 'na potvrdenie ·',
    'already_imported' => ':count už importovaná|:count už importované|:count už importovaných',
    'commit_committing' => 'Potvrdzuje sa…',
    'commit_count' => 'Potvrdiť všetko (:count transakcia) →|Potvrdiť všetko (:count transakcie) →|Potvrdiť všetko (:count transakcií) →',
    'commit_empty' => 'Potvrdiť všetko (—) →',
    'skip' => 'Zatiaľ preskočiť',

    'errors' => [
        'nothing_to_commit' => 'Niet čo potvrdiť.',
        'commit_failed' => 'Tvoje výpisy sa nepodarilo potvrdiť. Nič sa nezmenilo — skús to znova.',
    ],

    'section' => [
        'from_prefix' => 'ZDROJ: ',
        'from_bank' => 'ZDROJ: TVOJ BANKOVÝ VÝPIS',
        'from_ics' => 'ZDROJ: TVOJE VÝPISY KARTY ICS',
        'from_paypal' => 'ZDROJ: PAYPAL',
        'row' => ':count RIADOK|:count RIADKY|:count RIADKOV',
        'badge_ready' => '✓ PRIPRAVENÉ',
        'badge_empty' => 'PRÁZDNE',
        'badge_error' => 'TREBA NAHRAŤ ZNOVA',
        'error_body' => 'Nepodarilo sa nám prečítať všetky súbory z tohto zdroja. Skús iný súbor →',
        'partial_body' => 'Časť tohto súboru sa nepodarilo načítať a bola vynechaná: :reason',
        'empty_body' => 'Tento výpis je prázdny.',
        'col_date' => 'Dátum',
        'col_type' => 'Typ',
        'col_counterparty' => 'Protistrana',
        'col_amount' => 'Suma',
        'load_more' => 'Načítať ďalšie (zostáva :remaining)',
        'rows_shown' => ':count zobrazený riadok|:count zobrazené riadky|:count zobrazených riadkov',
    ],
];
