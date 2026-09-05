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
    'unreadable_html' => 'Forhåndsvisningen kan ikke læses. <a href="/imports/new" class="underline">Upload filen igen</a> for at prøve igen.',

    'save_name' => 'Gem navnet',
    'account_name_label' => 'Kontonavn',
    'account_placeholder' => 'f.eks. Opsparingskonto',
    'rename_aria' => 'Omdøb denne modpart',

    'unknown_iban_prefix' => 'Vi fandt et ukendt IBAN:',

    'unknown_account_prefix' => 'Vi fandt en ukendt konto:',
    'unknown_iban_suffix' => 'Giv denne konto et navn.',

    'ics' => [
        'name' => 'ICS-kort',
        'heading' => 'Giv din ICS-kortkonto et navn.',
        'help' => 'Det er første gang, du importerer ICS-data. Giv dette kort et navn, så det vises ensartet i hele appen.',
        'placeholder' => 'f.eks. ICS-kort',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Giv din PayPal-konto et navn.',
        'help' => 'Det er første gang, du importerer PayPal-data. Giv denne wallet et navn, så den vises ensartet i hele appen.',
        'placeholder' => 'f.eks. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Giv din Google Play-konto et navn.',
        'help' => 'Det er første gang, du importerer en Google Play-kvittering. Giv denne konto et navn, så den vises ensartet i hele appen.',
        'placeholder' => 'f.eks. Google Play',
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

    'rows_shown' => 'Viste rækker: :shown af :total',

    'show_more' => 'Vis flere rækker',

    'errors' => [
        'app_locked' => 'Lås appen op for at importere: krypteringsnøglerne kan ikke bruges, mens den er låst.',
        'archive_holds_one_message' => 'Denne fil er én e-mail, ikke et postkassearkiv, så læst som et arkiv er der intet i den. Upload den igen med formatet sat til E-mail.',
        'email_file_is_an_archive' => 'Denne fil er et postkassearkiv: den indeholder mere end én meddelelse, og læst som én meddelelse ville kun den første blive taget. Upload den igen med formatet sat til Postkassearkiv.',
        'file_stopped_short' => 'Overskriftsrækken passede, så formatet er rigtigt. Læsningen stoppede før slutningen af filen. Det sker ved én ulæselig række, og også hvis filen er for stor til denne enhed. Prøv en kortere periode.',
        'file_unreadable' => 'Denne fil kunne ikke læses.',
        'file_unreadable_detail' => 'Appen kunne ikke læse denne fil (:code). De fulde oplysninger står i appens log; angiv denne kode, hvis du rapporterer et problem.',
        'iban_not_in_preview' => 'Dette IBAN indgår ikke i den aktuelle forhåndsvisning.',
        'not_an_email_file' => 'Denne fil er hverken en e-mail eller et postkassearkiv, så der er intet i den at læse som kvittering. Vælg den importtype og det format, der passer til din fil.',
        'pdf_has_no_text_layer' => 'Denne PDF indeholder ingen tekst — det er en scanning eller et foto af et kontoudtog, så der er intet at læse i den. Hent selve kontoudtoget hos din bank, eller brug en CSV-eksport i stedet.',
        'pdf_password_protected' => 'Denne PDF er beskyttet med adgangskode, så ingen læser kan åbne den. Gem en ubeskyttet kopi fra din PDF-fremviser, og importér den.',
        'pdf_reader_unavailable' => 'Denne version af appen har slet ingen PDF-læser, så et PDF-kontoudtog kan ikke åbnes her. Importér filen på en anden enhed, eller brug en CSV-eksport fra din bank i stedet.',
        'row_belongs_to_another_statement' => 'Denne række hører til en transaktion i en anden kontoudtogsfil. Importér også det kontoudtog — de to læses sammen.',
        'row_unreadable' => 'Denne række kunne ikke læses.',
        'row_unreadable_detail' => 'Appen kunne ikke læse denne række (:code). De fulde oplysninger står i appens log; angiv denne kode, hvis du rapporterer et problem.',
        'unknown_account' => 'Denne række hører til en konto, du endnu ikke har navngivet.',
    ],

    'receipts' => [
        'heading' => 'Denne fil blev læst som e-mail',
        'saved' => 'Hvad den indeholdt, står nedenfor, og hver meddelelse er gemt.',
        'none_imported' => 'Intet af det blev til en transaktion, så der blev ikke tilføjet noget blandt dine transaktioner.',
        'shown' => 'Viste meddelelser: :shown af :total',
        'no_subject' => 'Intet emne',

        'state' => [
            'read' => 'Læst som en betaling — bekræft denne import for at føje den til dine transaktioner.',
            'not_a_payment' => 'Ikke en betaling. Denne meddelelse varsler noget i stedet for at bekræfte en betaling.',
            'unreadable' => 'Gemt. Appen læser kvitteringer fra denne afsender, men fandt hverken beløb, forretning eller reference i meddelelsen.',
            'unknown_sender' => 'Gemt. Appen læser ikke kvitteringer fra denne afsender, så den tog intet fra meddelelsen.',
        ],
    ],

    'failed' => [
        'heading' => 'Filen kunne ikke læses',
        'no_rows' => 'Der blev ikke fundet nogen transaktioner i filen, så der er intet at importere.',
        'nothing_read' => 'Intet i filen kunne læses som en transaktion, så der er intet at importere.',
        'every_row' => 'Ingen række i filen kunne læses, så der er intet at importere. Hver række er vist nedenfor med årsagen.',
        'likely_cause' => 'Som regel passer overskriftsrækken ikke til den kilde, du valgte. Tjek bank og format på upload-skærmen, eller hent kontoudtoget fra din bank igen.',
        'truncated_heading' => 'Kun en del af filen kunne læses',
        'truncated' => 'Læsningen stoppede midt i filen. Denne fil kan ikke importeres: hvis kun den læste del gemmes, mangler resten af perioden, uden at noget siger det.',
        'truncated_action' => 'Upload filen igen, eller hent en ny kopi af kontoudtoget hos din bank.',
        'some_rows' => 'Nogle rækker kunne ikke læses. De er markeret nedenfor og springes over; bekræfter du, importeres resten.',
        'detail_label' => 'Hvad parseren meldte:',
        'rows_read_label' => 'Læste rækker',
        'rows_skipped_label' => 'Oversprungne rækker',
    ],
];
