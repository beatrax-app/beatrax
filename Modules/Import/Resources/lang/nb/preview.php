<?php

declare(strict_types=1);

return [
    'page_title' => 'Forhåndsvis import',
    'heading' => 'Forhåndsvis import',
    'discard' => 'Forkast importen',
    'confirm' => 'Bekreft importen',
    'subtitle' => 'Se gjennom de innleste radene. Ingenting lagres blant transaksjonene dine før du bekrefter.',

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
        'unknown_error' => 'det oppsto en ukjent feil',
        'open_horizon' => 'Åpne Horizon',
        'failed_suffix' => 'for å prøve igjen eller undersøke nærmere.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Dette IBAN-nummeret er ikke en del av den gjeldende forhåndsvisningen.',
    ],
];
