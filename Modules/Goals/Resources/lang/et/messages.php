<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Eesmärgid',
        'subtitle' => 'Jälgi edenemist oma säästueesmärkide poole.',
        'add_goal' => 'Lisa eesmärk',
    ],

    'empty' => [
        'heading' => 'Eesmärke veel pole',
        'body' => 'Määra sihtsumma ja kuupäev, et hakata säästmise edenemist jälgima.',
        'add_first' => 'Lisa oma esimene eesmärk',
    ],

    'status' => [
        'overdue' => 'Tähtaeg möödas',
        'reached' => 'Saavutatud',
        'completed' => 'Lõpetatud',
        'archived' => 'Arhiveeritud',
    ],

    'row' => [
        'edit' => 'Muuda',
    ],

    'progress' => [
        'aria' => ':name: :pct% täidetud',
    ],

    'card' => [
        'target_date' => 'Sihtkuupäev: :date',
    ],

    'projection' => [
        'target_reached' => 'Siht saavutatud',
        'closed_short' => 'Suletud enne eesmärgi saavutamist',
        'add_contributions' => 'Lisa sissemakseid, et näha prognoosi',
        'not_enough_history' => 'Ajalugu ei ole veel piisav kuupäeva prognoosimiseks',
        'no_recent_contributions' => 'Pole hiljutisi sissemakseid, mille põhjal prognoosida',
        'est' => 'Hinnanguliselt :date ·',
        'projection_note' => '(prognoos)',
        'projected' => 'Prognoositud: :date',
    ],

    'archive' => [
        'confirm_question' => 'Kas arhiveerida see eesmärk?',
        'close' => 'Sulge',
        'confirm_aria' => 'Kinnita eesmärgi :name arhiveerimine',
        'archive' => 'Arhiveeri',
    ],

    'actions' => [
        'more_aria' => 'Rohkem toiminguid eesmärgi :name jaoks',
        'mark_complete' => 'Märgi lõpetatuks',
        'archive' => 'Arhiveeri',
        'restore' => 'Taasta',
    ],

    'archived_disclosure' => 'Arhiveeritud eesmärgid (:count)',

    'form' => [
        'title_edit' => 'Muuda eesmärki',
        'title_create' => 'Loo säästueesmärk',
        'subtitle_edit' => 'Uuenda nime, sihti, kuupäeva või seotud potti.',
        'subtitle_create' => 'Määra sihtsumma ja kuupäev, et jälgida säästmise edenemist.',
        'name' => 'Nimi',
        'name_placeholder' => 'nt Hädaabifond',
        'target_amount' => 'Sihtsumma (:currency)',
        'target_date' => 'Sihtkuupäev',
        'linked_pot' => 'Seotud kogumispott (valikuline)',
        'no_pot' => 'Potti pole — kasuta ülekannete jälgimist',
        'linked_pot_help' => 'Kui pott on seotud, määrab poti jääk selle eesmärgi edenemise.',
        'save_changes' => 'Salvesta muudatused',
        'save_goal' => 'Salvesta eesmärk',
        'close' => 'Sulge',
    ],

    'summary' => [
        'see_all' => 'Vaata kõiki →',
        'no_goals' => 'Eesmärke veel pole.',
        'add_first' => 'Lisa oma esimene eesmärk →',
    ],

    'notices' => [
        'goal_created' => 'Eesmärk on loodud.',
        'goal_updated' => 'Eesmärk on uuendatud.',
        'goal_marked_complete' => 'Eesmärk on märgitud lõpetatuks.',
        'goal_archived' => 'Eesmärk on arhiveeritud.',
        'goal_restored' => 'Eesmärk on taastatud.',
    ],

    'errors' => [
        'name' => 'Sisesta oma eesmärgi nimi.',
        'date' => 'Vali sihtkuupäev.',
        'amount' => 'Sisesta kehtiv nullist suurem summa.',
        'pot_linked_category' => 'See pott on seotud kategooriaga. Eemalda see seos kõigepealt kogumispottide lehel.',
    ],
];
