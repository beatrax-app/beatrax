<?php

declare(strict_types=1);

return [
    'heading' => 'Ennuste',
    'page_title' => 'Ennuste',
    'subtitle' => 'Mihin saldosi on menossa — seuraavien 30–365 päivän aikana.',
    'adjust_buffers' => 'Säädä puskureita',

    'empty_heading' => 'Ei vielä ennustetietoja',
    'empty_body' => 'Yhdistä tili tai hyväksy toistuva sarja, niin näet ennustetun saldosi tulevilta viikoilta.',
    'empty_start' => 'Aloita',
    'empty_import_link' => 'tuomalla tiliote',
    'empty_or' => 'tai',
    'empty_recurring_link' => 'tarkistamalla toistuvat kaavat',

    'account_tablist' => 'Tili',
    'all_accounts' => 'Kaikki tilit',

    'horizon_label' => 'Ennusteen aikajänne',
    'n_days' => ':days päivä|:days päivää',

    'view_by_funder' => 'Näytä rahoittajan mukaan',
    'view_by_funder_hint' => 'Kokoa ketjuratkaistut sarjat sille tilille, joka ne todella maksaa.',

    'scenario_group' => 'Skenaario',
    'baseline' => 'Perustaso',
    'scenario_word' => 'Skenaario',
    'new_scenario' => '+ Uusi skenaario',
    'scenario_name_placeholder' => 'Skenaarion nimi',
    'new_scenario_aria' => 'Uuden skenaarion nimi',
    'create_scenario' => 'Luo skenaario',
    'cancel' => 'Peruuta',

    'aggregate_subtitle' => 'Kaikkien tilien yhteenlaskettu saldo ennustettuna seuraavan :days päivän ajalle.|Kaikkien tilien yhteenlaskettu saldo ennustettuna seuraavien :days päivän ajalle.',

    'today' => 'tänään',
    'on_day' => 'päivänä',

    'edit_buffer_aria' => 'Muokkaa tilin :name vähimmäispuskuria',
    'buffer_not_set' => 'Puskuri: ei asetettu',
    'buffer_set' => 'Puskuri: :amount',

    'shortfall' => 'Vaje alkaa :date — :amount alle :buffer puskurisi',

    'compared_against_baseline' => 'Verrattuna yllä olevaan perustasoon',

    'scenario_editor_aria' => 'Skenaarioeditori',
    'series_confidence' => 'Sarjan varmuus',
    'no_series_contribute' => 'Mikään sarja ei vielä vaikuta tämän tilin ennusteeseen.',

    'net_diff' => 'Nettoero',
    'net_diff_section_aria' => 'Nettoero perustason ja skenaarion välillä aikajänteen päivinä 30 / 60 / 90',
    'net_diff_delta_aria' => 'Nettoero päivänä :day: :value, skenaario on :state',
    'better_than_baseline' => 'parempi kuin perustaso',
    'worse_than_baseline' => 'huonompi kuin perustaso',
    'equal_to_baseline' => 'sama kuin perustaso',
    'at_day' => 'päivänä :day',

    'updating' => 'Päivitetään',
    'chart_noscript' => 'Kaavio vaatii JavaScriptin. Jakso kattaa :days päivän.|Kaavio vaatii JavaScriptin. Jakso kattaa :days päivää.',
    'total_balance' => 'Kokonaissaldo',

    'per_month_suffix' => '/kk',
    'confidence_chip_aria' => ':name, varmuus :confidence — ennusteen vaihteluväli on :percent prosenttia pistearviosta',

    'highlights_title' => 'Ennusteen kohokohdat',
    'highlights_shortfall_aria' => ':count aktiivinen vajejakso seuraavien :days päivän aikana|:count aktiivista vajejaksoa seuraavien :days päivän aikana',
    'dips_to' => ':name laskee arvoon :amount',
    'on_date_suffix' => ' päivänä :date',
    'shortfall_window' => '1 aktiivinen vajejakso|:count aktiivista vajejaksoa',
    'lowest_in_30' => 'Alin 30 päivän aikana: :amount',
    'next_ics' => 'Seuraava ICS-tilitys: :amount päivänä :date',
];
