<?php

declare(strict_types=1);

return [
    'heading' => 'Napoved',
    'page_title' => 'Napoved',
    'subtitle' => 'Kam gre tvoje stanje — v naslednjih 30 do 365 dneh.',
    'adjust_buffers' => 'Prilagodi rezerve',

    'empty_heading' => 'Podatkov za napoved še ni',
    'empty_body' => 'Poveži račun ali odobri ponavljajočo se serijo, da vidiš predvideno stanje v prihodnjih tednih.',
    'empty_start' => 'Začni tako, da',
    'empty_import_link' => 'uvoziš izpisek',
    'empty_or' => 'ali',
    'empty_recurring_link' => 'pregledaš ponavljajoče se vzorce',

    'account_tablist' => 'Račun',
    'all_accounts' => 'Vsi računi',

    'horizon_label' => 'Obzorje napovedi',
    'n_days' => ':days dan|:days dneva|:days dnevi|:days dni',

    'view_by_funder' => 'Prikaži po plačniku',
    'view_by_funder_hint' => 'Strni serije, razrešene prek verige, na račun, ki jih dejansko plačuje.',

    'scenario_group' => 'Scenarij',
    'baseline' => 'Izhodiščni scenarij',
    'scenario_word' => 'Scenarij',
    'new_scenario' => '+ Nov scenarij',
    'scenario_name_placeholder' => 'Ime scenarija',
    'new_scenario_aria' => 'Ime novega scenarija',
    'create_scenario' => 'Ustvari scenarij',
    'cancel' => 'Prekliči',

    'aggregate_subtitle' => 'Skupno stanje vseh računov, predvideno za naslednji :days dan.|Skupno stanje vseh računov, predvideno za naslednja :days dneva.|Skupno stanje vseh računov, predvideno za naslednje :days dneve.|Skupno stanje vseh računov, predvideno za naslednjih :days dni.',

    'today' => 'danes',
    'on_day' => 'na dan',

    'edit_buffer_aria' => 'Uredi najnižjo rezervo za :name',
    'buffer_not_set' => 'Rezerva: ni nastavljena',
    'buffer_set' => 'Rezerva: :amount',

    'shortfall' => 'Primanjkljaj se začne :date — :amount pod tvojo rezervo :buffer',

    'compared_against_baseline' => 'Primerjano z izhodiščnim scenarijem zgoraj',

    'scenario_editor_aria' => 'Urejevalnik scenarija',
    'series_confidence' => 'Zanesljivost serije',
    'no_series_contribute' => 'K napovedi tega računa še ne prispeva nobena serija.',

    'net_diff' => 'Neto razlika',
    'net_diff_section_aria' => 'Neto razlika med izhodiščnim scenarijem in scenarijem pri obzorju 30 / 60 / 90 dni',
    'net_diff_delta_aria' => 'Neto razlika na dan :day: :value, scenarij je :state',
    'better_than_baseline' => 'boljši od izhodiščnega scenarija',
    'worse_than_baseline' => 'slabši od izhodiščnega scenarija',
    'equal_to_baseline' => 'enak izhodiščnemu scenariju',
    'at_day' => 'na dan :day',

    'updating' => 'Posodabljanje',
    'chart_noscript' => 'Grafikon zahteva JavaScript. Obseg pokriva :days dan.|Grafikon zahteva JavaScript. Obseg pokriva :days dneva.|Grafikon zahteva JavaScript. Obseg pokriva :days dneve.|Grafikon zahteva JavaScript. Obseg pokriva :days dni.',
    'total_balance' => 'Skupno stanje',

    'per_month_suffix' => '/mes.',
    'confidence_chip_aria' => ':name, zanesljivost :confidence — razpon napovedi je :percent odstotkov točkovne ocene',

    'highlights_title' => 'Poudarki napovedi',
    'highlights_shortfall_aria' => ':count aktivno obdobje primanjkljaja v naslednjih :days dneh|:count aktivni obdobji primanjkljaja v naslednjih :days dneh|:count aktivna obdobja primanjkljaja v naslednjih :days dneh|:count aktivnih obdobij primanjkljaja v naslednjih :days dneh',
    'on_date_suffix' => ' na dan :date',
    'shortfall_window' => ':count aktivno obdobje primanjkljaja|:count aktivni obdobji primanjkljaja|:count aktivna obdobja primanjkljaja|:count aktivnih obdobij primanjkljaja',
    'lowest_in_30_label' => 'Najnižje v 30 dneh',
    'next_ics' => 'Naslednja poravnava ICS: :amount na dan :date',
];
