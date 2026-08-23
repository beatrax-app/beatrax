<?php

declare(strict_types=1);

return [
    'title' => 'Verifică recurentele',
    'subtitle' => 'Aprobă, amână sau respinge sugestiile recurente detectate.',

    'tabs' => [
        'pending' => 'În așteptare',
        'rejected' => 'Respinse',
        'cadence_changed' => 'Frecvență schimbată',
    ],

    'bulk' => [
        'aria' => 'Acțiuni în masă',
        'selected' => ':count selectate',
        'approve' => 'Aprobă :count',
        'reject' => 'Respinge :count',
    ],

    'empty' => [
        'heading' => 'Nimic de verificat',
        'pending' => 'Sugestiile recurente apar aici pe măsură ce detectorul găsește grupări lunare stabile.',
        'rejected' => 'Sugestiile respinse apar aici, ca să le poți readuce dacă te răzgândești.',
        'cadence_changed' => 'Seriile aprobate cărora li s-a schimbat frecvența apar aici pentru o nouă verificare.',
    ],

    'next' => 'Următoarea',
    'cadence_changed_note' => 'frecvență schimbată',
    'un_reject' => 'Anulează respingerea',
    'approve' => 'Aprobă',
    'approve_aria' => 'Aprobă seria recurentă :id',
    'reject' => 'Respinge',
    'reject_aria' => 'Respinge seria recurentă :id',
    'snooze' => 'Amână',
    'snooze_aria' => 'Amână seria recurentă :id',
    'snooze_1w' => '1 săptămână',
    'snooze_1m' => '1 lună',
    'snooze_3m' => '3 luni',
    'edit_name' => 'Editează numele',
    'edit_name_aria' => 'Redenumește seria recurentă :id',
    'new_name_label' => 'Nume nou pentru această serie',
    'save' => 'Salvează',

    'toast' => [
        'approved' => 'Aprobată',
        'rejected' => 'Respinsă',
        'snoozed' => 'Amânată',
        'renamed' => 'Redenumită',
        'un_rejected' => 'Respingere anulată',
        'bulk_approved' => ':count aprobate',
        'bulk_rejected' => ':count respinse',
    ],
];
