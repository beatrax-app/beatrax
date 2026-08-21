<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Obiective',
        'subtitle' => 'Urmărește progresul spre țintele tale de economisire.',
        'add_goal' => 'Adaugă obiectiv',
    ],

    'empty' => [
        'heading' => 'Niciun obiectiv deocamdată',
        'body' => 'Stabilește o sumă țintă și o dată pentru a începe să urmărești progresul economiilor.',
        'add_first' => 'Adaugă primul obiectiv',
    ],

    'status' => [
        'overdue' => 'Restant',
        'reached' => 'Atins',
        'completed' => 'Finalizat',
        'archived' => 'Arhivat',
    ],

    'row' => [
        'edit' => 'Editează',
    ],

    'progress' => [
        'aria' => ':name: :pct% finalizat',
    ],

    'projection' => [
        'target_reached' => 'Țintă atinsă',
        'add_contributions' => 'Adaugă contribuții pentru a vedea o proiecție',
        'not_enough_history' => 'Încă nu există suficient istoric pentru a estima o dată',
        'est' => 'Est. :date ·',
        'projection_note' => '(proiecție)',
        'projected' => 'Estimat: :date',
    ],

    'archive' => [
        'confirm_question' => 'Arhivezi acest obiectiv?',
        'close' => 'Închide',
        'confirm_aria' => 'Confirmă arhivarea pentru :name',
        'archive' => 'Arhivează',
    ],

    'actions' => [
        'more_aria' => 'Mai multe acțiuni pentru :name',
        'mark_complete' => 'Marchează drept finalizat',
        'archive' => 'Arhivează',
        'restore' => 'Restaurează',
    ],

    'archived_disclosure' => 'Obiective arhivate (:count)',

    'form' => [
        'title_edit' => 'Editează obiectivul',
        'title_create' => 'Creează un obiectiv de economisire',
        'subtitle_edit' => 'Actualizează numele, ținta, data sau pușculița asociată.',
        'subtitle_create' => 'Stabilește o sumă țintă și o dată pentru a urmări progresul economiilor.',
        'name' => 'Nume',
        'name_placeholder' => 'ex. Fond de urgență',
        'target_amount' => 'Sumă țintă (:currency)',
        'target_date' => 'Dată țintă',
        'linked_pot' => 'Pușculiță asociată (opțional)',
        'no_pot' => 'Fără pușculiță — urmărire prin transferuri',
        'linked_pot_help' => 'Când este asociată, soldul pușculiței determină progresul obiectivului.',
        'save_changes' => 'Salvează modificările',
        'save_goal' => 'Salvează obiectivul',
        'close' => 'Închide',
    ],

    'summary' => [
        'see_all' => 'Vezi toate →',
        'no_goals' => 'Niciun obiectiv deocamdată.',
        'add_first' => 'Adaugă primul obiectiv →',
    ],

    'notices' => [
        'goal_created' => 'Obiectiv creat.',
        'goal_updated' => 'Obiectiv actualizat.',
        'goal_marked_complete' => 'Obiectiv marcat drept finalizat.',
        'goal_archived' => 'Obiectiv arhivat.',
        'goal_restored' => 'Obiectiv restaurat.',
    ],

    'errors' => [
        'name' => 'Introdu un nume pentru obiectiv.',
        'date' => 'Alege o dată țintă.',
        'amount' => 'Introdu o sumă validă, mai mare decât zero.',
        'pot_linked_category' => 'Această pușculiță este asociată unei categorii. Elimină întâi asocierea din pagina Pușculițe.',
    ],
];
