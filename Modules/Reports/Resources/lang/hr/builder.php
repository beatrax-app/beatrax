<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Bez kategorije',
    'no_counterparty' => 'Nema protustranke',
    'unavailable_counterparty' => 'Protustranke nema na ovom uređaju',
    'title' => 'Izvješća',
    'page_title' => 'Izvješća · Beatrax',
    'subtitle' => 'Sastavi izvješće iz svoje glavne knjige.',
    'controls_aria' => 'Postavke izvješća',
    'result_aria' => 'Rezultat izvješća',
    'dismiss' => 'Odbaci',

    'metric' => [
        'heading' => 'Mjera',
        'spend' => 'Potrošnja',
        'income' => 'Prihodi',
        'net' => 'Neto',
        'net_worth' => 'Neto vrijednost',
        'fallback' => 'Iznos',
    ],

    'group_by' => 'Grupiraj po',

    'dimension' => [
        'category' => 'Kategorija',
        'time_bucket' => 'Vremenski interval',
        'counterparty' => 'Protustranka',
        'account' => 'Račun',
    ],

    'period' => [
        'heading' => 'Razdoblje',
        'this_month' => 'Ovaj mjesec',
        'last_3_months' => 'Zadnja 3 mjeseca',
        'last_6_months' => 'Zadnjih 6 mjeseci',
        'last_12_months' => 'Zadnjih 12 mjeseci',
        'ytd' => 'Od početka godine',
        'this_year' => 'Ova godina',
        'custom' => 'Prilagođeni raspon',
        'from' => 'Od',
        'to' => 'Do',
        'error' => [
            'incomplete' => 'Odaberi i početni i završni datum.',
            'malformed' => 'Upiši ispravan datum u obliku GGGG-MM-DD.',
            'inverted' => 'Završni datum je prije početnog.',
        ],
    ],

    'currency' => [
        'heading' => 'Valuta',
        'aria' => 'Način prikaza valute',
        'base' => 'Osnovna',
        'original' => 'Izvorna',
    ],

    'granularity' => [
        'heading' => 'Razina detalja',
        'aria' => 'Vremenska razina detalja',
        'monthly' => 'Mjesečno',
        'weekly' => 'Tjedno',
    ],

    'filters' => [
        'heading' => 'Filtri',
        'net_worth_note' => 'Neto vrijednost je stanje: primjenjuje se samo filtar računa.',
    ],

    'compare' => 'Usporedi s prethodnim razdobljem',

    'viz' => [
        'heading' => 'Vizualizacija',
        'table' => 'Tablica',
        'bar' => 'Stupčasti',
        'line' => 'Linijski',
        'donut' => 'Prstenasti',
    ],

    'actions' => [
        'update_report' => 'Ažuriraj izvješće',
        'save_report' => 'Spremi izvješće',
        'report_name' => 'Naziv izvješća',
        'update' => 'Ažuriraj',
        'save' => 'Spremi',
        'cancel' => 'Odustani',
        'export_csv' => 'Izvezi CSV',
    ],

    'updating' => '… Ažuriranje',

    'empty' => [
        'heading' => 'Nema ništa za prikaz za ovaj odabir',
        'body' => 'Pokušaj proširiti raspon datuma ili ukloniti neki filtar.',
    ],

    'total_prefix' => 'Ukupno',
    'total' => 'Ukupno',
    'vs_previous' => 'u odnosu na prethodno razdoblje',
    'view_transactions' => 'Prikaži transakcije',

    'fx_excluded' => ':count račun nije preračunat — nema dostupnog tečaja|:count računa nisu preračunata — nema dostupnog tečaja|:count računa nije preračunato — nema dostupnog tečaja',

    'group_header' => [
        'category' => 'Kategorija',
        'counterparty' => 'Protustranka',
        'account' => 'Račun',
        'month' => 'Mjesec',
        'default' => 'Grupa',
    ],

    'chart' => [
        'other_currencies' => 'Grafikon u valuti :currency — :list nije prikazano',
        'undrawn' => 'Nije u prstenu — :amount ide u suprotnom smjeru',
        'bar_title' => 'Klikni stupac za prikaz njegovih transakcija',
        'line_title' => 'Klikni točku za prikaz njezinih transakcija',
        'donut_title' => 'Klikni segment za prikaz njegovih transakcija',
    ],

    'flash' => [
        'saved' => 'Izvješće je spremljeno.',
        'updated' => 'Izvješće je ažurirano.',
    ],

    'filter' => [
        'account' => 'Račun',
        'account_count' => ':count račun|:count računa|:count računa',
        'remove_account' => 'Ukloni filtar računa',
        'account_dialog' => 'Filtar računa',

        'category' => 'Kategorija',
        'category_count' => ':count kategorija|:count kategorije|:count kategorija',
        'remove_category' => 'Ukloni filtar kategorije',
        'category_dialog' => 'Filtar kategorije',

        'counterparty' => 'Protustranka',
        'counterparty_count' => ':count protustranka|:count protustranke|:count protustranaka',
        'remove_counterparty' => 'Ukloni filtar protustranke',
        'counterparty_dialog' => 'Filtar protustranke',

        'amount' => 'Iznos',
        'remove_amount' => 'Ukloni filtar iznosa',
        'amount_dialog' => 'Filtar iznosa',
        'dir_both' => 'Oboje',
        'dir_in' => 'Priljev',
        'dir_out' => 'Odljev',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Najmanji iznos',
        'max_aria' => 'Najveći iznos',
    ],

    'other_movement' => 'Naknade i usklađenja (nisu uračunata)',
    'other_movement_with_refunds' => 'Naknade, povrati i usklađenja (nisu uračunata)',
];
