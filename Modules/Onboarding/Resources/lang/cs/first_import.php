<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Kontrola a zápis',
    'h1' => 'Zkontroluj všechno, co jsme našli',

    'lede_counts' => ':transactions z :sources.',
    'source' => ':count zdroje|:count zdrojů|:count zdrojů',
    'lede_confirm' => 'Potvrď počáteční zůstatky a pak vše zapiš.',

    'empty' => 'Zatím není co kontrolovat. Vlož v předchozích krocích výpis z účtu a uvidíš tu své transakce.',

    'sb_eyebrow_label' => '🧮 POČÁTEČNÍ ZŮSTATKY ·',
    'account_detected' => ':count NALEZENÝ ÚČET|:count NALEZENÉ ÚČTY|:count NALEZENÝCH ÚČTŮ',
    'sb_lede' => 'U každého účtu jsme zjistili počáteční zůstatek. Před zápisem ho potvrď nebo uprav.',

    'txn' => ':count transakce|:count transakce|:count transakcí',
    'to_commit' => 'k zápisu ·',
    'already_imported' => ':count už naimportováno|:count už naimportováno|:count už naimportováno',
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
        'row' => ':count ŘÁDEK|:count ŘÁDKY|:count ŘÁDKŮ',
        'badge_ready' => '✓ PŘIPRAVENO',
        'badge_empty' => 'PRÁZDNÉ',
        'badge_error' => 'NUTNO NAHRÁT ZNOVU',
        'error_body' => 'Nepodařilo se přečíst všechny soubory z tohoto zdroje. Zkus jiný soubor →',
        'left_out' => 'Jeden soubor tady byl vynechán, uloží se proto jen zbytek: :reason|:count soubory tady byly vynechány, uloží se proto jen zbytek: :reason|:count souborů tady bylo vynecháno, uloží se proto jen zbytek: :reason',
        'rows_skipped' => 'Některé řádky se tady nepodařilo načíst a budou přeskočeny: :reason',
        'empty_body' => 'Tenhle výpis je prázdný.',
        'col_date' => 'Datum',
        'col_type' => 'Typ',
        'col_counterparty' => 'Protistrana',
        'col_amount' => 'Částka',
        'load_more' => 'Načíst další (zbývá :remaining)',
        'rows_shown' => ':count zobrazený řádek|:count zobrazené řádky|:count zobrazených řádků',
    ],
];
