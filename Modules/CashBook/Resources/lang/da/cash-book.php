<?php

declare(strict_types=1);

return [
    'page_title' => 'Kassebog',
    'heading' => 'Kassebog',
    'intro' => 'Registrér kontantkøb og andre udgifter uden om banken manuelt. Manuelle posteringer indgår i den samme transaktionsliste som dine import — de kategoriseres, indgår i registreringen af tilbagevendende betalinger og tæller med i din måned.',

    'direction' => 'Retning',
    'expense' => 'Udgift',
    'income' => 'Indtægt',

    'amount' => 'Beløb (€)',
    'date' => 'Dato',
    'counterparty' => 'Modpart',
    'counterparty_placeholder' => 'f.eks. Bager',
    'category' => 'Kategori',
    'optional' => '(valgfrit)',
    'uncategorized' => 'Ikke kategoriseret',
    'note' => 'Note',

    'add_entry' => 'Tilføj postering',
    'manual_entries' => 'Manuelle posteringer',
    'no_entries' => 'Ingen manuelle posteringer endnu.',
    'delete_entry' => 'Slet postering',
    'delete' => 'Slet',
    'delete_confirm' => 'Slet denne postering?',
    'delete_keep' => 'Behold',

    'errors' => [
        'amount_positive' => 'Indtast et beløb større end nul.',
        'amount_too_large' => 'Beløbet er for stort. Tjek cifrene.',
        'invalid_date' => 'Indtast en gyldig dato.',
    ],

    'toast' => [
        'added' => 'Kontantposteringen er tilføjet.',
        'removed' => 'Kontantposteringen er fjernet.',
    ],
];
