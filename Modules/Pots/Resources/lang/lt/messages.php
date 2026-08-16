<?php

declare(strict_types=1);

return [
    'page_title' => 'Taupyklės · Beatrax',
    'heading' => 'Taupyklės',
    'subtitle' => 'Virtualūs daliniai likučiai, kurių suma visada atitinka tikrą sąskaitos likutį.',
    'add_pot' => 'Pridėti taupyklę',

    'pot_fallback' => 'taupyklė',

    'empty' => [
        'heading' => 'Taupyklių dar nėra',
        'body' => 'Sukurk virtualius dalinius likučius bet kurioje sąskaitoje ir tvarkyk pinigus be tikro banko pavedimo.',
        'cta' => 'Pridėk pirmą taupyklę',
    ],

    'common' => [
        'cancel' => 'Atšaukti',
        'amount' => 'Suma',
        'note_optional' => 'Pastaba (neprivaloma)',
    ],

    'actions' => [
        'fund' => 'Papildyti',
        'move' => 'Perkelti',
        'edit' => 'Redaguoti',
        'withdraw' => 'Išimti',
        'archive' => 'Archyvuoti',
        'restore' => 'Atkurti',
    ],

    'recon' => [
        'over_allocated' => 'Taupyklės viršija tikrą likutį :amount — subalansuok',
        'real_balance' => 'Tikras likutis:',
        'allocated' => 'Paskirstyta:',
        'unallocated' => 'Nepaskirstyta:',
    ],

    'chip' => [
        'goal' => 'Tikslas:',
        'goal_name_fallback' => 'Tikslas',
        'category_fallback' => 'Kategorija',
    ],

    'coverage' => [
        'spent' => 'išleista',
        'in_pot' => 'taupyklėje',
    ],

    'archive_confirm' => 'Archyvuoti šią taupyklę? :amount likutis grįš į nepaskirstytas lėšas.',
    'confirm_archive_aria' => 'Patvirtinti archyvavimą: :name',
    'more_actions_aria' => 'Daugiau veiksmų: :name',

    'history' => [
        'show' => 'Rodyti istoriją ↓',
        'hide' => 'Slėpti istoriją ↑',
    ],

    'movement' => [
        'fund' => 'Papildymas',
        'withdraw' => 'Išėmimas',
        'moved_from' => 'Perkelta iš :name',
        'moved_to' => 'Perkelta į :name',
    ],

    'archived' => [
        'toggle' => 'Archyvuotos taupyklės (:count)',
        'badge' => 'Archyvuota',
    ],

    'form' => [
        'create_title' => 'Sukurti taupyklę',
        'edit_title' => 'Redaguoti taupyklę',
        'create_subtitle' => 'Pavadink virtualų dalinį likutį sąskaitoje.',
        'edit_subtitle' => 'Atnaujink šios taupyklės pavadinimą arba sąsają.',
        'name' => 'Pavadinimas',
        'name_placeholder' => 'pvz. Atostogų fondas',
        'account' => 'Sąskaita',
        'select_account' => 'Pasirink sąskaitą',
        'initial_amount' => 'Pradinė suma (neprivaloma)',
        'initial_amount_help' => 'Suma nuskaičiuojama nuo nepaskirstytų lėšų. Palik tuščią, kad sukurtum tuščią taupyklę.',
        'link_to' => 'Susieti su (neprivaloma)',
        'link_goal' => 'Tikslas',
        'link_none' => 'Nieko',
        'select_goal' => 'Pasirink tikslą',
        'save_pot' => 'Išsaugoti taupyklę',
        'save_changes' => 'Išsaugoti pakeitimus',
    ],

    'fund' => [
        'title' => 'Papildyti taupyklę',
        'heading' => 'Papildymas: :name',
        'submit' => 'Papildyti taupyklę',
        'note_placeholder' => 'pvz. Mėnesinis taupymas',
        'available' => 'Galima paskirstyti: :amount (nepaskirstyta)',
    ],

    'move' => [
        'title' => 'Perkelti lėšas',
        'heading' => 'Perkėlimas iš: :name',
        'to' => 'Perkelti į',
        'select_pot' => 'Pasirink taupyklę',
        'no_others_short' => 'Kitų taupyklių nėra',
        'no_others' => 'Šioje sąskaitoje kitų taupyklių nėra',
        'submit' => 'Perkelti lėšas',
        'note_placeholder' => 'pvz. Pervedimas atostogoms',
    ],

    'withdraw' => [
        'heading' => 'Išėmimas iš: :name',
        'note_placeholder' => 'pvz. Išėmimas',
    ],

    'available_in' => 'Taupyklėje :name galima: :amount',

    'errors' => [
        'enter_name' => 'Įvesk šios taupyklės pavadinimą.',
        'select_account' => 'Pasirink šios taupyklės sąskaitą.',
        'amount_exceeds_unallocated' => 'Suma viršija nepaskirstytą likutį.',
        'amount_exceeds_unallocated_available' => 'Suma viršija nepaskirstytą likutį (galima :amount).',
        'amount_exceeds_pot_balance' => 'Suma viršija likutį taupyklėje :name (galima :amount).',
    ],

    'toast' => [
        'pot_created' => 'Taupyklė sukurta.',
        'pot_updated' => 'Taupyklė atnaujinta.',
        'pot_funded' => 'Taupyklė papildyta.',
        'withdrawn' => 'Iš taupyklės išimta.',
        'funds_moved' => 'Lėšos perkeltos.',
        'pot_archived' => 'Taupyklė archyvuota.',
        'pot_restored' => 'Taupyklė atkurta.',
    ],
];
