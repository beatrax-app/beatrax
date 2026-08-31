<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Tavoitteet',
        'subtitle' => 'Seuraa edistymistä säästötavoitteissasi.',
        'add_goal' => 'Lisää tavoite',
    ],

    'empty' => [
        'heading' => 'Ei vielä tavoitteita',
        'body' => 'Aseta tavoitesumma ja päivämäärä, niin voit seurata säästöjesi edistymistä.',
        'add_first' => 'Lisää ensimmäinen tavoite',
    ],

    'status' => [
        'overdue' => 'Myöhässä',
        'reached' => 'Saavutettu',
        'completed' => 'Valmis',
        'archived' => 'Arkistoitu',
    ],

    'row' => [
        'edit' => 'Muokkaa',
    ],

    'progress' => [
        'aria' => ':name: :pct % valmiina',
    ],

    'card' => [
        'target_date' => 'Tavoitepäivä: :date',
    ],

    'projection' => [
        'target_reached' => 'Tavoite saavutettu',
        'closed_short' => 'Suljettu ennen tavoitetta',
        'add_contributions' => 'Lisää talletuksia, niin näet ennusteen',
        'not_enough_history' => 'Historiaa ei ole vielä tarpeeksi päivän ennustamiseen',
        'no_recent_contributions' => 'Ei viimeaikaisia lisäyksiä, joiden pohjalta ennustaa',
        'too_far_to_date' => 'Tällä tahdilla liian kaukana päivämäärää varten',
        'est' => 'Arvio :date ·',
        'projection_note' => '(ennuste)',
        'projected' => 'Ennuste: :date',
    ],

    'archive' => [
        'confirm_question' => 'Arkistoidaanko tämä tavoite?',
        'close' => 'Sulje',
        'confirm_aria' => 'Vahvista tavoitteen :name arkistointi',
        'archive' => 'Arkistoi',
    ],

    'actions' => [
        'more_aria' => 'Lisää toimintoja kohteelle :name',
        'mark_complete' => 'Merkitse valmiiksi',
        'mark_complete_caption' => 'Merkitse',
        'archive' => 'Arkistoi',
        'restore' => 'Palauta',
    ],

    'archived_disclosure' => 'Arkistoitu tavoite (:count)|Arkistoidut tavoitteet (:count)',

    'form' => [
        'title_edit' => 'Muokkaa tavoitetta',
        'title_create' => 'Luo säästötavoite',
        'subtitle_edit' => 'Päivitä nimi, tavoitesumma, päivämäärä tai liitetty potti.',
        'subtitle_create' => 'Aseta tavoitesumma ja päivämäärä säästöjesi seuraamista varten.',
        'name' => 'Nimi',
        'name_placeholder' => 'esim. Hätävara',
        'target_amount' => 'Tavoitesumma (:currency)',
        'target_date' => 'Tavoitepäivä',
        'linked_pot' => 'Liitetty säästöpotti (valinnainen)',
        'no_pot' => 'Ei säästöpottia — käytä siirtojen seurantaa',
        'linked_pot_help' => 'Kun potti on liitetty, sen saldo määrää tämän tavoitteen edistymisen.',
        'save_changes' => 'Tallenna muutokset',
        'save_goal' => 'Tallenna tavoite',
        'close' => 'Sulje',
    ],

    'summary' => [
        'see_all' => 'Näytä kaikki →',
        'no_goals' => 'Ei vielä tavoitteita.',
        'add_first' => 'Lisää ensimmäinen tavoite →',
    ],

    'notices' => [
        'goal_created' => 'Tavoite luotu.',
        'goal_updated' => 'Tavoite päivitetty.',
        'goal_marked_complete' => 'Tavoite merkitty valmiiksi.',
        'goal_archived' => 'Tavoite arkistoitu.',
        'goal_restored' => 'Tavoite palautettu.',
    ],

    'errors' => [
        'name' => 'Anna tavoitteelle nimi.',
        'date' => 'Valitse tavoitepäivä.',
        'date_invalid' => 'Valitse todellinen päivämäärä.',
        'date_before_start' => 'Valitse päivä tavoitteen alkamispäivänä tai sen jälkeen.',
        'generic' => 'Tavoitetta ei voitu tallentaa. Tarkista kentät ja yritä uudelleen.',
        'amount' => 'Anna kelvollinen nollaa suurempi summa.',
        'pot_linked_category' => 'Tämä potti on liitetty kategoriaan. Poista liitos ensin Säästöpotit-sivulla.',
        'pot_already_linked' => 'Tämä potti kerää jo toista tavoitetta varten. Poista liitos ensin sieltä.',
        'pot_missing' => 'Se potti ei ole enää käytettävissä. Valitse toinen tai jätä tämä tavoite liittämättä.',
    ],
];
