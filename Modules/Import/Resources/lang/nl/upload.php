<?php

declare(strict_types=1);

return [
    'page_title' => 'Afschrift uploaden',
    'heading' => 'Afschrift uploaden',
    'migrate_prompt' => 'Overstappen van een andere budget-app?',
    'migrate_link' => 'Importeren uit YNAB of Actual',
    'subtitle' => 'Voeg een afschrift toe als CSV, CAMT.053, MT940 of PDF, of een e-mailbonbestand.',
    'mime_hint' => 'Ondersteunde bestanden: bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kaartafschrift-PDF, e-mailbericht (.eml) of mailbox-archief (.mbox).',

    'type_label' => 'Importtype',

    'types' => [
        'csv' => 'CSV-bestand',
        'camt053' => 'CAMT.053-afschrift (XML)',
        'mt940' => 'MT940-afschrift',
        'pdf' => 'Kaartafschrift (PDF)',
        'email' => 'E-mailbonbestand',
    ],

    'format_label' => 'Formaat',

    'format_from_file' => 'Het formaat is op :format gezet om bij het gekozen bestand te passen. Wijzig het als dat niet klopt.',
    'file_label' => 'Bestand',
    'submit' => 'Afschrift uploaden',

    'formats' => [
        'activity_download' => 'Activiteitenoverzicht (CSV)',
        'email_message' => 'E-mailbericht (.eml)',
        'mailbox_archive' => 'Mailbox-archief (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Dat bestand is te groot. Voeg een afschrift-export toe die onder de groottelimiet voor het gekozen formaat blijft.',
        'file_extensions' => 'Dat bestand lijkt geen ondersteunde afschrift-export. Voeg een bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, een kaartafschrift-PDF, een e-mailbericht (.eml) of een mailbox-archief (.mbox) toe.',
        'type_format' => 'De waarde :attribute is niet geldig voor importtype :type.',
        'process_failed' => 'Kon dit bestand niet verwerken (:class). De volledige fout staat in /dev/logs.',
    ],
];
