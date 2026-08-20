<?php

declare(strict_types=1);

return [
    'page_title' => 'Wgraj wyciąg',
    'heading' => 'Wgraj wyciąg',
    'migrate_prompt' => 'Przechodzisz z innej aplikacji budżetowej?',
    'migrate_link' => 'Importuj z YNAB lub Actual',
    'subtitle' => 'Upuść eksport z banku, karty lub PayPal albo plik z paragonem e-mail.',
    'mime_hint' => 'Obsługiwane pliki: bankowy CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF wyciągu z karty, wiadomość e-mail (.eml) lub archiwum skrzynki (.mbox).',

    'source_label' => 'Źródło',

    'issuer_other_bank' => 'Inny bank (N26, Revolut, ING…)',
    'issuer_email_file' => 'Plik e-mail (.eml, .mbox)',

    'format_label' => 'Format',
    'file_label' => 'Plik',
    'submit' => 'Wgraj wyciąg',

    'formats' => [
        'activity_download' => 'Zestawienie aktywności (CSV)',
        'email_message' => 'Wiadomość e-mail (.eml)',
        'mailbox_archive' => 'Archiwum skrzynki (.mbox)',
        'ing_nl' => 'ING Holandia (CSV)',
    ],

    'errors' => [
        'file_max' => 'Ten plik jest za duży. Upuść eksport wyciągu mieszczący się w limicie rozmiaru dla wybranego formatu.',
        'file_extensions' => 'Ten plik nie wygląda na obsługiwany eksport wyciągu. Upuść bankowy plik CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, wyciąg karty w PDF, wiadomość e-mail (.eml) lub archiwum skrzynki (.mbox).',
        'issuer_format' => 'Wartość pola :attribute jest nieprawidłowa dla źródła: :source.',
        'process_failed' => 'Nie udało się przetworzyć tego pliku (:class). Pełny błąd znajdziesz w /dev/logs.',
    ],
];
