<?php

declare(strict_types=1);

return [
    'page_title' => 'Forhåndsvis import',

    'heading' => 'Forhåndsvis import',
    'subtitle' => 'Se gjennom hva som blir endret. Ingenting lagres før du bekrefter.',

    'stats' => [
        'category' => 'Kategorier',
        'account' => 'Kontoer',
        'payee' => 'Motparter',
        'transaction' => 'Transaksjoner',
        'budget' => 'Budsjettmåneder',
    ],

    'all_clean' => 'Alt ble tilknyttet rent — det er ingenting her du må ta stilling til.',

    'nothing_staged' => 'Denne eksporten inneholdt ingenting å importere — det er ingenting å bekrefte her.',

    'groups' => [
        'conflict' => 'Krever din avgjørelse',
        'extra' => 'Ikke importert',
    ],

    'keep_or_take_aria' => 'Behold lokal eller ta fra kilden for :label',
    'keep_local' => 'Behold lokal',
    'take_source' => 'Ta fra kilden',

    'footer_note' => 'Dette oppretter eller oppdaterer antallene ovenfor i kategoriene, budsjettene og transaksjonene dine.',
    'discard_button' => 'Forkast importen',
    'discard_confirm' => 'Vil du forkaste denne importen? Alt som er lest ut av eksportfilen din, blir slettet her, og for å få det tilbake må du laste opp og lese gjennom hele filen på nytt. Ingenting har havnet blant transaksjonene dine ennå.',
    'confirm_button' => 'Bekreft importen',
];
