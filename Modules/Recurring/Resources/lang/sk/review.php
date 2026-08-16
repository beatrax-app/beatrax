<?php

declare(strict_types=1);

return [
    'title' => 'Kontrola opakovaných',
    'subtitle' => 'Schváľ, odlož alebo zamietni rozpoznané návrhy opakovaných platieb.',

    'tabs' => [
        'pending' => 'Čakajúce',
        'rejected' => 'Zamietnuté',
        'cadence_changed' => 'Zmenená frekvencia',
    ],

    'bulk' => [
        'aria' => 'Hromadné akcie',
        'selected' => 'Vybrané: :count',
        'approve' => 'Schváliť (:count)',
        'reject' => 'Zamietnuť (:count)',
    ],

    'empty' => [
        'heading' => 'Nie je čo kontrolovať',
        'pending' => 'Návrhy opakovaných platieb sa sem dostanú, keď detektor nájde stabilné mesačné zhluky.',
        'rejected' => 'Zamietnuté návrhy sa zobrazia tu — ak si to rozmyslíš, dajú sa vrátiť späť.',
        'cadence_changed' => 'Schválené série, ktorým sa zmenila frekvencia, sa sem dostanú na opätovnú kontrolu.',
    ],

    'next' => 'Ďalšia',
    'cadence_changed_note' => 'zmenená frekvencia',

    'select_aria' => 'Vybrať opakovanú sériu :id',
    'un_reject' => 'Zrušiť zamietnutie',
    'approve' => 'Schváliť',
    'approve_aria' => 'Schváliť opakovanú sériu :id',
    'reject' => 'Zamietnuť',
    'reject_aria' => 'Zamietnuť opakovanú sériu :id',
    'snooze' => 'Odložiť',
    'snooze_1w' => '1 týždeň',
    'snooze_1m' => '1 mesiac',
    'snooze_3m' => '3 mesiace',
    'edit_name' => 'Upraviť názov',
    'new_name_label' => 'Nový názov pre túto sériu',
    'save' => 'Uložiť',

    'toast' => [
        'approved' => 'Schválené',
        'rejected' => 'Zamietnuté',
        'snoozed' => 'Odložené',
        'renamed' => 'Premenované',
        'un_rejected' => 'Zamietnutie zrušené',
        'bulk_approved' => 'Schválené: :count',
        'bulk_rejected' => 'Zamietnuté: :count',
    ],
];
