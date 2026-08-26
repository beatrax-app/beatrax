<?php

declare(strict_types=1);

return [
    'title' => 'Pregled ponavljajućih',
    'subtitle' => 'Odobri, odgodi ili odbij otkrivene prijedloge ponavljajućih plaćanja.',

    'tabs' => [
        'pending' => 'Na čekanju',
        'rejected' => 'Odbijeno',
        'cadence_changed' => 'Promijenjena učestalost',
    ],

    'bulk' => [
        'aria' => 'Skupne radnje',
        'selected' => 'odabrano: :count',
        'approve' => 'Odobri (:count)',
        'reject' => 'Odbij (:count)',
    ],

    'empty' => [
        'heading' => 'Nema ničega za pregled',
        'pending' => 'Prijedlozi ponavljajućih stižu ovdje kad detektor prepozna stabilne mjesečne skupine.',
        'rejected' => 'Odbijeni prijedlozi ostaju ovdje da ih možeš vratiti ako se predomisliš.',
        'cadence_changed' => 'Odobrene serije kojima se učestalost promijenila pojavljuju se ovdje za ponovni pregled.',
    ],

    'next' => 'Sljedeće',
    'overdue' => 'Kasni',
    'cadence_changed_note' => 'učestalost promijenjena',
    'un_reject' => 'Poništi odbijanje',
    'approve' => 'Odobri',
    'approve_aria' => 'Odobri ponavljajuću seriju :id',
    'reject' => 'Odbij',
    'reject_aria' => 'Odbij ponavljajuću seriju :id',
    'snooze' => 'Odgodi',
    'snooze_aria' => 'Odgodi ponavljajuću seriju :id',
    'snooze_1w' => '1 tjedan',
    'snooze_1m' => '1 mjesec',
    'snooze_3m' => '3 mjeseca',
    'edit_name' => 'Uredi naziv',
    'edit_name_aria' => 'Preimenuj ponavljajuću seriju :id',
    'new_name_label' => 'Novi naziv za ovu seriju',
    'save' => 'Spremi',

    'toast' => [
        'approved' => 'Odobreno',
        'rejected' => 'Odbijeno',
        'snoozed' => 'Odgođeno',
        'renamed' => 'Preimenovano',
        'un_rejected' => 'Odbijanje poništeno',
        'bulk_approved' => 'Odobreno: :count',
        'bulk_rejected' => 'Odbijeno: :count',
    ],
];
