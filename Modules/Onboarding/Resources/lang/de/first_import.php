<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Prüfen & übernehmen',
    'h1' => 'Prüfe alles, was wir gefunden haben',

    'lede_across' => 'Transaktionen aus',
    'source' => 'Quelle|Quellen',
    'lede_confirm' => 'Bestätige deine Anfangssalden und übernimm dann alles.',

    'empty' => 'Noch nichts zu prüfen. Zieh in den vorherigen Schritten einen Kontoauszug hierher, um deine Transaktionen hier zu sehen.',

    'sb_eyebrow_label' => '🧮 ANFANGSSALDEN ·',
    'account_detected' => 'KONTO ERKANNT|KONTEN ERKANNT',
    'sb_lede' => 'Wir haben den Anfangssaldo für jedes Konto erkannt. Bestätige oder bearbeite ihn, bevor wir übernehmen.',

    'txn' => 'Transaktion|Transaktionen',
    'to_commit' => 'zum Übernehmen ·',
    'already_imported' => 'bereits importiert',
    'commit_committing' => 'Wird übernommen…',
    'commit_count' => 'Alles übernehmen (:count Transaktion) →|Alles übernehmen (:count Transaktionen) →',
    'commit_empty' => 'Alles übernehmen (—) →',
    'skip' => 'Vorerst überspringen',

    'errors' => [
        'nothing_to_commit' => 'Nichts zu übernehmen.',
        'commit_failed' => 'Wir konnten deine Kontoauszüge nicht übernehmen. Es wurde nichts geändert — versuche es erneut.',
    ],

    'section' => [
        'from_prefix' => 'VON ',
        'from_bank' => 'VON DEINEM KONTOAUSZUG',
        'from_ics' => 'VON DEINEN ICS-KARTENABRECHNUNGEN',
        'from_paypal' => 'VON PAYPAL',
        'row' => 'ZEILE|ZEILEN',
        'badge_ready' => '✓ BEREIT',
        'badge_empty' => 'LEER',
        'badge_error' => 'MUSS NEU HOCHGELADEN WERDEN',
        'error_body' => 'Wir konnten nicht alle Dateien dieser Quelle lesen. Versuch eine andere Datei →',
        'partial_body' => 'Ein Teil dieser Datei konnte nicht gelesen werden und wurde weggelassen: :reason',
        'empty_body' => 'Dieser Kontoauszug ist leer.',
        'col_date' => 'Datum',
        'col_type' => 'Typ',
        'col_counterparty' => 'Zahlungspartner',
        'col_amount' => 'Betrag',
        'load_more' => 'Mehr laden (:remaining übrig)',
        'rows_shown' => ':count Zeile angezeigt|:count Zeilen angezeigt',
    ],
];
