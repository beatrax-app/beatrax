<?php

declare(strict_types=1);

return [
    'title' => 'Gjennomgå gjentakende',
    'subtitle' => 'Godkjenn, utsett eller avvis forslag til gjentakende serier som er funnet.',

    'tabs' => [
        'pending' => 'Venter',
        'rejected' => 'Avviste',
        'cadence_changed' => 'Endret intervall',
    ],

    'bulk' => [
        'aria' => 'Massehandlinger',
        'selected' => ':count valgt',
        'approve' => 'Godkjenn :count',
        'reject' => 'Avvis :count',
    ],

    'empty' => [
        'heading' => 'Ingenting å gjennomgå',
        'pending' => 'Forslag til gjentakende serier havner her når gjenkjenningen finner stabile månedlige mønstre.',
        'rejected' => 'Avviste forslag vises her, slik at du kan hente dem tilbake hvis du ombestemmer deg.',
        'cadence_changed' => 'Godkjente serier som har fått nytt intervall, dukker opp her for ny gjennomgang.',
    ],

    'next' => 'Neste',
    'cadence_changed_note' => 'intervallet endret',
    'un_reject' => 'Angre avvisning',
    'approve' => 'Godkjenn',
    'approve_aria' => 'Godkjenn gjentakende serie :id',
    'reject' => 'Avvis',
    'reject_aria' => 'Avvis gjentakende serie :id',
    'snooze' => 'Utsett',
    'snooze_aria' => 'Utsett gjentakende serie :id',
    'snooze_1w' => '1 uke',
    'snooze_1m' => '1 måned',
    'snooze_3m' => '3 måneder',
    'edit_name' => 'Rediger navnet',
    'edit_name_aria' => 'Gi gjentakende serie :id nytt navn',
    'new_name_label' => 'Nytt navn for denne serien',
    'save' => 'Lagre',

    'toast' => [
        'approved' => 'Godkjent',
        'rejected' => 'Avvist',
        'snoozed' => 'Utsatt',
        'renamed' => 'Navnet er endret',
        'un_rejected' => 'Avvisningen er angret',
        'bulk_approved' => ':count godkjent',
        'bulk_rejected' => ':count avvist',
    ],
];
