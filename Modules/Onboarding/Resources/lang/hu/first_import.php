<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Áttekintés és véglegesítés',
    'h1' => 'Nézd át, amit találtunk',

    'lede_across' => 'tranzakció',
    'source' => '{0} forrásból|[1,1] forrásból|[2,*] forrásból',
    'lede_confirm' => 'Erősítsd meg a nyitó egyenlegeket, majd véglegesíts.',

    'empty' => 'Még nincs mit átnézni. Húzz be egy számlakivonatot a korábbi lépéseknél, hogy itt lásd a tranzakcióidat.',

    'sb_eyebrow_label' => '🧮 NYITÓ EGYENLEGEK ·',
    'account_detected' => '{0} FELISMERT SZÁMLA|[1,1] FELISMERT SZÁMLA|[2,*] FELISMERT SZÁMLA',
    'sb_lede' => 'Felismertük az egyes számlák nyitó egyenlegét. Erősítsd meg vagy módosítsd, mielőtt véglegesítünk.',

    'txn' => '{0} tranzakció|[1,1] tranzakció|[2,*] tranzakció',
    'to_commit' => 'véglegesítendő ·',
    'already_imported' => 'már importálva',
    'commit_committing' => 'Véglegesítés…',
    'commit_count' => 'Minden véglegesítése (:count tranzakció) →',
    'commit_empty' => 'Minden véglegesítése (—) →',
    'skip' => 'Kihagyás egyelőre',

    'errors' => [
        'nothing_to_commit' => 'Nincs mit véglegesíteni.',
        'commit_failed' => 'Nem sikerült véglegesíteni a kivonataidat. Semmi nem változott — próbáld újra.',
    ],

    'section' => [
        'from_prefix' => 'INNEN: ',
        'from_bank' => 'A BANKSZÁMLAKIVONATODBÓL',
        'from_ics' => 'AZ ICS KÁRTYAKIVONATAIDBÓL',
        'from_paypal' => 'A PAYPALRÓL',
        'row' => '{0} SOR|[1,1] SOR|[2,*] SOR',
        'badge_ready' => '✓ KÉSZ',
        'badge_empty' => 'ÜRES',
        'badge_error' => 'ÚJRA FEL KELL TÖLTENI',
        'badge_filtered' => 'MÁR IMPORTÁLVA',
        'error_body' => 'Nem sikerült beolvasni a forrás összes fájlját. Próbálj másik fájlt →',
        'empty_body' => 'Ez a kivonat üres.',
        'filtered_body' => 'Ezt a kivonatot már máshol importáltuk — kihagytuk.',
        'col_date' => 'Dátum',
        'col_type' => 'Típus',
        'col_counterparty' => 'Partner',
        'col_amount' => 'Összeg',
        'load_more' => 'Továbbiak betöltése (még :remaining)',
        'rows_shown' => ':count sor látható',
    ],
];
