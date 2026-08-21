<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Kontrola a zápis',
    'h1' => 'Zkontroluj všechno, co jsme našli',

    'lede_across' => 'transakcí z',
    'source' => 'zdroje|zdrojů|zdrojů',
    'lede_confirm' => 'Potvrď počáteční zůstatky a pak vše zapiš.',

    'empty' => 'Zatím není co kontrolovat. Vlož v předchozích krocích výpis z účtu a uvidíš tu své transakce.',

    'sb_eyebrow_label' => '🧮 POČÁTEČNÍ ZŮSTATKY ·',
    'account_detected' => 'NALEZENÝ ÚČET|NALEZENÉ ÚČTY|NALEZENÝCH ÚČTŮ',
    'sb_lede' => 'U každého účtu jsme zjistili počáteční zůstatek. Před zápisem ho potvrď nebo uprav.',

    'txn' => 'transakce|transakce|transakcí',
    'to_commit' => 'k zápisu ·',
    'already_imported' => 'už naimportováno',
    'commit_committing' => 'Zapisuje se…',
    'commit_count' => 'Zapsat vše (:count transakce) →|Zapsat vše (:count transakce) →|Zapsat vše (:count transakcí) →',
    'commit_empty' => 'Zapsat vše (—) →',
    'skip' => 'Zatím přeskočit',

    'errors' => [
        'nothing_to_commit' => 'Není co zapsat.',
        'commit_failed' => 'Tvoje výpisy se nepodařilo zapsat. Nic se nezměnilo — zkus to znovu.',
    ],

    'section' => [
        'from_prefix' => 'Z ',
        'from_bank' => 'Z TVÉHO BANKOVNÍHO VÝPISU',
        'from_ics' => 'Z TVÝCH VÝPISŮ KARTY ICS',
        'from_paypal' => 'Z PAYPAL',
        'row' => 'ŘÁDEK|ŘÁDKY|ŘÁDKŮ',
        'badge_ready' => '✓ PŘIPRAVENO',
        'badge_empty' => 'PRÁZDNÉ',
        'badge_error' => 'NUTNO NAHRÁT ZNOVU',
        'badge_filtered' => 'UŽ NAIMPORTOVÁNO',
        'error_body' => 'Nepodařilo se přečíst všechny soubory z tohoto zdroje. Zkus jiný soubor →',
        'partial_body' => 'Část tohoto souboru se nepodařilo načíst a byla vynechána: :reason',
        'empty_body' => 'Tenhle výpis je prázdný.',
        'filtered_body' => 'Tenhle výpis už byl naimportován jinde — vynechali jsme ho.',
        'col_date' => 'Datum',
        'col_type' => 'Typ',
        'col_counterparty' => 'Protistrana',
        'col_amount' => 'Částka',
        'load_more' => 'Načíst další (zbývá :remaining)',
        'rows_shown' => ':count zobrazený řádek|:count zobrazené řádky|:count zobrazených řádků',
    ],
];
