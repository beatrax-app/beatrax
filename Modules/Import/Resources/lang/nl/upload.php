<?php

declare(strict_types=1);

return [
    'page_title' => 'Afschrift uploaden',
    'heading' => 'Afschrift uploaden',
    'subtitle' => 'Voeg een bank-, kaart- of PayPal-export toe, of een e-mailbon.',
    'mime_hint' => 'Dat bestand lijkt geen ondersteunde afschrift-export. Voeg een bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, een kaartafschrift-PDF, een e-mailbericht (.eml) of een mailbox-archief (.mbox) toe.',

    'source_label' => 'Bron',
    'issuer_other_bank' => 'Andere bank (N26, Revolut, ING…)',
    'issuer_email_file' => 'E-mailbestand (.eml, .mbox)',

    'format_label' => 'Formaat',
    'file_label' => 'Bestand',
    'submit' => 'Afschrift uploaden',

    'formats' => [
        'activity_download' => 'Activiteitenoverzicht (CSV)',
        'email_message' => 'E-mailbericht (.eml)',
        'mailbox_archive' => 'Mailbox-archief (.mbox)',
        'ing_nl' => 'ING Nederland (CSV)',
    ],

    'errors' => [
        'file_max' => 'Dat bestand is te groot. Voeg een afschrift-export toe die onder de groottelimiet voor het gekozen formaat blijft.',
        'file_extensions' => 'Dat bestand lijkt geen ondersteunde afschrift-export. Voeg een bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, een kaartafschrift-PDF, een e-mailbericht (.eml) of een mailbox-archief (.mbox) toe.',
        'issuer_format' => 'De waarde :attribute is niet geldig voor de bron :source.',
        'process_failed' => 'Kon dit bestand niet verwerken (:class). De volledige fout staat in /dev/logs.',
    ],
];
