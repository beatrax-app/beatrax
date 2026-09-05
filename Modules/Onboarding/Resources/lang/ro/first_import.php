<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Verificare și finalizare',
    'h1' => 'Verifică tot ce am găsit',

    'lede_counts' => ':transactions din :sources.',
    'source' => ':count sursă|:count surse|:count de surse',
    'lede_confirm' => 'Confirmă soldurile inițiale, apoi finalizează.',

    'empty' => 'Încă nu e nimic de verificat. Trage un extras de cont la pașii anteriori ca să îți vezi tranzacțiile aici.',

    'sb_eyebrow_label' => '🧮 SOLDURI INIȚIALE ·',
    'account_detected' => ':count CONT DETECTAT|:count CONTURI DETECTATE|:count DE CONTURI DETECTATE',
    'sb_lede' => 'Am detectat soldul inițial pentru fiecare cont. Confirmă-l sau modifică-l înainte să finalizăm.',

    'txn' => ':count tranzacție|:count tranzacții|:count de tranzacții',
    'to_commit' => 'de finalizat ·',
    'already_imported' => ':count deja importată|:count deja importate|:count de deja importate',
    'commit_committing' => 'Se finalizează…',
    'commit_count' => 'Finalizează tot (:count tranzacție) →|Finalizează tot (:count tranzacții) →|Finalizează tot (:count de tranzacții) →',
    'commit_empty' => 'Finalizează tot (—) →',
    'skip' => 'Omite deocamdată',

    'errors' => [
        'nothing_to_commit' => 'Nu e nimic de finalizat.',
        'commit_failed' => 'Nu am putut finaliza extrasele tale. Nu s-a schimbat nimic — încearcă din nou.',
    ],

    'section' => [
        'from_prefix' => 'DIN ',
        'from_bank' => 'DIN EXTRASUL TĂU DE CONT',
        'from_ics' => 'DIN EXTRASELE TALE DE CARD ICS',
        'from_paypal' => 'DIN PAYPAL',
        'row' => ':count RÂND|:count RÂNDURI|:count DE RÂNDURI',
        'badge_ready' => '✓ GATA',
        'badge_empty' => 'GOL',
        'badge_error' => 'NECESITĂ REÎNCĂRCARE',
        'error_body' => 'Nu am putut citi toate fișierele acestei surse. Încearcă alt fișier →',
        'left_out' => 'Un fișier de aici a fost omis, așa că se va salva doar restul: :reason|:count fișiere de aici au fost omise, așa că se va salva doar restul: :reason|:count de fișiere de aici au fost omise, așa că se va salva doar restul: :reason',
        'rows_skipped' => 'Unele rânduri de aici nu au putut fi citite și vor fi omise: :reason',
        'empty_body' => 'Acest extras este gol.',
        'col_date' => 'Dată',
        'col_type' => 'Tip',
        'col_counterparty' => 'Contraparte',
        'col_amount' => 'Sumă',
        'load_more' => 'Încarcă mai multe (au rămas :remaining)',
        'rows_shown' => ':count rând afișat|:count rânduri afișate|:count de rânduri afișate',
    ],
];
