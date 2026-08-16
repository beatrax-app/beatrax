<?php

declare(strict_types=1);

return [
    'page_title' => 'Förhandsgranska import',
    'heading' => 'Förhandsgranska import',
    'discard' => 'Kasta importen',
    'confirm' => 'Bekräfta importen',
    'subtitle' => 'Granska de inlästa raderna. Ingenting sparas bland dina transaktioner förrän du bekräftar.',

    'expired_html' => 'Förhandsgranskningen har upphört att gälla. <a href="/imports/new" class="underline">Ladda upp filen igen</a> för att försöka på nytt.',

    'save_name' => 'Spara namnet',
    'account_name_label' => 'Kontonamn',
    'account_placeholder' => 't.ex. Huvudsparkonto',
    'rename_aria' => 'Byt namn på den här motparten',

    'unknown_iban_prefix' => 'Vi hittade ett okänt IBAN:',
    'unknown_iban_suffix' => 'Ge det här kontot ett namn.',

    'ics' => [
        'heading' => 'Ge ditt ICS-kortkonto ett namn.',
        'help' => 'Det här är första gången du importerar ICS-data. Ge kortet ett namn så att det visas konsekvent i hela appen.',
        'placeholder' => 't.ex. ICS-kort',
    ],

    'paypal' => [
        'heading' => 'Ge ditt PayPal-konto ett namn.',
        'help' => 'Det här är första gången du importerar PayPal-data. Ge den här plånboken ett namn så att den visas konsekvent i hela appen.',
        'placeholder' => 't.ex. PayPal',
    ],

    'col_date' => 'Datum',
    'col_funding_source' => 'Finansieringskälla',
    'col_counterparty' => 'Motpart',
    'col_amount' => 'Belopp',
    'col_status' => 'Status',

    'status' => [
        'new' => 'Ny',
        'new_title' => 'Läggs till bland dina transaktioner.',
        'duplicate' => 'Dubblett',
        'duplicate_title' => 'Redan importerad — hoppas över.',
        'enriched' => 'Berikad',
        'enriched_title' => 'Befintlig rad uppdateras med en starkare källhänvisning.',
        'error' => 'Fel',
    ],

    'chain' => [
        'heading' => 'Löser upp kedjor…',
        'pending' => 'I kö. Kedjelösaren startar snart.',
        'running' => 'Länkar finansieringskedjor och delar upp avräkningar från kontoutdraget.',
        'failed_prefix' => 'Kedjeupplösningen misslyckades:',
        'unknown_error' => 'ett okänt fel uppstod',
        'open_horizon' => 'Öppna Horizon',
        'failed_suffix' => 'för att försöka igen eller undersöka närmare.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Det här IBAN-numret ingår inte i den aktuella förhandsgranskningen.',
    ],
];
