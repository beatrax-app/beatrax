<?php

declare(strict_types=1);

return [
    'title' => 'Gennemgå tilbagevendende',
    'subtitle' => 'Godkend, udsæt eller afvis genkendte forslag til tilbagevendende serier.',

    'tabs' => [
        'pending' => 'Afventer',
        'rejected' => 'Afviste',
        'cadence_changed' => 'Ændret interval',
    ],

    'bulk' => [
        'aria' => 'Massehandlinger',
        'selected' => ':count valgt',
        'approve' => 'Godkend :count',
        'reject' => 'Afvis :count',
    ],

    'empty' => [
        'heading' => 'Intet at gennemgå',
        'pending' => 'Forslag til tilbagevendende serier lander her, når genkendelsen finder stabile månedlige mønstre.',
        'rejected' => 'Afviste forslag vises her, så du kan hente dem tilbage, hvis du skifter mening.',
        'cadence_changed' => 'Godkendte serier, hvis interval er skiftet, dukker op her til fornyet gennemgang.',
    ],

    'next' => 'Næste',
    'cadence_changed_note' => 'interval ændret',
    'un_reject' => 'Fortryd afvisning',
    'approve' => 'Godkend',
    'approve_aria' => 'Godkend tilbagevendende serie :id',
    'reject' => 'Afvis',
    'reject_aria' => 'Afvis tilbagevendende serie :id',
    'snooze' => 'Udsæt',
    'snooze_aria' => 'Udsæt tilbagevendende serie :id',
    'snooze_1w' => '1 uge',
    'snooze_1m' => '1 måned',
    'snooze_3m' => '3 måneder',
    'edit_name' => 'Redigér navnet',
    'edit_name_aria' => 'Omdøb tilbagevendende serie :id',
    'new_name_label' => 'Nyt navn til denne serie',
    'save' => 'Gem',

    'toast' => [
        'approved' => 'Godkendt',
        'rejected' => 'Afvist',
        'snoozed' => 'Udsat',
        'renamed' => 'Navnet er ændret',
        'un_rejected' => 'Afvisningen er fortrudt',
        'bulk_approved' => ':count godkendt',
        'bulk_rejected' => ':count afvist',
    ],
];
