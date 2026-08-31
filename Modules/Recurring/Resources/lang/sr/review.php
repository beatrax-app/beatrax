<?php

declare(strict_types=1);

return [
    'title' => 'Pregled ponavljajućih',
    'subtitle' => 'Odobri, odloži ili odbij otkrivene predloge ponavljajućih plaćanja.',

    'tabs' => [
        'pending' => 'Na čekanju',
        'rejected' => 'Odbijeno',
        'cadence_changed' => 'Promenjena učestalost',
    ],

    'bulk' => [
        'aria' => 'Grupne radnje',
        'selected' => 'izabrano: :count',
        'approve' => 'Odobri (:count)',
        'reject' => 'Odbij (:count)',
    ],

    'empty' => [
        'heading' => 'Nema ničega za pregled',
        'pending' => 'Predlozi ponavljajućih stižu ovde kada detektor prepozna stabilne mesečne grupe.',
        'rejected' => 'Odbijeni predlozi ostaju ovde da možeš da ih vratiš ako se predomisliš.',
        'cadence_changed' => 'Odobrene serije kojima se učestalost promenila pojavljuju se ovde za ponovni pregled.',
    ],

    'next' => 'Sledeće',
    'overdue' => 'Kasni',
    'cadence_changed_note' => 'učestalost promenjena',
    'un_reject' => 'Opozovi odbijanje',
    'approve' => 'Odobri',
    'approve_aria' => 'Odobri ponavljajuću seriju :id',
    'reject' => 'Odbij',
    'reject_aria' => 'Odbij ponavljajuću seriju :id',
    'snooze' => 'Odloži',
    'snooze_aria' => 'Odloži ponavljajuću seriju :id',
    'snooze_1w' => '1 nedelja',
    'snooze_1m' => '1 mesec',
    'snooze_3m' => '3 meseca',
    'edit_name' => 'Izmeni naziv',
    'edit_name_aria' => 'Preimenuj ponavljajuću seriju :id',
    'new_name_label' => 'Novi naziv za ovu seriju',
    'load_more' => 'Učitaj još',
    'save' => 'Sačuvaj',

    'toast' => [
        'approved' => 'Odobreno',
        'rejected' => 'Odbijeno',
        'snoozed' => 'Odloženo',
        'renamed' => 'Preimenovano',
        'un_rejected' => 'Odbijanje opozvano',
        'bulk_approved' => 'Odobreno: :count',
        'bulk_rejected' => 'Odbijeno: :count',
    ],
];
