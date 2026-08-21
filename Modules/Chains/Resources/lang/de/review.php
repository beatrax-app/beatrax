<?php

declare(strict_types=1);

return [
    'page_title' => 'Ketten prüfen',
    'heading' => 'Ketten prüfen',
    'hint' => ':count Hinweis|:count Hinweise',
    'subtitle' => 'Bestätige oder verwirf Kandidatenverknüpfungen, die der Ketten-Resolver nicht automatisch bestätigen konnte.',

    'empty_heading' => 'Nichts zu prüfen',
    'empty_body' => 'Jede Kettenverknüpfung ist bestätigt oder abgelehnt. Neue Kandidaten erscheinen hier, sobald Importe eintreffen.',

    'auto_confirm_nudge' => 'Noch eine Bestätigung und ähnliche Verknüpfungen werden automatisch bestätigt.',

    'confirm' => 'Bestätigen',
    'reject' => 'Ablehnen',
    'confirm_aria' => 'Kettenverknüpfung :id bestätigen',
    'reject_aria' => 'Kettenverknüpfung :id ablehnen',
    'show_more' => 'Mehr anzeigen',

    'kind' => [
        'paypal_funding' => 'PayPal-Finanzierung',
        'ics_bulk_settle' => 'iDEAL-Sammelabrechnung',
    ],

    'errors' => [
        'confirm_hint' => 'Dieser Kandidat ist ein Hinweis — öffne ihn und ordne die passende Transaktion zu, bevor du bestätigst.',
        'reject_hint' => 'Dieser Kandidat ist ein Hinweis — öffne ihn und ordne die passende Transaktion zu, bevor du ablehnst.',
    ],
];
