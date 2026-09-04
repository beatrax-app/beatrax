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
    'unreadable_html' => 'Priekšskatījumu nevar nolasīt. <a href="/imports/new" class="underline">Augšupielādējiet failu vēlreiz</a>, lai mēģinātu no jauna.',

    'save_name' => 'Saglabāt nosaukumu',
    'account_name_label' => 'Konta nosaukums',
    'account_placeholder' => 'piem. Galvenais krājkonts',
    'rename_aria' => 'Pārdēvēt šo darījuma partneri',

    'unknown_iban_prefix' => 'Atradām nepazīstamu IBAN:',

    'unknown_account_prefix' => 'Atradām nepazīstamu kontu:',
    'unknown_iban_suffix' => 'Piešķiriet šim kontam nosaukumu.',

    'ics' => [
        'name' => 'ICS karte',
        'heading' => 'Piešķiriet nosaukumu savam ICS kartes kontam.',
        'help' => 'Šī ir pirmā reize, kad importējat ICS datus. Piešķiriet šai kartei nosaukumu, lai tā visā lietotnē parādītos vienādi.',
        'placeholder' => 'piem. ICS karte',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Piešķiriet nosaukumu savam PayPal kontam.',
        'help' => 'Šī ir pirmā reize, kad importējat PayPal datus. Piešķiriet šim makam nosaukumu, lai tas visā lietotnē parādītos vienādi.',
        'placeholder' => 'piem. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Piešķiriet nosaukumu savam Google Play kontam.',
        'help' => 'Šī ir pirmā reize, kad importējat Google Play čeku. Piešķiriet šim kontam nosaukumu, lai tas visā lietotnē parādītos vienādi.',
        'placeholder' => 'piem. Google Play',
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

    'rows_shown' => 'Rādītās rindas: :shown no :total',

    'show_more' => 'Rādīt vairāk rindu',

    'errors' => [
        'app_locked' => 'Atbloķējiet lietotni, lai importētu: šifrēšanas atslēgas nevar izmantot, kamēr tā ir bloķēta.',
        'archive_holds_one_message' => 'Šis fails ir viens e-pasta ziņojums, nevis pastkastes arhīvs, tāpēc, lasīts kā arhīvs, tajā nekā nav. Augšupielādē to vēlreiz ar formātu E-pasta ziņojums.',
        'email_file_is_an_archive' => 'Šis fails ir pastkastes arhīvs: tajā ir vairāk nekā viens ziņojums, un, lasīts kā viens ziņojums, tas paņemtu tikai pirmo. Augšupielādē to vēlreiz ar formātu Pastkastes arhīvs.',
        'file_stopped_short' => 'Galvenes rinda sakrita, tātad formāts ir pareizs. Lasīšana apstājās pirms faila beigām. To izraisa viena nenolasāma rinda, kā arī šai ierīcei pārāk liels fails. Pamēģini īsāku laikposmu.',
        'file_unreadable' => 'Šo failu neizdevās nolasīt.',
        'file_unreadable_detail' => 'Lietotne nevarēja nolasīt šo failu (:code). Pilnas ziņas ir lietotnes žurnālā; ziņojot par problēmu, norādiet šo kodu.',
        'iban_not_in_preview' => 'Šis IBAN nav daļa no pašreizējā priekšskatījuma.',
        'not_an_email_file' => 'Šis fails nav ne e-pasta ziņojums, ne pastkastes arhīvs, tāpēc tajā nav ko lasīt kā čeku. Izvēlies importa veidu un formātu, kas atbilst tavam failam.',
        'pdf_has_no_text_layer' => 'Šajā PDF nav teksta — tas ir izraksta skenējums vai fotoattēls, tāpēc tajā nav ko lasīt. Lejupielādē pašu izrakstu no savas bankas vai izmanto CSV eksportu.',
        'pdf_password_protected' => 'Šis PDF ir aizsargāts ar paroli, tāpēc to nevar atvērt neviens lasītājs. Saglabā PDF skatītājā neaizsargātu kopiju un importē to.',
        'pdf_reader_unavailable' => 'Šai lietotnes versijai nav nekāda PDF lasītāja, tāpēc PDF izrakstu šeit nevar atvērt. Importē šo failu citā ierīcē vai izmanto bankas CSV eksportu.',
        'row_belongs_to_another_statement' => 'Šī rinda pieder darījumam citā pārskata failā. Importējiet arī šo pārskatu — abi tiek lasīti kopā.',
        'row_unreadable' => 'Šo rindu neizdevās nolasīt.',
        'row_unreadable_detail' => 'Lietotne nevarēja nolasīt šo rindu (:code). Pilnas ziņas ir lietotnes žurnālā; ziņojot par problēmu, norādiet šo kodu.',
        'unknown_account' => 'Šī rinda pieder kontam, kuram vēl neesi devis nosaukumu.',
    ],

    'receipts' => [
        'heading' => 'Šis fails tika nolasīts kā e-pasts',
        'saved' => 'Kas tajā bija, ir uzskaitīts zemāk, un katra vēstule ir saglabāta.',
        'none_imported' => 'Nekas no tā nekļuva par darījumu, tāpēc virsgrāmatai netika pievienots nekas.',
        'shown' => 'Rādītās vēstules: :shown no :total',
        'no_subject' => 'Bez temata',

        'state' => [
            'read' => 'Nolasīts kā maksājums — apstipriniet šo importu, lai tas nonāktu virsgrāmatā.',
            'not_a_payment' => 'Tas nav maksājums. Šī vēstule kaut ko paziņo, nevis apstiprina maksājumu.',
            'unreadable' => 'Saglabāts. Lietotne nolasa šā sūtītāja čekus, taču šajā vēstulē neatrada ne summu, ne tirgotāju, ne atsauci.',
            'unknown_sender' => 'Saglabāts. Lietotne nenolasa šā sūtītāja čekus, tāpēc no vēstules neko nepaņēma.',
        ],
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
