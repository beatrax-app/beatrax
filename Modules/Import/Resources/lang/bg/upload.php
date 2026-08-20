<?php

declare(strict_types=1);

return [
    'page_title' => 'Качване на извлечение',
    'heading' => 'Качване на извлечение',
    'migrate_prompt' => 'Преминаваш от друго приложение за бюджетиране?',
    'migrate_link' => 'Импортирай от YNAB или Actual',
    'subtitle' => 'Пусни тук банков, картов или PayPal експорт, или файл с касова бележка от имейл.',
    'mime_hint' => 'Поддържани файлове: банков CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF с извлечение по карта, имейл съобщение (.eml) или архив на пощенска кутия (.mbox).',

    'source_label' => 'Източник',

    'issuer_other_bank' => 'Друга банка (N26, Revolut, ING…)',
    'issuer_email_file' => 'Имейл файл (.eml, .mbox)',

    'format_label' => 'Формат',
    'file_label' => 'Файл',
    'submit' => 'Качи извлечението',

    'formats' => [
        'activity_download' => 'Изтегляне на активността (CSV)',
        'email_message' => 'Имейл съобщение (.eml)',
        'mailbox_archive' => 'Архив на пощенска кутия (.mbox)',
        'ing_nl' => 'ING Нидерландия (CSV)',
    ],

    'errors' => [
        'file_max' => 'Този файл е твърде голям. Пусни експорт на извлечение под ограничението за размер на избрания формат.',
        'file_extensions' => 'Този файл не изглежда като поддържан експорт на извлечение. Пусни банков CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF с извлечение по карта, имейл съобщение (.eml) или архив на пощенска кутия (.mbox).',
        'issuer_format' => 'Стойността :attribute не е валидна за източника :source.',
        'process_failed' => 'Този файл не можа да бъде обработен (:class). Пълната грешка е в /dev/logs.',
    ],
];
