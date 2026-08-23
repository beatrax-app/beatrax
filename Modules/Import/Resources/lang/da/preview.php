<?php

declare(strict_types=1);

return [
    'page_title' => 'Forhåndsvis import',
    'heading' => 'Forhåndsvis import',
    'discard' => 'Kassér importen',
    'confirm' => 'Bekræft importen',
    'subtitle' => 'Gennemgå de indlæste rækker. Intet gemmes blandt dine transaktioner, før du bekræfter.',

    'already_imported' => 'Denne fil er allerede importeret.',

    'already_imported_link' => 'Se importresultatet',

    'expired_html' => 'Forhåndsvisningen er udløbet. <a href="/imports/new" class="underline">Upload filen igen</a> for at prøve igen.',

    'save_name' => 'Gem navnet',
    'account_name_label' => 'Kontonavn',
    'account_placeholder' => 'f.eks. Opsparingskonto',
    'rename_aria' => 'Omdøb denne modpart',

    'unknown_iban_prefix' => 'Vi fandt et ukendt IBAN:',
    'unknown_iban_suffix' => 'Giv denne konto et navn.',

    'ics' => [
        'heading' => 'Giv din ICS-kortkonto et navn.',
        'help' => 'Det er første gang, du importerer ICS-data. Giv dette kort et navn, så det vises ensartet i hele appen.',
        'placeholder' => 'f.eks. ICS-kort',
    ],

    'paypal' => [
        'heading' => 'Giv din PayPal-konto et navn.',
        'help' => 'Det er første gang, du importerer PayPal-data. Giv denne wallet et navn, så den vises ensartet i hele appen.',
        'placeholder' => 'f.eks. PayPal',
    ],

    'col_date' => 'Dato',
    'col_funding_source' => 'Finansieringskilde',
    'col_counterparty' => 'Modpart',
    'col_amount' => 'Beløb',
    'col_status' => 'Status',

    'status' => [
        'new' => 'Ny',
        'new_title' => 'Bliver føjet til dine transaktioner.',
        'duplicate' => 'Dublet',
        'duplicate_title' => 'Allerede importeret — springes over.',
        'enriched' => 'Beriget',
        'enriched_title' => 'Eksisterende række opdateres med en stærkere kildehenvisning.',
        'error' => 'Fejl',
    ],

    'chain' => [
        'heading' => 'Løser kæder op…',
        'pending' => 'I kø. Kædeløseren starter om lidt.',
        'running' => 'Forbinder finansieringskæder og opdeler afregninger fra kontoudtoget.',
        'failed_prefix' => 'Opløsningen af kæder mislykkedes:',
        'failed_detail' => 'detaljerne står i joblogen',
        'open_horizon' => 'Åbn Horizon',
        'failed_suffix' => 'for at prøve igen eller undersøge nærmere.',
    ],

    'errors' => [
        'app_locked' => 'Lås appen op for at importere: krypteringsnøglerne kan ikke bruges, mens den er låst.',
        'file_unreadable' => 'Denne fil kunne ikke læses.',
        'iban_not_in_preview' => 'Dette IBAN indgår ikke i den aktuelle forhåndsvisning.',
        'row_unreadable' => 'Denne række kunne ikke læses.',
        'unknown_account' => 'Denne række hører til en konto, du endnu ikke har navngivet.',
    ],

    'failed' => [
        'heading' => 'Filen kunne ikke læses',
        'no_rows' => 'Der blev ikke fundet nogen transaktioner i filen, så der er intet at importere.',
        'nothing_read' => 'Intet i filen kunne læses som en transaktion, så der er intet at importere.',
        'every_row' => 'Ingen række i filen kunne læses, så der er intet at importere. Hver række er vist nedenfor med årsagen.',
        'likely_cause' => 'Som regel passer overskriftsrækken ikke til den kilde, du valgte. Tjek bank og format på upload-skærmen, eller hent kontoudtoget fra din bank igen.',
        'truncated_heading' => 'Kun en del af filen kunne læses',
        'truncated' => 'Læsningen stoppede midt i filen. Alt efter det punkt blev ikke læst og bliver ikke importeret.',
        'some_rows' => 'Nogle rækker kunne ikke læses. De er markeret nedenfor og springes over; bekræfter du, importeres resten.',
        'detail_label' => 'Hvad parseren meldte:',
        'rows_read_label' => 'Læste rækker',
        'rows_skipped_label' => 'Oversprungne rækker',
    ],
];
