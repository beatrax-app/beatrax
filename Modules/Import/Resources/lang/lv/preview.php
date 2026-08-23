<?php

declare(strict_types=1);

return [
    'page_title' => 'Importa priekšskatījums',
    'heading' => 'Importa priekšskatījums',
    'discard' => 'Atmest importu',
    'confirm' => 'Apstiprināt importu',
    'subtitle' => 'Pārskatiet nolasītās rindas. Nekas netiek saglabāts virsgrāmatā, kamēr neapstiprināt.',

    'already_imported' => 'Šis fails jau ir importēts.',

    'already_imported_link' => 'Skatīt importa rezultātu',


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
        'failed_detail' => 'sīkāka informācija ir darbu žurnālā',
        'open_horizon' => 'Atveriet Horizon',
        'failed_suffix' => 'lai mēģinātu vēlreiz vai pārbaudītu.',
    ],

    'errors' => [
        'app_locked' => 'Atbloķējiet lietotni, lai importētu: šifrēšanas atslēgas nevar izmantot, kamēr tā ir bloķēta.',
        'file_unreadable' => 'Šo failu neizdevās nolasīt.',
        'iban_not_in_preview' => 'Šis IBAN nav daļa no pašreizējā priekšskatījuma.',
        'row_unreadable' => 'Šo rindu neizdevās nolasīt.',
        'unknown_account' => 'Šī rinda pieder kontam, kuram vēl neesi devis nosaukumu.',
    ],

    'failed' => [
        'heading' => 'Šo failu neizdevās nolasīt',
        'no_rows' => 'Šajā failā netika atrasti darījumi, tāpēc nav ko importēt.',
        'nothing_read' => 'Neko šajā failā neizdevās nolasīt kā darījumu, tāpēc nav ko importēt.',
        'every_row' => 'Nevienu šā faila rindu neizdevās nolasīt, tāpēc nav ko importēt. Katra rinda ar iemeslu ir uzskaitīta zemāk.',
        'likely_cause' => 'Parasti galvenes rinda neatbilst izvēlētajam avotam. Pārbaudi banku un formātu augšupielādes ekrānā vai lejupielādē konta pārskatu no savas bankas vēlreiz.',
        'truncated_heading' => 'No šā faila izdevās nolasīt tikai daļu',
        'truncated' => 'Lasīšana apstājās faila vidū. Viss pēc tā netika nolasīts un netiks importēts.',
        'some_rows' => 'Dažas rindas neizdevās nolasīt. Tās ir atzīmētas zemāk un tiks izlaistas; apstiprinot tiek importēts pārējais.',
        'detail_label' => 'Ko ziņoja parsers:',
        'rows_read_label' => 'Nolasītās rindas',
        'rows_skipped_label' => 'Izlaistās rindas',
    ],
];
