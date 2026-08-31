<?php

declare(strict_types=1);

return [
    'page_title' => 'Carica estratto conto',
    'heading' => 'Carica estratto conto',
    'migrate_prompt' => "Arrivi da un'altra app di budget?",
    'migrate_link' => 'Importa da YNAB o Actual',
    'subtitle' => 'Trascina qui un estratto conto in CSV, CAMT.053, MT940 o PDF, oppure un file di ricevuta email.',
    'mime_hint' => 'File supportati: CSV bancario, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF dell\'estratto conto della carta, messaggio e-mail (.eml) o archivio della casella di posta (.mbox).',

    'type_label' => 'Tipo di importazione',

    'types' => [
        'csv' => 'File CSV',
        'camt053' => 'Estratto conto CAMT.053 (XML)',
        'mt940' => 'Estratto conto MT940',
        'pdf' => 'Estratto conto della carta (PDF)',
        'email' => 'File di ricevuta email',
    ],

    'format_label' => 'Formato',

    'format_from_file' => 'Il formato è stato impostato su :format per corrispondere al file che hai scelto. Cambialo se non è giusto.',
    'file_label' => 'File',
    'submit' => 'Carica estratto conto',

    'formats' => [
        'activity_download' => 'Activity Download (CSV)',
        'email_message' => 'Messaggio email (.eml)',
        'mailbox_archive' => 'Archivio di casella (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Questo file è troppo grande. Trascina qui un export di estratto conto entro il limite di dimensione del formato scelto.',
        'file_extensions' => 'Questo file non sembra un export di estratto conto supportato. Trascina qui un CSV bancario, un MT940 (.sta / .mt940 / .txt), un XML CAMT.053, un PDF di estratto conto della carta, un messaggio email (.eml) o un archivio di casella (.mbox).',
        'type_format' => 'Il valore :attribute non è valido per il tipo di importazione :type.',
        'process_failed' => "Impossibile elaborare questo file (:class). Trovi l'errore completo in /dev/logs.",
    ],
];
