<?php

declare(strict_types=1);

return [
    'page_title' => 'Import voorvertonen',
    'heading' => 'Import voorvertonen',
    'discard' => 'Import weggooien',
    'confirm' => 'Import bevestigen',
    'subtitle' => 'Bekijk de ingelezen regels. Er wordt niets in je grootboek opgeslagen totdat je bevestigt.',

    'expired_html' => 'De voorvertoning is verlopen. <a href="/imports/new" class="underline">Upload het bestand opnieuw</a> om het nog eens te proberen.',

    'save_name' => 'Naam opslaan',
    'account_name_label' => 'Rekeningnaam',
    'account_placeholder' => 'bijv. Spaarrekening',
    'rename_aria' => 'Deze tegenpartij hernoemen',

    'unknown_iban_prefix' => 'We vonden een onbekend IBAN:',
    'unknown_iban_suffix' => 'Geef deze rekening een naam.',

    'ics' => [
        'heading' => 'Geef je ICS-kaartrekening een naam.',
        'help' => 'Dit is de eerste keer dat je ICS-gegevens importeert. Geef deze kaart een naam zodat hij overal in de app consistent verschijnt.',
        'placeholder' => 'bijv. ICS-kaart',
    ],

    'paypal' => [
        'heading' => 'Geef je PayPal-rekening een naam.',
        'help' => 'Dit is de eerste keer dat je PayPal-gegevens importeert. Geef deze wallet een naam zodat hij overal in de app consistent verschijnt.',
        'placeholder' => 'bijv. PayPal',
    ],

    'col_date' => 'Datum',
    'col_funding_source' => 'Financieringsbron',
    'col_counterparty' => 'Tegenpartij',
    'col_amount' => 'Bedrag',
    'col_status' => 'Status',

    'status' => [
        'new' => 'Nieuw',
        'new_title' => 'Wordt aan je grootboek toegevoegd.',
        'duplicate' => 'Duplicaat',
        'duplicate_title' => 'Al geïmporteerd — wordt overgeslagen.',
        'enriched' => 'Verrijkt',
        'enriched_title' => 'Bestaande regel wordt bijgewerkt met een sterkere bronverwijzing.',
        'error' => 'Fout',
    ],

    'chain' => [
        'heading' => 'Ketens oplossen…',
        'pending' => 'In wachtrij. De keten-oplosser start zo dadelijk.',
        'running' => 'Financieringsketens koppelen en afschriftverrekeningen ontleden.',
        'failed_prefix' => 'Keten oplossen mislukt:',
        'unknown_error' => 'er is een onbekende fout opgetreden',
        'open_horizon' => 'Horizon openen',
        'failed_suffix' => 'om opnieuw te proberen of te inspecteren.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Dit IBAN maakt geen deel uit van de huidige voorvertoning.',
    ],
];
