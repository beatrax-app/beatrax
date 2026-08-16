<?php

declare(strict_types=1);

return [
    'page_title' => 'Importa priekšskatījums',
    'heading' => 'Importa priekšskatījums',
    'discard' => 'Atmest importu',
    'confirm' => 'Apstiprināt importu',
    'subtitle' => 'Pārskatiet nolasītās rindas. Nekas netiek saglabāts virsgrāmatā, kamēr neapstiprināt.',

    'expired_html' => 'Priekšskatījuma termiņš ir beidzies. <a href="/imports/new" class="underline">Augšupielādējiet failu vēlreiz</a>, lai mēģinātu no jauna.',

    'save_name' => 'Saglabāt nosaukumu',
    'account_name_label' => 'Konta nosaukums',
    'account_placeholder' => 'piem. Galvenais krājkonts',
    'rename_aria' => 'Pārdēvēt šo darījuma partneri',

    'unknown_iban_prefix' => 'Atradām nepazīstamu IBAN:',
    'unknown_iban_suffix' => 'Piešķiriet šim kontam nosaukumu.',

    'ics' => [
        'heading' => 'Piešķiriet nosaukumu savam ICS kartes kontam.',
        'help' => 'Šī ir pirmā reize, kad importējat ICS datus. Piešķiriet šai kartei nosaukumu, lai tā visā lietotnē parādītos vienādi.',
        'placeholder' => 'piem. ICS karte',
    ],

    'paypal' => [
        'heading' => 'Piešķiriet nosaukumu savam PayPal kontam.',
        'help' => 'Šī ir pirmā reize, kad importējat PayPal datus. Piešķiriet šim makam nosaukumu, lai tas visā lietotnē parādītos vienādi.',
        'placeholder' => 'piem. PayPal',
    ],

    'col_date' => 'Datums',
    'col_funding_source' => 'Finansējuma avots',
    'col_counterparty' => 'Darījuma partneris',
    'col_amount' => 'Summa',
    'col_status' => 'Statuss',

    'status' => [
        'new' => 'Jauns',
        'new_title' => 'Tiks pievienots jūsu virsgrāmatai.',
        'duplicate' => 'Dublikāts',
        'duplicate_title' => 'Jau importēts — tiks izlaists.',
        'enriched' => 'Papildināts',
        'enriched_title' => 'Esošā rinda tiks atjaunināta ar precīzāku avota atsauci.',
        'error' => 'Kļūda',
    ],

    'chain' => [
        'heading' => 'Nosaka ķēdes…',
        'pending' => 'Rindā. Ķēžu atrisinātājs sāks darbu drīzumā.',
        'running' => 'Saista finansējuma ķēdes un sadala konta izraksta norēķinus.',
        'failed_prefix' => 'Ķēžu noteikšana neizdevās:',
        'unknown_error' => 'radās nezināma kļūda',
        'open_horizon' => 'Atveriet Horizon',
        'failed_suffix' => 'lai mēģinātu vēlreiz vai pārbaudītu.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Šis IBAN nav daļa no pašreizējā priekšskatījuma.',
    ],
];
