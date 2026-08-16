<?php

declare(strict_types=1);

return [
    'page_title' => 'Завантажити виписку',
    'heading' => 'Завантажити виписку',
    'migrate_prompt' => 'Переходиш з іншого застосунку для бюджетування?',
    'migrate_link' => 'Імпортувати з YNAB або Actual',
    'subtitle' => 'Перетягни експорт із банку, картки чи PayPal або файл чека з пошти.',
    'mime_hint' => 'Цей файл не схожий на підтримуваний експорт виписки. Перетягни банківський CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF-виписку картки, лист (.eml) або архів поштової скриньки (.mbox).',

    'source_label' => 'Джерело',

    'issuer_other_bank' => 'Інший банк (N26, Revolut, ING…)',
    'issuer_email_file' => 'Файл пошти (.eml, .mbox)',

    'format_label' => 'Формат',
    'file_label' => 'Файл',
    'submit' => 'Завантажити виписку',

    'formats' => [
        'activity_download' => 'Activity Download (CSV)',
        'email_message' => 'Лист (.eml)',
        'mailbox_archive' => 'Архів поштової скриньки (.mbox)',
        'ing_nl' => 'ING Нідерланди (CSV)',
    ],

    'errors' => [
        'file_max' => 'Цей файл завеликий. Перетягни експорт виписки в межах обмеження розміру для вибраного формату.',
        'file_extensions' => 'Цей файл не схожий на підтримуваний експорт виписки. Перетягни банківський CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF-виписку картки, лист (.eml) або архів поштової скриньки (.mbox).',
        'issuer_format' => 'Значення :attribute не підходить для джерела :source.',
        'process_failed' => 'Не вдалося обробити цей файл (:class). Повний текст помилки — у /dev/logs.',
    ],
];
