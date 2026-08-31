<?php

declare(strict_types=1);

return [
    'page_title' => 'Wgraj wyciąg',
    'heading' => 'Wgraj wyciąg',
    'migrate_prompt' => 'Przechodzisz z innej aplikacji budżetowej?',
    'migrate_link' => 'Importuj z YNAB lub Actual',
    'subtitle' => 'Upuść wyciąg w formacie CSV, CAMT.053, MT940 lub PDF albo plik z paragonem e-mail.',
    'mime_hint' => 'Obsługiwane pliki: bankowy CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF wyciągu z karty, wiadomość e-mail (.eml) lub archiwum skrzynki (.mbox).',

    'type_label' => 'Typ importu',

    'types' => [
        'csv' => 'Plik CSV',
        'camt053' => 'Wyciąg CAMT.053 (XML)',
        'mt940' => 'Wyciąg MT940',
        'pdf' => 'Wyciąg z karty (PDF)',
        'email' => 'Plik z paragonem e-mail',
    ],

    'format_label' => 'Format',

    'format_from_file' => 'Format został ustawiony na :format, żeby pasował do wybranego pliku. Zmień go, jeśli to nie tak.',
    'file_label' => 'Plik',
    'submit' => 'Wgraj wyciąg',

    'formats' => [
        'activity_download' => 'Zestawienie aktywności (CSV)',
        'email_message' => 'Wiadomość e-mail (.eml)',
        'mailbox_archive' => 'Archiwum skrzynki (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Ten plik jest za duży. Upuść eksport wyciągu mieszczący się w limicie rozmiaru dla wybranego formatu.',
        'file_extensions' => 'Ten plik nie wygląda na obsługiwany eksport wyciągu. Upuść bankowy plik CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, wyciąg karty w PDF, wiadomość e-mail (.eml) lub archiwum skrzynki (.mbox).',
        'type_format' => 'Wartość pola :attribute jest nieprawidłowa dla typu importu: :type.',
        'process_failed' => 'Nie udało się przetworzyć tego pliku (:class). Pełny błąd znajdziesz w /dev/logs.',
    ],
];
