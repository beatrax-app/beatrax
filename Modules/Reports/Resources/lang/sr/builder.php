<?php

declare(strict_types=1);

return [
    'title' => 'Izveštaji',
    'page_title' => 'Izveštaji · Beatrax',
    'subtitle' => 'Sastavi izveštaj iz svoje glavne knjige.',
    'controls_aria' => 'Podešavanja izveštaja',
    'result_aria' => 'Rezultat izveštaja',
    'dismiss' => 'Odbaci',

    'metric' => [
        'heading' => 'Mera',
        'spend' => 'Potrošnja',
        'income' => 'Prihodi',
        'net' => 'Neto',
        'net_worth' => 'Neto vrednost',
        'fallback' => 'Iznos',
    ],

    'group_by' => 'Grupiši po',

    'dimension' => [
        'category' => 'Kategorija',
        'time_bucket' => 'Vremenski interval',
        'counterparty' => 'Druga strana',
        'account' => 'Račun',
    ],

    'period' => [
        'heading' => 'Period',
        'this_month' => 'Ovaj mesec',
        'last_3_months' => 'Poslednja 3 meseca',
        'last_6_months' => 'Poslednjih 6 meseci',
        'last_12_months' => 'Poslednjih 12 meseci',
        'ytd' => 'Od početka godine',
        'this_year' => 'Ova godina',
        'custom' => 'Prilagođeni opseg',
        'from' => 'Od',
        'to' => 'Do',
    ],

    'currency' => [
        'heading' => 'Valuta',
        'aria' => 'Način prikaza valute',
        'base' => 'Osnovna',
        'original' => 'Originalna',
    ],

    'granularity' => [
        'heading' => 'Nivo detalja',
        'aria' => 'Vremenski nivo detalja',
        'monthly' => 'Mesečno',
        'weekly' => 'Nedeljno',
    ],

    'filters' => [
        'heading' => 'Filteri',
    ],

    'compare' => 'Uporedi sa prethodnim periodom',

    'viz' => [
        'heading' => 'Vizuelizacija',
        'table' => 'Tabela',
        'bar' => 'Stubičasti',
        'line' => 'Linijski',
        'donut' => 'Prstenasti',
    ],

    'actions' => [
        'update_report' => 'Ažuriraj izveštaj',
        'save_report' => 'Sačuvaj izveštaj',
        'report_name' => 'Naziv izveštaja',
        'update' => 'Ažuriraj',
        'save' => 'Sačuvaj',
        'cancel' => 'Otkaži',
        'export_csv' => 'Izvezi CSV',
    ],

    'updating' => '… Ažuriranje',

    'empty' => [
        'heading' => 'Nema šta da se prikaže za ovaj izbor',
        'body' => 'Pokušaj da proširiš opseg datuma ili da ukloniš neki filter.',
    ],

    'total_prefix' => 'Ukupno',
    'total' => 'Ukupno',
    'vs_previous' => 'u odnosu na prethodni period',
    'view_transactions' => 'Prikaži transakcije',

    'fx_excluded' => ':count račun nije preračunat — nema dostupnog kursa|:count računa nisu preračunata — nema dostupnog kursa|:count računa nije preračunato — nema dostupnog kursa',

    'group_header' => [
        'category' => 'Kategorija',
        'counterparty' => 'Druga strana',
        'account' => 'Račun',
        'month' => 'Mesec',
        'default' => 'Grupa',
    ],

    'chart' => [
        'bar_title' => 'Klikni stubić za prikaz njegovih transakcija',
        'line_title' => 'Klikni tačku za prikaz njenih transakcija',
        'donut_title' => 'Klikni segment za prikaz njegovih transakcija',
    ],

    'flash' => [
        'saved' => 'Izveštaj je sačuvan.',
        'updated' => 'Izveštaj je ažuriran.',
    ],

    'filter' => [
        'account' => 'Račun',
        'account_count' => ':count račun|:count računa|:count računa',
        'remove_account' => 'Ukloni filter računa',
        'account_dialog' => 'Filter računa',

        'category' => 'Kategorija',
        'category_count' => ':count kategorija|:count kategorije|:count kategorija',
        'remove_category' => 'Ukloni filter kategorije',
        'category_dialog' => 'Filter kategorije',

        'counterparty' => 'Druga strana',
        'counterparty_count' => ':count druga strana|:count druge strane|:count drugih strana',
        'remove_counterparty' => 'Ukloni filter druge strane',
        'counterparty_dialog' => 'Filter druge strane',

        'amount' => 'Iznos',
        'remove_amount' => 'Ukloni filter iznosa',
        'amount_dialog' => 'Filter iznosa',
        'dir_both' => 'Oba',
        'dir_in' => 'Priliv',
        'dir_out' => 'Odliv',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Najmanji iznos',
        'max_aria' => 'Najveći iznos',
    ],
];
