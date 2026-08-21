<?php

declare(strict_types=1);

return [
    'page_title' => 'Säästöpotit · Beatrax',
    'heading' => 'Säästöpotit',
    'subtitle' => 'Virtuaalisia osasaldoja, joiden summa vastaa aina todellista tilisaldoasi.',
    'add_pot' => 'Lisää potti',

    'pot_fallback' => 'potti',

    'empty' => [
        'heading' => 'Ei vielä säästöpotteja',
        'body' => 'Luo virtuaalisia osasaldoja mille tahansa tilille ja järjestä rahasi ilman oikeaa tilisiirtoa.',
        'cta' => 'Lisää ensimmäinen potti',
        'no_accounts_cta' => 'Tuo tiliote',
    ],

    'common' => [
        'cancel' => 'Peruuta',
        'amount' => 'Summa',
        'note_optional' => 'Muistiinpano (valinnainen)',
    ],

    'actions' => [
        'fund' => 'Lisää rahaa',
        'move' => 'Siirrä',
        'edit' => 'Muokkaa',
        'withdraw' => 'Nosta',
        'archive' => 'Arkistoi',
        'restore' => 'Palauta',
    ],

    'recon' => [
        'over_allocated' => 'Potit ylittävät todellisen saldon :amount — korjaa tasapainottamalla',
        'real_balance' => 'Todellinen saldo:',
        'allocated' => 'Jaettu:',
        'unallocated' => 'Jakamaton:',
    ],

    'chip' => [
        'goal' => 'Tavoite:',
        'goal_name_fallback' => 'Tavoite',
        'category_fallback' => 'Kategoria',
    ],

    'coverage' => [
        'spent' => 'käytetty',
        'in_pot' => 'potissa',
    ],

    'archive_confirm' => 'Arkistoidaanko tämä potti? Saldo :amount palautuu jakamattomiin varoihin.',
    'confirm_archive_aria' => 'Vahvista kohteen :name arkistointi',
    'more_actions_aria' => 'Lisää toimintoja kohteelle :name',

    'history' => [
        'show' => 'Näytä historia ↓',
        'hide' => 'Piilota historia ↑',
    ],

    'movement' => [
        'fund' => 'Lisäys',
        'withdraw' => 'Nosto',
        'moved_from' => 'Siirretty potista :name',
        'moved_to' => 'Siirretty pottiin :name',
    ],

    'archived' => [
        'toggle' => 'Arkistoidut potit (:count)',
        'badge' => 'Arkistoitu',
    ],

    'form' => [
        'create_title' => 'Luo potti',
        'edit_title' => 'Muokkaa pottia',
        'create_subtitle' => 'Nimeä virtuaalinen osasaldo tilin sisällä.',
        'edit_subtitle' => 'Päivitä tämän potin nimi tai liitos.',
        'name' => 'Nimi',
        'name_placeholder' => 'esim. Lomarahasto',
        'account' => 'Tili',
        'select_account' => 'Valitse tili',
        'initial_amount' => 'Alkusumma (valinnainen)',
        'initial_amount_help' => 'Summa vähennetään jakamattomista varoista. Jätä tyhjäksi, niin potti luodaan tyhjänä.',
        'link_to' => 'Liitä kohteeseen (valinnainen)',
        'link_goal' => 'Tavoite',
        'link_none' => 'Ei mitään',
        'select_goal' => 'Valitse tavoite',
        'save_pot' => 'Tallenna potti',
        'save_changes' => 'Tallenna muutokset',
    ],

    'fund' => [
        'title' => 'Lisää rahaa pottiin',
        'heading' => 'Lisää rahaa pottiin :name',
        'submit' => 'Lisää rahaa',
        'note_placeholder' => 'esim. Kuukausisäästö',
        'available' => 'Jaettavissa: :amount (jakamattomat varat)',
    ],

    'move' => [
        'title' => 'Siirrä varoja',
        'heading' => 'Siirrä potista :name',
        'to' => 'Siirrä pottiin',
        'select_pot' => 'Valitse potti',
        'no_others_short' => 'Ei muita potteja',
        'no_others' => 'Ei muita potteja tällä tilillä',
        'submit' => 'Siirrä varat',
        'note_placeholder' => 'esim. Siirto lomaa varten',
    ],

    'withdraw' => [
        'heading' => 'Nosta potista :name',
        'note_placeholder' => 'esim. Nosto',
    ],

    'available_in' => 'Käytettävissä potissa :name: :amount',

    'errors' => [
        'enter_name' => 'Anna tälle potille nimi.',
        'select_account' => 'Valitse tälle potille tili.',
        'amount_exceeds_unallocated' => 'Summa ylittää jakamattoman saldon.',
        'amount_exceeds_unallocated_available' => 'Summa ylittää jakamattoman saldon (:amount käytettävissä).',
        'amount_exceeds_pot_balance' => 'Summa ylittää potin :name saldon (:amount käytettävissä).',
    ],

    'toast' => [
        'pot_created' => 'Potti luotu.',
        'pot_updated' => 'Potti päivitetty.',
        'pot_funded' => 'Pottiin lisättiin rahaa.',
        'withdrawn' => 'Potista nostettiin.',
        'funds_moved' => 'Varat siirretty.',
        'pot_archived' => 'Potti arkistoitu.',
        'pot_restored' => 'Potti palautettu.',
    ],
];
