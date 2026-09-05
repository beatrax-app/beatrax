<?php

declare(strict_types=1);

return [
    'page_title' => 'Forhåndsvis import',
    'heading' => 'Forhåndsvis import',
    'discard' => 'Forkast importen',
    'confirm' => 'Bekreft importen',
    'subtitle' => 'Se gjennom de innleste radene. Ingenting lagres blant transaksjonene dine før du bekrefter.',

    'already_imported' => 'Denne filen er allerede importert.',

    'already_imported_link' => 'Se importresultatet',

    'expired_html' => 'Forhåndsvisningen er utløpt. <a href="/imports/new" class="underline">Last opp filen på nytt</a> for å prøve igjen.',
    'unreadable_html' => 'Forhåndsvisningen kan ikke leses. <a href="/imports/new" class="underline">Last opp filen på nytt</a> for å prøve igjen.',

    'save_name' => 'Lagre navnet',
    'account_name_label' => 'Kontonavn',
    'account_placeholder' => 'f.eks. Sparekonto',
    'rename_aria' => 'Gi denne motparten nytt navn',

    'unknown_iban_prefix' => 'Vi fant et ukjent IBAN:',

    'unknown_account_prefix' => 'Vi fant en ukjent konto:',
    'unknown_iban_suffix' => 'Gi denne kontoen et navn.',

    'ics' => [
        'name' => 'ICS-kort',
        'heading' => 'Gi ICS-kortkontoen din et navn.',
        'help' => 'Dette er første gang du importerer ICS-data. Gi dette kortet et navn, så det vises likt i hele appen.',
        'placeholder' => 'f.eks. ICS-kort',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Gi PayPal-kontoen din et navn.',
        'help' => 'Dette er første gang du importerer PayPal-data. Gi denne lommeboken et navn, så den vises likt i hele appen.',
        'placeholder' => 'f.eks. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Gi Google Play-kontoen din et navn.',
        'help' => 'Dette er første gang du importerer en Google Play-kvittering. Gi denne kontoen et navn, så den vises likt i hele appen.',
        'placeholder' => 'f.eks. Google Play',
    ],

    'col_date' => 'Dato',
    'col_funding_source' => 'Finansieringskilde',
    'col_counterparty' => 'Motpart',
    'col_amount' => 'Beløp',
    'col_status' => 'Status',

    'status' => [
        'new' => 'Ny',
        'new_title' => 'Blir lagt til blant transaksjonene dine.',
        'duplicate' => 'Duplikat',
        'duplicate_title' => 'Allerede importert — hoppes over.',
        'enriched' => 'Beriket',
        'enriched_title' => 'Eksisterende rad oppdateres med en sterkere kildehenvisning.',
        'error' => 'Feil',
    ],

    'rows_shown' => 'Viste rader: :shown av :total',

    'show_more' => 'Vis flere rader',

    'errors' => [
        'app_locked' => 'Lås opp appen for å importere: krypteringsnøklene kan ikke brukes mens den er låst.',
        'archive_holds_one_message' => 'Denne filen er én e-postmelding, ikke et postkassearkiv, så lest som arkiv er det ingenting i den. Last den opp igjen med formatet E-postmelding.',
        'email_file_is_an_archive' => 'Denne filen er et postkassearkiv: den inneholder mer enn én melding, og lest som én melding ville bare den første blitt tatt. Last den opp igjen med formatet Postkassearkiv.',
        'file_stopped_short' => 'Overskriftsraden stemte, så formatet er riktig. Lesingen stoppet før slutten av filen. Én uleselig rad gjør det, og det samme gjør en fil som er for stor for denne enheten. Prøv en kortere periode.',
        'file_unreadable' => 'Denne filen kunne ikke leses.',
        'file_unreadable_detail' => 'Appen kunne ikke lese denne filen (:code). De fullstendige detaljene ligger i apploggen; oppgi denne koden hvis du melder fra om et problem.',
        'iban_not_in_preview' => 'Dette IBAN-nummeret er ikke en del av den gjeldende forhåndsvisningen.',
        'message_unreadable' => 'Denne meldingen kunne ikke leses, så den ble hoppet over.',
        'not_an_email_file' => 'Denne filen er verken en e-postmelding eller et postkassearkiv, så det er ingenting i den å lese som kvittering. Velg importtypen og formatet som passer filen din.',
        'pdf_has_no_text_layer' => 'Denne PDF-en inneholder ingen tekst — det er en skanning eller et bilde av en kontoutskrift, så det er ingenting å lese i den. Last ned selve kontoutskriften fra banken din, eller bruk en CSV-eksport i stedet.',
        'pdf_password_protected' => 'Denne PDF-en er passordbeskyttet, så ingen leser får åpnet den. Lagre en ubeskyttet kopi fra PDF-viseren din, og importer den.',
        'pdf_reader_unavailable' => 'Denne versjonen av appen har ingen PDF-leser i det hele tatt, så en PDF-kontoutskrift kan ikke åpnes her. Importer filen på en annen enhet, eller bruk en CSV-eksport fra banken din i stedet.',
        'row_belongs_to_another_statement' => 'Denne raden hører til en transaksjon i en annen kontoutskriftsfil. Importer den kontoutskriften også — de to leses sammen.',
        'row_unreadable' => 'Denne raden kunne ikke leses.',
        'row_unreadable_detail' => 'Appen kunne ikke lese denne raden (:code). De fullstendige detaljene ligger i apploggen; oppgi denne koden hvis du melder fra om et problem.',
        'unknown_account' => 'Denne raden hører til en konto du ikke har gitt navn ennå.',
    ],

    'refused' => [
        'accounts_to_name' => 'Denne filen venter på at du gir navn til kontoen radene hører til.',
        'file_did_not_read_in_full' => 'Denne filen kunne ikke leses helt til slutten.',
        'nothing_importable' => 'Ingenting i denne filen kan importeres.',
        'preview_expired' => 'Forhåndsvisningen av denne filen er for gammel til å lagres nå. Last den opp på nytt.',
    ],

    'receipts' => [
        'heading' => 'Denne filen ble lest som e-post',
        'saved' => 'Det den inneholdt, står nedenfor, og hver melding er lagret.',
        'none_imported' => 'Ingenting av dette ble en transaksjon, så ingenting ble lagt til blant transaksjonene dine.',
        'shown' => 'Viste meldinger: :shown av :total',
        'no_subject' => 'Uten emne',

        'state' => [
            'read' => 'Lest som en betaling — bekreft denne importen for å legge den til blant transaksjonene dine.',
            'not_a_payment' => 'Ikke en betaling. Denne meldingen varsler om noe i stedet for å bekrefte en betaling.',
            'unreadable' => 'Lagret. Appen leser kvitteringer fra denne avsenderen, men fant verken beløp, brukersted eller referanse i meldingen.',
            'unknown_sender' => 'Lagret. Appen leser ikke kvitteringer fra denne avsenderen, så den tok ingenting fra meldingen.',
        ],
    ],

    'failed' => [
        'heading' => 'Filen kunne ikke leses',
        'no_rows' => 'Det ble ikke funnet noen transaksjoner i filen, så det er ingenting å importere.',
        'nothing_read' => 'Ingenting i filen kunne leses som en transaksjon, så det er ingenting å importere.',
        'every_row' => 'Ingen rad i filen kunne leses, så det er ingenting å importere. Hver rad er listet nedenfor med årsaken.',
        'likely_cause' => 'Som regel passer ikke overskriftsraden med kilden du valgte. Sjekk bank og format på opplastingsskjermen, eller last ned kontoutskriften fra banken på nytt.',
        'truncated_heading' => 'Bare en del av filen kunne leses',
        'truncated' => 'Innlesingen stoppet midt i filen. Denne filen kan ikke importeres: hvis bare den leste delen lagres, mangler resten av perioden, uten at noe sier fra om det.',
        'truncated_action' => 'Last opp filen på nytt, eller hent en ny kopi av kontoutskriften fra banken din.',
        'some_rows' => 'Noen rader kunne ikke leses. De er merket nedenfor og hoppes over; bekrefter du, importeres resten.',
        'detail_label' => 'Hva parseren meldte:',
        'rows_read_label' => 'Leste rader',
        'rows_skipped_label' => 'Rader hoppet over',
    ],
];
