<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Rivedi e conferma',
    'h1' => 'Rivedi tutto quello che abbiamo trovato',

    'lede_counts' => ':transactions da :sources.',
    'source' => ':count fonte|:count fonti',
    'lede_confirm' => 'Controlla i tuoi saldi iniziali, poi conferma tutto.',

    'empty' => "Non c'è ancora niente da rivedere. Trascina un estratto conto nei passaggi precedenti per vedere qui le tue transazioni.",

    'sb_eyebrow_label' => '🧮 SALDI INIZIALI ·',
    'account_detected' => ':count CONTO RILEVATO|:count CONTI RILEVATI',
    'sb_lede' => 'Abbiamo rilevato il saldo iniziale di ogni conto. Confermalo o modificalo prima di procedere.',

    'txn' => ':count transazione|:count transazioni',
    'to_commit' => 'da confermare ·',
    'already_imported' => ':count già importata|:count già importate',
    'commit_committing' => 'Conferma in corso…',
    'commit_count' => 'Conferma tutto (:count transazione) →|Conferma tutto (:count transazioni) →',
    'commit_empty' => 'Conferma tutto (—) →',
    'skip' => 'Salta per ora',

    'errors' => [
        'nothing_to_commit' => "Non c'è niente da confermare.",
        'commit_failed' => 'Non siamo riusciti a confermare i tuoi estratti conto. Non è stato modificato niente — riprova.',
    ],

    'section' => [
        'from_prefix' => 'DA ',
        'from_bank' => 'DAL TUO ESTRATTO CONTO BANCARIO',
        'from_ics' => 'DAI TUOI ESTRATTI CONTO CARTA ICS',
        'from_paypal' => 'DA PAYPAL',
        'row' => ':count RIGA|:count RIGHE',
        'badge_ready' => '✓ PRONTO',
        'badge_empty' => 'VUOTO',
        'badge_error' => 'DA RICARICARE',
        'error_body' => 'Non siamo riusciti a leggere tutti i file di questa fonte. Prova con un altro file →',
        'partial_body' => 'Una parte di questo file non è stata leggibile ed è stata esclusa: :reason',
        'empty_body' => 'Questo estratto conto è vuoto.',
        'col_date' => 'Data',
        'col_type' => 'Tipo',
        'col_counterparty' => 'Controparte',
        'col_amount' => 'Importo',
        'load_more' => 'Carica altre (:remaining rimanenti)',
        'rows_shown' => ':count riga mostrata|:count righe mostrate',
    ],
];
