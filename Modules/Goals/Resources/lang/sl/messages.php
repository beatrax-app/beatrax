<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Cilji',
        'subtitle' => 'Spremljaj napredek proti svojim varčevalnim ciljem.',
        'add_goal' => 'Dodaj cilj',
    ],

    'empty' => [
        'heading' => 'Ciljev še ni',
        'body' => 'Nastavi ciljni znesek in datum, da začneš spremljati napredek varčevanja.',
        'add_first' => 'Dodaj svoj prvi cilj',
    ],

    'status' => [
        'overdue' => 'Zamuja',
        'reached' => 'Doseženo',
        'completed' => 'Zaključeno',
        'archived' => 'Arhivirano',
    ],

    'row' => [
        'edit' => 'Uredi',
    ],

    'progress' => [
        'aria' => ':name: dokončano :pct%',
    ],

    'projection' => [
        'target_reached' => 'Cilj je dosežen',
        'add_contributions' => 'Dodaj vplačila, da vidiš napoved',
        'building' => 'Izdelava napovedi…',
        'est' => 'Pred. :date ·',
        'projection_note' => '(napoved)',
        'projected' => 'Napoved: :date',
    ],

    'archive' => [
        'confirm_question' => 'Arhivirati ta cilj?',
        'close' => 'Zapri',
        'confirm_aria' => 'Potrdi arhiviranje cilja :name',
        'archive' => 'Arhiviraj',
    ],

    'actions' => [
        'more_aria' => 'Več dejanj za :name',
        'mark_complete' => 'Označi kot zaključeno',
        'archive' => 'Arhiviraj',
        'restore' => 'Obnovi',
    ],

    'archived_disclosure' => 'Arhivirani cilji (:count)',

    'form' => [
        'title_edit' => 'Uredi cilj',
        'title_create' => 'Ustvari varčevalni cilj',
        'subtitle_edit' => 'Posodobi ime, ciljni znesek, datum ali povezan hranilnik.',
        'subtitle_create' => 'Nastavi ciljni znesek in datum, da spremljaš napredek varčevanja.',
        'name' => 'Ime',
        'name_placeholder' => 'npr. Rezerva za nujne primere',
        'target_amount' => 'Ciljni znesek (:currency)',
        'target_date' => 'Ciljni datum',
        'linked_pot' => 'Povezan hranilnik (neobvezno)',
        'no_pot' => 'Brez hranilnika — uporabi sledenje prenosom',
        'linked_pot_help' => 'Ko je povezan, stanje hranilnika določa napredek tega cilja.',
        'save_changes' => 'Shrani spremembe',
        'save_goal' => 'Shrani cilj',
        'close' => 'Zapri',
    ],

    'summary' => [
        'see_all' => 'Prikaži vse →',
        'no_goals' => 'Ciljev še ni.',
        'add_first' => 'Dodaj svoj prvi cilj →',
    ],

    'notices' => [
        'goal_created' => 'Cilj je ustvarjen.',
        'goal_updated' => 'Cilj je posodobljen.',
        'goal_marked_complete' => 'Cilj je označen kot zaključen.',
        'goal_archived' => 'Cilj je arhiviran.',
        'goal_restored' => 'Cilj je obnovljen.',
    ],

    'errors' => [
        'name' => 'Vnesi ime svojega cilja.',
        'date' => 'Izberi ciljni datum.',
        'amount' => 'Vnesi veljaven znesek, večji od nič.',
        'pot_linked_category' => 'Ta hranilnik je povezan s kategorijo. Najprej odstrani to povezavo na strani Hranilniki.',
    ],
];
