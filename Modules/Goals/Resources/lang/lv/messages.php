<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Mērķi',
        'subtitle' => 'Sekojiet virzībai uz saviem uzkrājumu mērķiem.',
        'add_goal' => 'Pievienot mērķi',
    ],

    'empty' => [
        'heading' => 'Vēl nav mērķu',
        'body' => 'Norādiet mērķa summu un datumu, lai sāktu sekot uzkrājumu virzībai.',
        'add_first' => 'Pievienojiet savu pirmo mērķi',
    ],

    'status' => [
        'overdue' => 'Nokavēts',
        'reached' => 'Sasniegts',
        'completed' => 'Pabeigts',
        'archived' => 'Arhivēts',
    ],

    'row' => [
        'edit' => 'Rediģēt',
    ],

    'progress' => [
        'aria' => ':name: paveikti :pct%',
    ],

    'card' => [
        'target_date' => 'Mērķa datums: :date',
    ],

    'projection' => [
        'target_reached' => 'Mērķis sasniegts',
        'closed_short' => 'Slēgts pirms mērķa sasniegšanas',
        'add_contributions' => 'Pievienojiet iemaksas, lai redzētu prognozi',
        'not_enough_history' => 'Vēl nepietiek vēstures, lai prognozētu datumu',
        'no_recent_contributions' => 'Nav nesenu iemaksu, uz kurām balstīt prognozi',
        'est' => 'Apt. :date ·',
        'projection_note' => '(prognoze)',
        'projected' => 'Prognoze: :date',
    ],

    'archive' => [
        'confirm_question' => 'Arhivēt šo mērķi?',
        'close' => 'Aizvērt',
        'confirm_aria' => 'Apstiprināt :name arhivēšanu',
        'archive' => 'Arhivēt',
    ],

    'actions' => [
        'more_aria' => 'Vairāk darbību ar :name',
        'mark_complete' => 'Atzīmēt kā pabeigtu',
        'archive' => 'Arhivēt',
        'restore' => 'Atjaunot',
    ],

    'archived_disclosure' => 'Arhivētie mērķi (:count)',

    'form' => [
        'title_edit' => 'Rediģēt mērķi',
        'title_create' => 'Izveidot uzkrājumu mērķi',
        'subtitle_edit' => 'Atjauniniet nosaukumu, mērķa summu, datumu vai saistīto krājkasi.',
        'subtitle_create' => 'Norādiet mērķa summu un datumu, lai sekotu uzkrājumu virzībai.',
        'name' => 'Nosaukums',
        'name_placeholder' => 'piem. Rezerves fonds',
        'target_amount' => 'Mērķa summa (:currency)',
        'target_date' => 'Mērķa datums',
        'linked_pot' => 'Saistītā krājkase (neobligāti)',
        'no_pot' => 'Bez krājkases — izmantot pārskaitījumu uzskaiti',
        'linked_pot_help' => 'Kad krājkase ir saistīta, tās atlikums nosaka šī mērķa virzību.',
        'save_changes' => 'Saglabāt izmaiņas',
        'save_goal' => 'Saglabāt mērķi',
        'close' => 'Aizvērt',
    ],

    'summary' => [
        'see_all' => 'Skatīt visus →',
        'no_goals' => 'Vēl nav mērķu.',
        'add_first' => 'Pievienojiet savu pirmo mērķi →',
    ],

    'notices' => [
        'goal_created' => 'Mērķis izveidots.',
        'goal_updated' => 'Mērķis atjaunināts.',
        'goal_marked_complete' => 'Mērķis atzīmēts kā pabeigts.',
        'goal_archived' => 'Mērķis arhivēts.',
        'goal_restored' => 'Mērķis atjaunots.',
    ],

    'errors' => [
        'name' => 'Ievadiet mērķa nosaukumu.',
        'date' => 'Izvēlieties mērķa datumu.',
        'date_invalid' => 'Izvēlieties reālu datumu.',
        'generic' => 'Mērķi neizdevās saglabāt. Pārbaudiet laukus un mēģiniet vēlreiz.',
        'amount' => 'Ievadiet derīgu summu, kas lielāka par nulli.',
        'pot_linked_category' => 'Šī krājkase ir saistīta ar kategoriju. Vispirms noņemiet šo saiti Krājkasu lapā.',
    ],
];
