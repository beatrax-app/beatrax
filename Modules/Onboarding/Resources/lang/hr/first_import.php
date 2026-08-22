<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Pregled i potvrda',
    'h1' => 'Pregledaj sve što smo pronašli',

    'lede_counts' => ':transactions iz :sources.',
    'source' => ':count izvora|:count izvora|:count izvora',
    'lede_confirm' => 'Potvrdi svoja početna stanja, a zatim potvrdi uvoz.',

    'empty' => 'Još nema ničega za pregled. Ispusti izvod u ranijim koracima da ovdje vidiš svoje transakcije.',

    'sb_eyebrow_label' => '🧮 POČETNA STANJA ·',
    'account_detected' => ':count PRONAĐEN RAČUN|:count PRONAĐENA RAČUNA|:count PRONAĐENIH RAČUNA',
    'sb_lede' => 'Otkrili smo početno stanje za svaki račun. Potvrdi ga ili uredi prije potvrde uvoza.',

    'txn' => ':count transakcija|:count transakcije|:count transakcija',
    'to_commit' => 'za potvrdu ·',
    'already_imported' => ':count već uvezeno|:count već uvezeno|:count već uvezeno',
    'commit_committing' => 'Potvrđivanje…',
    'commit_count' => 'Potvrdi sve (:count transakcija) →|Potvrdi sve (:count transakcije) →|Potvrdi sve (:count transakcija) →',
    'commit_empty' => 'Potvrdi sve (—) →',
    'skip' => 'Preskoči zasad',

    'errors' => [
        'nothing_to_commit' => 'Nema ničega za potvrdu.',
        'commit_failed' => 'Nismo mogli potvrditi tvoje izvode. Ništa nije promijenjeno — pokušaj ponovno.',
    ],

    'section' => [
        'from_prefix' => 'IZ ',
        'from_bank' => 'S TVOJEG BANKOVNOG IZVODA',
        'from_ics' => 'S TVOJIH ICS IZVODA KARTICE',
        'from_paypal' => 'IZ PAYPALA',
        'row' => ':count REDAK|:count RETKA|:count REDAKA',
        'badge_ready' => '✓ SPREMNO',
        'badge_empty' => 'PRAZNO',
        'badge_error' => 'TREBA PONOVNI PRIJENOS',
        'error_body' => 'Nismo mogli pročitati sve datoteke za ovaj izvor. Pokušaj s drugom datotekom →',
        'partial_body' => 'Dio ove datoteke nije bilo moguće pročitati i izostavljen je: :reason',
        'empty_body' => 'Ovaj izvod je prazan.',
        'col_date' => 'Datum',
        'col_type' => 'Vrsta',
        'col_counterparty' => 'Protustranka',
        'col_amount' => 'Iznos',
        'load_more' => 'Učitaj još (preostalo :remaining)',
        'rows_shown' => 'prikazan :count redak|prikazana :count retka|prikazano :count redaka',
    ],
];
