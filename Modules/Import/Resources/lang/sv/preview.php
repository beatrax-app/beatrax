<?php

declare(strict_types=1);

return [
    'page_title' => 'Förhandsgranska import',
    'heading' => 'Förhandsgranska import',
    'discard' => 'Kasta importen',
    'confirm' => 'Bekräfta importen',
    'subtitle' => 'Granska de inlästa raderna. Ingenting sparas bland dina transaktioner förrän du bekräftar.',

    'already_imported' => 'Den här filen har redan importerats.',

    'already_imported_link' => 'Visa importresultatet',

    'expired_html' => 'Förhandsgranskningen har upphört att gälla. <a href="/imports/new" class="underline">Ladda upp filen igen</a> för att försöka på nytt.',

    'save_name' => 'Spara namnet',
    'account_name_label' => 'Kontonamn',
    'account_placeholder' => 't.ex. Huvudsparkonto',
    'rename_aria' => 'Byt namn på den här motparten',

    'unknown_iban_prefix' => 'Vi hittade ett okänt IBAN:',

    'unknown_account_prefix' => 'Vi hittade ett okänt konto:',
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
        'failed_detail' => 'detaljerna finns i jobbloggen',
        'open_horizon' => 'Öppna Horizon',
        'failed_suffix' => 'för att försöka igen eller undersöka närmare.',
    ],

    'errors' => [
        'app_locked' => 'Lås upp appen för att importera: krypteringsnycklarna kan inte användas medan den är låst.',
        'file_stopped_short' => 'Rubrikraden stämde, så formatet är rätt. Läsningen stannade före filens slut. En enda oläslig rad gör det, och det gör även en fil som är för stor för den här enheten. Prova en kortare period.',
        'file_unreadable' => 'Filen gick inte att läsa.',
        'iban_not_in_preview' => 'Det här IBAN-numret ingår inte i den aktuella förhandsgranskningen.',
        'pdf_reader_unavailable' => 'PDF-kontoutdrag kräver programmet pdftotext, som inte är installerat här. Importera filen på en dator som har det, eller använd en CSV-export från din bank i stället.',
        'row_unreadable' => 'Raden gick inte att läsa.',
        'unknown_account' => 'Raden hör till ett konto som du ännu inte har namngett.',
    ],

    'failed' => [
        'heading' => 'Filen gick inte att läsa',
        'no_rows' => 'Inga transaktioner hittades i filen, så det finns inget att importera.',
        'nothing_read' => 'Ingenting i filen gick att läsa som en transaktion, så det finns inget att importera.',
        'every_row' => 'Ingen rad i filen gick att läsa, så det finns inget att importera. Varje rad listas nedan med orsaken.',
        'likely_cause' => 'Oftast beror det på att rubrikraden inte stämmer med källan du valde. Kontrollera bank och format på uppladdningssidan, eller ladda ner kontoutdraget från banken igen.',
        'truncated_heading' => 'Bara en del av filen gick att läsa',
        'truncated' => 'Inläsningen stannade mitt i filen. Allt efter den punkten lästes inte och kommer inte att importeras.',
        'some_rows' => 'Vissa rader gick inte att läsa. De är markerade nedan och hoppas över; bekräftar du importeras resten.',
        'detail_label' => 'Vad tolken rapporterade:',
        'rows_read_label' => 'Lästa rader',
        'rows_skipped_label' => 'Överhoppade rader',
    ],
];
