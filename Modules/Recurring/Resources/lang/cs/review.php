<?php

declare(strict_types=1);

return [
    'title' => 'Kontrola opakovaných plateb',
    'subtitle' => 'Schval, odlož nebo odmítni nalezené návrhy opakovaných plateb.',

    'tabs' => [
        'pending' => 'Čekající',
        'rejected' => 'Odmítnuté',
        'cadence_changed' => 'Změněná frekvence',
    ],

    'bulk' => [
        'aria' => 'Hromadné akce',
        'selected' => 'vybráno: :count',
        'approve' => 'Schválit (:count)',
        'reject' => 'Odmítnout (:count)',
    ],

    'empty' => [
        'heading' => 'Není co kontrolovat',
        'pending' => 'Návrhy opakovaných plateb sem přistanou, jakmile detektor najde stabilní měsíční shluky.',
        'rejected' => 'Odmítnuté návrhy se objeví tady, ať je můžeš vrátit zpět, když si to rozmyslíš.',
        'cadence_changed' => 'Schválené řady, kterým se změnila frekvence, se sem vrátí ke kontrole.',
    ],

    'next' => 'Příští',
    'cadence_changed_note' => 'změněná frekvence',

    'select_aria' => 'Vybrat opakovanou řadu :id',
    'un_reject' => 'Vrátit odmítnutí',
    'approve' => 'Schválit',
    'approve_aria' => 'Schválit opakovanou řadu :id',
    'reject' => 'Odmítnout',
    'reject_aria' => 'Odmítnout opakovanou řadu :id',
    'snooze' => 'Odložit',
    'snooze_aria' => 'Odložit opakovanou řadu :id',
    'snooze_1w' => '1 týden',
    'snooze_1m' => '1 měsíc',
    'snooze_3m' => '3 měsíce',
    'edit_name' => 'Upravit název',
    'edit_name_aria' => 'Přejmenovat opakovanou řadu :id',
    'new_name_label' => 'Nový název této řady',
    'save' => 'Uložit',

    'toast' => [
        'approved' => 'Schváleno',
        'rejected' => 'Odmítnuto',
        'snoozed' => 'Odloženo',
        'renamed' => 'Přejmenováno',
        'un_rejected' => 'Odmítnutí vráceno',
        'bulk_approved' => 'Schváleno: :count',
        'bulk_rejected' => 'Odmítnuto: :count',
    ],
];
