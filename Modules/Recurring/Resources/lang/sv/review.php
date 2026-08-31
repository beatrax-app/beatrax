<?php

declare(strict_types=1);

return [
    'title' => 'Granska återkommande',
    'subtitle' => 'Godkänn, skjut upp eller avvisa identifierade förslag på återkommande serier.',

    'tabs' => [
        'pending' => 'Väntande',
        'rejected' => 'Avvisade',
        'cadence_changed' => 'Ändrat intervall',
    ],

    'bulk' => [
        'aria' => 'Massåtgärder',
        'selected' => ':count valda',
        'approve' => 'Godkänn :count',
        'reject' => 'Avvisa :count',
    ],

    'empty' => [
        'heading' => 'Inget att granska',
        'pending' => 'Förslag på återkommande serier hamnar här när identifieringen hittar stabila månadsmönster.',
        'rejected' => 'Avvisade förslag visas här så att du kan ta tillbaka dem om du ändrar dig.',
        'cadence_changed' => 'Godkända serier vars intervall har ändrats dyker upp här för ny granskning.',
    ],

    'next' => 'Nästa',
    'overdue' => 'Försenat',
    'cadence_changed_note' => 'intervallet ändrat',
    'un_reject' => 'Ångra avvisning',
    'approve' => 'Godkänn',
    'approve_aria' => 'Godkänn återkommande serie :id',
    'reject' => 'Avvisa',
    'reject_aria' => 'Avvisa återkommande serie :id',
    'snooze' => 'Skjut upp',
    'snooze_aria' => 'Skjut upp återkommande serie :id',
    'snooze_1w' => '1 vecka',
    'snooze_1m' => '1 månad',
    'snooze_3m' => '3 månader',
    'edit_name' => 'Redigera namnet',
    'edit_name_aria' => 'Byt namn på återkommande serie :id',
    'new_name_label' => 'Nytt namn för den här serien',
    'load_more' => 'Ladda fler',
    'save' => 'Spara',

    'toast' => [
        'approved' => 'Godkänd',
        'rejected' => 'Avvisad',
        'snoozed' => 'Uppskjuten',
        'renamed' => 'Namnet ändrat',
        'un_rejected' => 'Avvisningen ångrad',
        'bulk_approved' => ':count godkända',
        'bulk_rejected' => ':count avvisade',
    ],
];
