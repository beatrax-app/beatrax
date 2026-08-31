<?php

declare(strict_types=1);

return [
    'page_title' => 'Kassabok',
    'heading' => 'Kassabok',
    'intro' => 'Registrera kontantköp och andra utgifter utanför banken för hand. Manuella poster hamnar i samma transaktionslista som dina importer — de kategoriseras, kopplas till en motpart, ingår i detekteringen av återkommande betalningar och räknas med i din månad.',

    'direction' => 'Riktning',
    'expense' => 'Utgift',
    'income' => 'Inkomst',

    'amount' => 'Belopp (:symbol)',
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
    'delete_entry_caption' => 'Ta bort',
    'delete' => 'Ta bort',
    'delete_confirm' => 'Ta bort den här posten?',
    'delete_keep' => 'Behåll',

    'errors' => [
        'amount_positive' => 'Ange ett belopp större än noll.',
        'amount_too_large' => 'Beloppet är för stort. Kontrollera siffrorna.',
        'amount_unreadable' => 'Beloppet kunde inte läsas. Ange det med högst :decimals decimal, till exempel :example.|Beloppet kunde inte läsas. Ange det med högst :decimals decimaler, till exempel :example.',
        'amount_unreadable_whole' => 'Beloppet kunde inte läsas. Den här valutan har inga decimaler, så ange ett heltal, till exempel :example.',
        'invalid_date' => 'Ange ett giltigt datum.',
        'not_recorded' => 'Posten sparades inte. Försök lägga till den igen.',
    ],

    'toast' => [
        'added' => 'Kontantposten lades till.',
        'removed' => 'Kontantposten togs bort.',
        'reconciled_locked' => 'Den här transaktionen är avstämd. Häv avstämningen för att göra ändringar.',
    ],
];
