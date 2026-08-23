<?php

declare(strict_types=1);

return [
    'title' => 'Terugkerend beoordelen',
    'subtitle' => 'Keur gedetecteerde terugkerende suggesties goed, stel ze uit of wijs ze af.',

    'tabs' => [
        'pending' => 'In behandeling',
        'rejected' => 'Afgewezen',
        'cadence_changed' => 'Frequentie gewijzigd',
    ],

    'bulk' => [
        'aria' => 'Bulkacties',
        'selected' => ':count geselecteerd',
        'approve' => ':count goedkeuren',
        'reject' => ':count afwijzen',
    ],

    'empty' => [
        'heading' => 'Niets te beoordelen',
        'pending' => 'Terugkerende suggesties verschijnen hier zodra de detector stabiele maandelijkse clusters vindt.',
        'rejected' => 'Afgewezen suggesties verschijnen hier, zodat je ze kunt terughalen als je van gedachten verandert.',
        'cadence_changed' => 'Goedgekeurde reeksen waarvan de frequentie is gewijzigd, verschijnen hier voor herbeoordeling.',
    ],

    'next' => 'Volgende',
    'cadence_changed_note' => 'frequentie gewijzigd',

    'select_aria' => 'Terugkerende reeks :id selecteren',
    'un_reject' => 'Afwijzing ongedaan maken',
    'approve' => 'Goedkeuren',
    'approve_aria' => 'Terugkerende reeks :id goedkeuren',
    'reject' => 'Afwijzen',
    'reject_aria' => 'Terugkerende reeks :id afwijzen',
    'snooze' => 'Uitstellen',
    'snooze_aria' => 'Terugkerende reeks :id uitstellen',
    'snooze_1w' => '1 week',
    'snooze_1m' => '1 maand',
    'snooze_3m' => '3 maanden',
    'edit_name' => 'Naam bewerken',
    'edit_name_aria' => 'Terugkerende reeks :id hernoemen',
    'new_name_label' => 'Nieuwe naam voor deze reeks',
    'save' => 'Opslaan',

    'toast' => [
        'approved' => 'Goedgekeurd',
        'rejected' => 'Afgewezen',
        'snoozed' => 'Uitgesteld',
        'renamed' => 'Hernoemd',
        'un_rejected' => 'Afwijzing ongedaan gemaakt',
        'bulk_approved' => ':count goedgekeurd',
        'bulk_rejected' => ':count afgewezen',
    ],
];
