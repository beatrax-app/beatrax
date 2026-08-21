<?php

declare(strict_types=1);

return [
    'page_title' => 'Krājkases · Beatrax',
    'heading' => 'Krājkases',
    'subtitle' => 'Virtuāli apakšatlikumi, kas kopā vienmēr veido jūsu reālo konta atlikumu.',
    'add_pot' => 'Pievienot krājkasi',

    'pot_fallback' => 'krājkase',

    'empty' => [
        'heading' => 'Vēl nav krājkasu',
        'body' => 'Izveidojiet virtuālus apakšatlikumus jebkurā kontā, lai sakārtotu naudu bez reāla bankas pārskaitījuma.',
        'cta' => 'Pievienojiet savu pirmo krājkasi',
        'no_accounts_cta' => 'Importēt konta izrakstu',
    ],

    'common' => [
        'cancel' => 'Atcelt',
        'amount' => 'Summa',
        'note_optional' => 'Piezīme (neobligāti)',
    ],

    'actions' => [
        'fund' => 'Papildināt',
        'move' => 'Pārvietot',
        'edit' => 'Rediģēt',
        'withdraw' => 'Izņemt',
        'archive' => 'Arhivēt',
        'restore' => 'Atjaunot',
    ],

    'recon' => [
        'over_allocated' => 'Krājkases pārsniedz reālo atlikumu par :amount — līdzsvarojiet, lai to novērstu',
        'real_balance' => 'Reālais atlikums:',
        'allocated' => 'Piešķirts:',
        'unallocated' => 'Nepiešķirts:',
    ],

    'chip' => [
        'goal' => 'Mērķis:',
        'goal_name_fallback' => 'Mērķis',
        'category_fallback' => 'Kategorija',
    ],

    'coverage' => [
        'spent' => 'iztērēts',
        'in_pot' => 'krājkasē',
    ],

    'archive_confirm' => 'Arhivēt šo krājkasi? Atlikums :amount atgriezīsies nepiešķirtajā daļā.',
    'confirm_archive_aria' => 'Apstiprināt :name arhivēšanu',
    'more_actions_aria' => 'Vairāk darbību ar :name',

    'history' => [
        'show' => 'Rādīt vēsturi ↓',
        'hide' => 'Slēpt vēsturi ↑',
    ],

    'movement' => [
        'fund' => 'Papildināts',
        'withdraw' => 'Izņemts',
        'moved_from' => 'Pārvietots no :name',
        'moved_to' => 'Pārvietots uz :name',
    ],

    'archived' => [
        'toggle' => 'Arhivētās krājkases (:count)',
        'badge' => 'Arhivēta',
    ],

    'form' => [
        'create_title' => 'Izveidot krājkasi',
        'edit_title' => 'Rediģēt krājkasi',
        'create_subtitle' => 'Nosauciet virtuālu apakšatlikumu kontā.',
        'edit_subtitle' => 'Atjauniniet šīs krājkases nosaukumu vai saiti.',
        'name' => 'Nosaukums',
        'name_placeholder' => 'piem. Atvaļinājuma fonds',
        'account' => 'Konts',
        'select_account' => 'Izvēlieties kontu',
        'initial_amount' => 'Sākuma summa (neobligāti)',
        'initial_amount_help' => 'Summa tiek atskaitīta no nepiešķirtā atlikuma. Atstājiet tukšu, lai izveidotu tukšu krājkasi.',
        'link_to' => 'Saistīt ar (neobligāti)',
        'link_goal' => 'Mērķis',
        'link_none' => 'Nav',
        'select_goal' => 'Izvēlieties mērķi',
        'save_pot' => 'Saglabāt krājkasi',
        'save_changes' => 'Saglabāt izmaiņas',
    ],

    'fund' => [
        'title' => 'Papildināt krājkasi',
        'heading' => 'Papildināt :name',
        'submit' => 'Papildināt krājkasi',
        'note_placeholder' => 'piem. Ikmēneša uzkrājums',
        'available' => 'Pieejams sadalei: :amount (nepiešķirts)',
    ],

    'move' => [
        'title' => 'Pārvietot līdzekļus',
        'heading' => 'Pārvietot no :name',
        'to' => 'Pārvietot uz',
        'select_pot' => 'Izvēlieties krājkasi',
        'no_others_short' => 'Nav citu krājkasu',
        'no_others' => 'Šajā kontā nav citu krājkasu',
        'submit' => 'Pārvietot līdzekļus',
        'note_placeholder' => 'piem. Pārskaitījums atvaļinājumam',
    ],

    'withdraw' => [
        'heading' => 'Izņemt no :name',
        'note_placeholder' => 'piem. Izņemšana',
    ],

    'available_in' => 'Pieejams krājkasē :name: :amount',

    'errors' => [
        'enter_name' => 'Ievadiet šīs krājkases nosaukumu.',
        'select_account' => 'Izvēlieties kontu šai krājkasei.',
        'amount_exceeds_unallocated' => 'Summa pārsniedz nepiešķirto atlikumu.',
        'amount_exceeds_unallocated_available' => 'Summa pārsniedz nepiešķirto atlikumu (pieejams :amount).',
        'amount_exceeds_pot_balance' => 'Summa pārsniedz atlikumu krājkasē :name (pieejams :amount).',
    ],

    'toast' => [
        'pot_created' => 'Krājkase izveidota.',
        'pot_updated' => 'Krājkase atjaunināta.',
        'pot_funded' => 'Krājkase papildināta.',
        'withdrawn' => 'Izņemts no krājkases.',
        'funds_moved' => 'Līdzekļi pārvietoti.',
        'pot_archived' => 'Krājkase arhivēta.',
        'pot_restored' => 'Krājkase atjaunota.',
    ],
];
