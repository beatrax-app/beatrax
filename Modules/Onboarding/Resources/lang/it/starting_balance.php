<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 SALDO INIZIALE',
    'confirmed_aria' => 'confermato',
    'on_date' => 'al :date',

    'detected_h3' => 'Abbiamo rilevato che il tuo :label è partito da',
    'confirm' => 'Conferma',
    'edit' => 'Modifica',

    'conflict_h3' => 'Abbiamo visto due valori per questo conto — quale è quello giusto?',
    'conflict_legend' => 'Scegli un saldo iniziale',
    'conflict_from' => 'Da :source:',
    'conflict_helper' => 'Per impostazione predefinita usiamo la data più vecchia. Scegli quello giusto oppure modificalo a mano.',
    'edit_manually' => 'Modifica a mano',

    'editing_h3' => 'Modifica il saldo iniziale del tuo :label',
    'input_label' => 'SALDO INIZIALE',
    'minor_units' => '(unità minori)',
    'on_date_label' => 'IN DATA',
    'cancel' => 'Annulla',
    'save' => 'Salva',

    'change' => 'Cambia',

    'manual_h3' => 'Inserisci a mano il saldo iniziale del tuo :label',
    'manual_lede' => 'Non siamo riusciti a rilevare in automatico un saldo iniziale per questo conto. Inseriscilo a mano oppure salta.',

    'unknown_state' => 'Stato della scheda sconosciuto. Ricarica la procedura guidata.',

    'errors' => [
        'account_not_set' => 'Conto non impostato. Ricarica la procedura guidata.',
        'invalid_amount' => 'Inserisci un importo valido.',
        'amount_range' => 'Inserisci un importo compreso tra :min e :max.',
        'pick_date' => 'Scegli una data.',
        'pick_valid_date' => 'Scegli una data valida.',
        'future_date' => 'La data del saldo iniziale non può essere nel futuro.',
        'date_warning' => 'Questa data è successiva alla tua prima transazione importata (:date). La dashboard potrebbe mostrare transazioni precedenti a questa data.',
    ],
];
