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

    'save_name' => 'Lagre navnet',
    'account_name_label' => 'Kontonavn',
    'account_placeholder' => 'f.eks. Sparekonto',
    'rename_aria' => 'Gi denne motparten nytt navn',

    'unknown_iban_prefix' => 'Vi fant et ukjent IBAN:',
    'unknown_iban_suffix' => 'Gi denne kontoen et navn.',

    'ics' => [
        'heading' => 'Gi ICS-kortkontoen din et navn.',
        'help' => 'Dette er første gang du importerer ICS-data. Gi dette kortet et navn, så det vises likt i hele appen.',
        'placeholder' => 'f.eks. ICS-kort',
    ],

    'paypal' => [
        'heading' => 'Gi PayPal-kontoen din et navn.',
        'help' => 'Dette er første gang du importerer PayPal-data. Gi denne lommeboken et navn, så den vises likt i hele appen.',
        'placeholder' => 'f.eks. PayPal',
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

    'chain' => [
        'heading' => 'Løser opp kjeder…',
        'pending' => 'I kø. Kjedeløseren starter snart.',
        'running' => 'Kobler sammen finansieringskjeder og deler opp oppgjør fra kontoutskriften.',
        'failed_prefix' => 'Oppløsningen av kjeder mislyktes:',
        'failed_detail' => 'detaljene står i jobbloggen',
        'open_horizon' => 'Åpne Horizon',
        'failed_suffix' => 'for å prøve igjen eller undersøke nærmere.',
    ],

    'errors' => [
        'app_locked' => 'Lås opp appen for å importere: krypteringsnøklene kan ikke brukes mens den er låst.',
        'file_unreadable' => 'Denne filen kunne ikke leses.',
        'iban_not_in_preview' => 'Dette IBAN-nummeret er ikke en del av den gjeldende forhåndsvisningen.',
        'pdf_reader_unavailable' => 'PDF-kontoutskrifter krever programmet pdftotext, som ikke er installert her. Importer filen på en datamaskin som har det, eller bruk en CSV-eksport fra banken din i stedet.',
        'row_unreadable' => 'Denne raden kunne ikke leses.',
        'unknown_account' => 'Denne raden hører til en konto du ikke har gitt navn ennå.',
    ],

    'failed' => [
        'heading' => 'Filen kunne ikke leses',
        'no_rows' => 'Det ble ikke funnet noen transaksjoner i filen, så det er ingenting å importere.',
        'nothing_read' => 'Ingenting i filen kunne leses som en transaksjon, så det er ingenting å importere.',
        'every_row' => 'Ingen rad i filen kunne leses, så det er ingenting å importere. Hver rad er listet nedenfor med årsaken.',
        'likely_cause' => 'Som regel passer ikke overskriftsraden med kilden du valgte. Sjekk bank og format på opplastingsskjermen, eller last ned kontoutskriften fra banken på nytt.',
        'truncated_heading' => 'Bare en del av filen kunne leses',
        'truncated' => 'Innlesingen stoppet midt i filen. Alt etter det punktet ble ikke lest og blir ikke importert.',
        'some_rows' => 'Noen rader kunne ikke leses. De er merket nedenfor og hoppes over; bekrefter du, importeres resten.',
        'detail_label' => 'Hva parseren meldte:',
        'rows_read_label' => 'Leste rader',
        'rows_skipped_label' => 'Rader hoppet over',
    ],
];
