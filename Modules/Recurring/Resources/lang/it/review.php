<?php

declare(strict_types=1);

return [
    'title' => 'Rivedi le ricorrenti',
    'subtitle' => 'Approva, posticipa o rifiuta i suggerimenti di ricorrenti rilevati.',

    'tabs' => [
        'pending' => 'In sospeso',
        'rejected' => 'Rifiutate',
        'cadence_changed' => 'Cadenza cambiata',
    ],

    'bulk' => [
        'aria' => 'Azioni di gruppo',
        'selected' => ':count selezionate',
        'approve' => 'Approva :count',
        'reject' => 'Rifiuta :count',
    ],

    'empty' => [
        'heading' => 'Niente da rivedere',
        'pending' => 'I suggerimenti di ricorrenti arrivano qui quando il rilevatore individua gruppi mensili stabili.',
        'rejected' => 'I suggerimenti rifiutati compaiono qui, così puoi recuperarli se cambi idea.',
        'cadence_changed' => 'Le serie approvate la cui cadenza è cambiata compaiono qui per una nuova revisione.',
    ],

    'next' => 'Prossimo',
    'cadence_changed_note' => 'cadenza cambiata',

    'select_aria' => 'Seleziona la serie ricorrente :id',
    'un_reject' => 'Annulla il rifiuto',
    'approve' => 'Approva',
    'approve_aria' => 'Approva la serie ricorrente :id',
    'reject' => 'Rifiuta',
    'reject_aria' => 'Rifiuta la serie ricorrente :id',
    'snooze' => 'Posticipa',
    'snooze_aria' => 'Posticipa la serie ricorrente :id',
    'snooze_1w' => '1 settimana',
    'snooze_1m' => '1 mese',
    'snooze_3m' => '3 mesi',
    'edit_name' => 'Modifica nome',
    'edit_name_aria' => 'Rinomina la serie ricorrente :id',
    'new_name_label' => 'Nuovo nome per questa serie',
    'save' => 'Salva',

    'toast' => [
        'approved' => 'Approvata',
        'rejected' => 'Rifiutata',
        'snoozed' => 'Posticipata',
        'renamed' => 'Rinominata',
        'un_rejected' => 'Rifiuto annullato',
        'bulk_approved' => ':count approvate',
        'bulk_rejected' => ':count rifiutate',
    ],
];
