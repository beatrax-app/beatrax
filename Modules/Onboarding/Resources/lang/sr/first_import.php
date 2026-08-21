<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Pregled i potvrda',
    'h1' => 'Pregledaj sve što smo pronašli',

    'lede_across' => 'transakcija iz',
    'source' => 'izvor|izvora|izvora',
    'lede_confirm' => 'Potvrdi svoja početna stanja, pa potvrdi uvoz.',

    'empty' => 'Još nema ničega za pregled. Prevuci izvod u ranijim koracima da ovde vidiš svoje transakcije.',

    'sb_eyebrow_label' => '🧮 POČETNA STANJA ·',
    'account_detected' => 'PRONAĐEN RAČUN|PRONAĐENA RAČUNA|PRONAĐENIH RAČUNA',
    'sb_lede' => 'Otkrili smo početno stanje za svaki račun. Potvrdi ga ili izmeni pre potvrde uvoza.',

    'txn' => 'transakcija|transakcije|transakcija',
    'to_commit' => 'za potvrdu ·',
    'already_imported' => 'već uvezeno',
    'commit_committing' => 'Potvrđivanje…',
    'commit_count' => 'Potvrdi sve (:count transakcija) →|Potvrdi sve (:count transakcije) →|Potvrdi sve (:count transakcija) →',
    'commit_empty' => 'Potvrdi sve (—) →',
    'skip' => 'Preskoči zasad',

    'errors' => [
        'nothing_to_commit' => 'Nema ničega za potvrdu.',
        'commit_failed' => 'Nismo mogli da potvrdimo tvoje izvode. Ništa nije promenjeno — probaj ponovo.',
    ],

    'section' => [
        'from_prefix' => 'IZ ',
        'from_bank' => 'SA TVOG BANKOVNOG IZVODA',
        'from_ics' => 'SA TVOJIH ICS IZVODA KARTICE',
        'from_paypal' => 'IZ PAYPALA',
        'row' => 'RED|REDA|REDOVA',
        'badge_ready' => '✓ SPREMNO',
        'badge_empty' => 'PRAZNO',
        'badge_error' => 'TREBA PONOVNA OTPREMA',
        'badge_filtered' => 'VEĆ UVEZENO',
        'error_body' => 'Nismo mogli da pročitamo sve datoteke za ovaj izvor. Probaj sa drugom datotekom →',
        'partial_body' => 'Deo ovog fajla nije bilo moguće pročitati i izostavljen je: :reason',
        'empty_body' => 'Ovaj izvod je prazan.',
        'filtered_body' => 'Ovaj izvod je već uvezen na drugom mestu — izostavili smo ga.',
        'col_date' => 'Datum',
        'col_type' => 'Tip',
        'col_counterparty' => 'Druga strana',
        'col_amount' => 'Iznos',
        'load_more' => 'Učitaj još (preostalo :remaining)',
        'rows_shown' => 'prikazan :count red|prikazana :count reda|prikazano :count redova',
    ],
];
