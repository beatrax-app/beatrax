<?php

declare(strict_types=1);

return [
    'page_title' => 'Kassabok',
    'heading' => 'Kassabok',
    'intro' => 'Registrera kontantköp och andra utgifter utanför banken för hand. Manuella poster hamnar i samma transaktionslista som dina importer — de kategoriseras, ingår i detekteringen av återkommande betalningar och räknas med i din månad.',

    'direction' => 'Riktning',
    'expense' => 'Utgift',
    'income' => 'Inkomst',

    'amount' => 'Belopp (€)',
    'date' => 'Datum',
    'counterparty' => 'Motpart',
    'counterparty_placeholder' => 't.ex. Bageri',
    'category' => 'Kategori',
    'optional' => '(valfritt)',
    'uncategorized' => 'Okategoriserat',
    'note' => 'Anteckning',

    'add_entry' => 'Lägg till post',
    'manual_entries' => 'Manuella poster',
    'no_entries' => 'Inga manuella poster än.',
    'delete_entry' => 'Ta bort post',
    'delete' => 'Ta bort',
    'delete_confirm' => 'Ta bort den här posten?',
    'delete_keep' => 'Behåll',

    'errors' => [
        'amount_positive' => 'Ange ett belopp större än noll.',
        'amount_too_large' => 'Beloppet är för stort. Kontrollera siffrorna.',
        'invalid_date' => 'Ange ett giltigt datum.',
    ],

    'toast' => [
        'added' => 'Kontantposten lades till.',
        'removed' => 'Kontantposten togs bort.',
    ],
];
