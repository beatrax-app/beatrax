<?php

declare(strict_types=1);

return [
    'page_title' => 'Kassabok',
    'heading' => 'Kassabok',
    'intro' => 'Registrer kontantkjøp og andre utgifter utenom banken manuelt. Manuelle poster havner i den samme transaksjonslisten som importene dine — de kategoriseres, knyttes til en motpart, inngår i gjenkjenningen av gjentakende betalinger og teller med i måneden din.',

    'direction' => 'Retning',
    'expense' => 'Utgift',
    'income' => 'Inntekt',

    'amount' => 'Beløp (:symbol)',
    'date' => 'Dato',
    'counterparty' => 'Motpart',
    'counterparty_placeholder' => 'f.eks. Bakeri',
    'category' => 'Kategori',
    'optional' => '(valgfritt)',
    'uncategorized' => 'Ikke kategorisert',
    'note' => 'Notat',

    'add_entry' => 'Legg til post',
    'manual_entries' => 'Manuelle poster',
    'no_entries' => 'Ingen manuelle poster ennå.',
    'delete_entry' => 'Slett post',
    'delete_entry_caption' => 'Slett',
    'delete' => 'Slett',
    'delete_confirm' => 'Slette denne posteringen?',
    'delete_keep' => 'Behold',

    'errors' => [
        'amount_positive' => 'Skriv inn et beløp større enn null.',
        'amount_too_large' => 'Beløpet er for stort. Sjekk sifrene.',
        'amount_unreadable' => 'Beløpet kunne ikke leses. Skriv det inn med høyst :decimals desimal, for eksempel :example.|Beløpet kunne ikke leses. Skriv det inn med høyst :decimals desimaler, for eksempel :example.',
        'amount_unreadable_whole' => 'Beløpet kunne ikke leses. Denne valutaen har ingen desimaler, så skriv inn et helt tall, for eksempel :example.',
        'invalid_date' => 'Skriv inn en gyldig dato.',
        'not_recorded' => 'Posten ble ikke lagret. Prøv å legge den til på nytt.',
    ],

    'toast' => [
        'added' => 'Kontantposten er lagt til.',
        'removed' => 'Kontantposten er fjernet.',
        'reconciled_locked' => 'Denne transaksjonen er avstemt. Opphev avstemmingen for å gjøre endringer.',
    ],
];
