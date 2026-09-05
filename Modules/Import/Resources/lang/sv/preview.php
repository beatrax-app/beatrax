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
    'unreadable_html' => 'Förhandsgranskningen går inte att läsa. <a href="/imports/new" class="underline">Ladda upp filen igen</a> för att försöka på nytt.',

    'save_name' => 'Spara namnet',
    'account_name_label' => 'Kontonamn',
    'account_placeholder' => 't.ex. Huvudsparkonto',
    'rename_aria' => 'Byt namn på den här motparten',

    'unknown_iban_prefix' => 'Vi hittade ett okänt IBAN:',

    'unknown_account_prefix' => 'Vi hittade ett okänt konto:',
    'unknown_iban_suffix' => 'Ge det här kontot ett namn.',

    'ics' => [
        'name' => 'ICS-kort',
        'heading' => 'Ge ditt ICS-kortkonto ett namn.',
        'help' => 'Det här är första gången du importerar ICS-data. Ge kortet ett namn så att det visas konsekvent i hela appen.',
        'placeholder' => 't.ex. ICS-kort',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Ge ditt PayPal-konto ett namn.',
        'help' => 'Det här är första gången du importerar PayPal-data. Ge den här plånboken ett namn så att den visas konsekvent i hela appen.',
        'placeholder' => 't.ex. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Ge ditt Google Play-konto ett namn.',
        'help' => 'Det här är första gången du importerar ett Google Play-kvitto. Ge det här kontot ett namn så att det visas konsekvent i hela appen.',
        'placeholder' => 't.ex. Google Play',
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

    'rows_shown' => 'Visade rader: :shown av :total',

    'show_more' => 'Visa fler rader',

    'errors' => [
        'app_locked' => 'Lås upp appen för att importera: krypteringsnycklarna kan inte användas medan den är låst.',
        'archive_holds_one_message' => 'Den här filen är ett enskilt e-postmeddelande, inte ett brevlådearkiv, så läst som arkiv finns det inget i den. Ladda upp den igen med formatet E-postmeddelande.',
        'email_file_is_an_archive' => 'Den här filen är ett brevlådearkiv: den innehåller fler än ett meddelande, och läst som ett enda meddelande skulle bara det första tas. Ladda upp den igen med formatet Brevlådearkiv.',
        'file_stopped_short' => 'Rubrikraden stämde, så formatet är rätt. Läsningen stannade före filens slut. En enda oläslig rad gör det, och det gör även en fil som är för stor för den här enheten. Prova en kortare period.',
        'file_unreadable' => 'Filen gick inte att läsa.',
        'file_unreadable_detail' => 'Appen kunde inte läsa den här filen (:code). De fullständiga uppgifterna finns i apploggen; ange den här koden om du rapporterar ett problem.',
        'iban_not_in_preview' => 'Det här IBAN-numret ingår inte i den aktuella förhandsgranskningen.',
        'message_unreadable' => 'Meddelandet gick inte att läsa, så det hoppades över.',
        'not_an_email_file' => 'Den här filen är varken ett e-postmeddelande eller ett brevlådearkiv, så det finns inget i den att läsa som kvitto. Välj den importtyp och det format som passar din fil.',
        'pdf_has_no_text_layer' => 'Den här PDF:en innehåller ingen text — det är en skanning eller ett foto av ett kontoutdrag, så det finns inget att läsa i den. Hämta själva kontoutdraget från din bank, eller använd en CSV-export i stället.',
        'pdf_password_protected' => 'Den här PDF:en är lösenordsskyddad, så ingen läsare kan öppna den. Spara en oskyddad kopia från din PDF-visare och importera den i stället.',
        'pdf_reader_unavailable' => 'Den här versionen av appen har ingen PDF-läsare alls, så ett PDF-kontoutdrag går inte att öppna här. Importera filen på en annan enhet, eller använd en CSV-export från din bank i stället.',
        'row_belongs_to_another_statement' => 'Den här raden hör till en transaktion i en annan kontoutdragsfil. Importera det kontoutdraget också — de två läses tillsammans.',
        'row_unreadable' => 'Raden gick inte att läsa.',
        'row_unreadable_detail' => 'Appen kunde inte läsa den här raden (:code). De fullständiga uppgifterna finns i apploggen; ange den här koden om du rapporterar ett problem.',
        'unknown_account' => 'Raden hör till ett konto som du ännu inte har namngett.',
    ],

    'refused' => [
        'accounts_to_name' => 'Den här filen väntar på att du namnger kontot som raderna hör till.',
        'file_did_not_read_in_full' => 'Den här filen gick inte att läsa hela vägen till slutet.',
        'nothing_importable' => 'Det finns inget i den här filen som går att importera.',
        'preview_expired' => 'Förhandsgranskningen av den här filen är för gammal för att sparas nu. Ladda upp den igen.',
    ],

    'receipts' => [
        'heading' => 'Den här filen lästes som e-post',
        'saved' => 'Vad den innehöll står nedan, och varje meddelande har sparats.',
        'none_imported' => 'Inget av det blev en transaktion, så inget lades till bland dina transaktioner.',
        'shown' => 'Visade meddelanden: :shown av :total',
        'no_subject' => 'Utan ämne',

        'state' => [
            'read' => 'Läst som en betalning — bekräfta den här importen för att lägga till den bland dina transaktioner.',
            'not_a_payment' => 'Ingen betalning. Det här meddelandet aviserar något i stället för att bekräfta en betalning.',
            'unreadable' => 'Sparat. Appen läser kvitton från den här avsändaren, men hittade varken belopp, handlare eller referens i meddelandet.',
            'unknown_sender' => 'Sparat. Appen läser inte kvitton från den här avsändaren, så den tog ingenting ur meddelandet.',
        ],
    ],

    'failed' => [
        'heading' => 'Filen gick inte att läsa',
        'no_rows' => 'Inga transaktioner hittades i filen, så det finns inget att importera.',
        'nothing_read' => 'Ingenting i filen gick att läsa som en transaktion, så det finns inget att importera.',
        'every_row' => 'Ingen rad i filen gick att läsa, så det finns inget att importera. Varje rad listas nedan med orsaken.',
        'likely_cause' => 'Oftast beror det på att rubrikraden inte stämmer med källan du valde. Kontrollera bank och format på uppladdningssidan, eller ladda ner kontoutdraget från banken igen.',
        'truncated_heading' => 'Bara en del av filen gick att läsa',
        'truncated' => 'Inläsningen stannade mitt i filen. Den här filen kan inte importeras: om bara den inlästa delen sparas saknas resten av perioden, utan att något säger det.',
        'truncated_action' => 'Ladda upp filen igen, eller hämta en ny kopia av kontoutdraget från din bank.',
        'some_rows' => 'Vissa rader gick inte att läsa. De är markerade nedan och hoppas över; bekräftar du importeras resten.',
        'detail_label' => 'Vad tolken rapporterade:',
        'rows_read_label' => 'Lästa rader',
        'rows_skipped_label' => 'Överhoppade rader',
    ],
];
