<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Luokittelematon',
    'title' => 'Raportit',
    'page_title' => 'Raportit · Beatrax',
    'subtitle' => 'Kokoa raportti tilikirjastasi.',
    'controls_aria' => 'Raportin säätimet',
    'result_aria' => 'Raportin tulos',
    'dismiss' => 'Ohita',

    'metric' => [
        'heading' => 'Mittari',
        'spend' => 'Kulutus',
        'income' => 'Tulot',
        'net' => 'Netto',
        'net_worth' => 'Nettovarallisuus',
        'fallback' => 'Summa',
    ],

    'group_by' => 'Ryhmittele',

    'dimension' => [
        'category' => 'Kategoria',
        'time_bucket' => 'Aikajakso',
        'counterparty' => 'Vastapuoli',
        'account' => 'Tili',
    ],

    'period' => [
        'heading' => 'Jakso',
        'this_month' => 'Tämä kuukausi',
        'last_3_months' => 'Viimeiset 3 kuukautta',
        'last_6_months' => 'Viimeiset 6 kuukautta',
        'last_12_months' => 'Viimeiset 12 kuukautta',
        'ytd' => 'Vuoden alusta',
        'this_year' => 'Tämä vuosi',
        'custom' => 'Mukautettu aikaväli',
        'from' => 'Alkaen',
        'to' => 'Asti',
        'error' => [
            'incomplete' => 'Valitse sekä alku- että loppupäivä.',
            'malformed' => 'Käytä kelvollista päivämäärää muodossa VVVV-KK-PP.',
            'inverted' => 'Loppupäivä on ennen alkupäivää.',
        ],
    ],

    'currency' => [
        'heading' => 'Valuutta',
        'aria' => 'Valuuttatila',
        'base' => 'Perusvaluutta',
        'original' => 'Alkuperäinen',
    ],

    'granularity' => [
        'heading' => 'Tarkkuus',
        'aria' => 'Ajan tarkkuus',
        'monthly' => 'Kuukausittain',
        'weekly' => 'Viikoittain',
    ],

    'filters' => [
        'heading' => 'Suodattimet',
        'net_worth_note' => 'Nettovarallisuus on saldo: vain tilisuodatin vaikuttaa.',
    ],

    'compare' => 'Vertaa edelliseen jaksoon',

    'viz' => [
        'heading' => 'Visualisointi',
        'table' => 'Taulukko',
        'bar' => 'Pylväs',
        'line' => 'Viiva',
        'donut' => 'Rengas',
    ],

    'actions' => [
        'update_report' => 'Päivitä raportti',
        'save_report' => 'Tallenna raportti',
        'report_name' => 'Raportin nimi',
        'update' => 'Päivitä',
        'save' => 'Tallenna',
        'cancel' => 'Peruuta',
        'export_csv' => 'Vie CSV',
    ],

    'updating' => '… Päivitetään',

    'empty' => [
        'heading' => 'Ei näytettävää tällä valinnalla',
        'body' => 'Kokeile laajentaa aikaväliä tai poistaa suodatin.',
    ],

    'total_prefix' => 'Yhteensä',
    'total' => 'Yhteensä',
    'vs_previous' => 'vs. edellinen jakso',
    'view_transactions' => 'Näytä tapahtumat',

    'fx_excluded' => ':count tili jäi muuntamatta — kurssia ei saatavilla|:count tiliä jäi muuntamatta — kurssia ei saatavilla',

    'group_header' => [
        'category' => 'Kategoria',
        'counterparty' => 'Vastapuoli',
        'account' => 'Tili',
        'month' => 'Kuukausi',
        'default' => 'Ryhmä',
    ],

    'chart' => [
        'bar_title' => 'Napsauta pylvästä, niin näet sen tapahtumat',
        'line_title' => 'Napsauta pistettä, niin näet sen tapahtumat',
        'donut_title' => 'Napsauta segmenttiä, niin näet sen tapahtumat',
    ],

    'flash' => [
        'saved' => 'Raportti tallennettu.',
        'updated' => 'Raportti päivitetty.',
    ],

    'filter' => [
        'account' => 'Tili',
        'account_count' => ':count tili|:count tiliä',
        'remove_account' => 'Poista tilisuodatin',
        'account_dialog' => 'Tilisuodatin',

        'category' => 'Kategoria',
        'category_count' => ':count kategoria|:count kategoriaa',
        'remove_category' => 'Poista kategoriasuodatin',
        'category_dialog' => 'Kategoriasuodatin',

        'counterparty' => 'Vastapuoli',
        'counterparty_count' => ':count vastapuoli|:count vastapuolta',
        'remove_counterparty' => 'Poista vastapuolisuodatin',
        'counterparty_dialog' => 'Vastapuolisuodatin',

        'amount' => 'Summa',
        'remove_amount' => 'Poista summasuodatin',
        'amount_dialog' => 'Summasuodatin',
        'dir_both' => 'Molemmat',
        'dir_in' => 'Sisään',
        'dir_out' => 'Ulos',
        'min' => 'Vähintään',
        'max' => 'Enintään',
        'min_aria' => 'Vähimmäissumma',
        'max_aria' => 'Enimmäissumma',
    ],

    'other_movement' => 'Palkkiot ja oikaisut (ei laskettu mukaan)',
    'other_movement_with_refunds' => 'Palkkiot, hyvitykset ja oikaisut (ei laskettu mukaan)',
];
