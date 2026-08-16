<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Твоят профил в PayPal',
    'h1' => 'Свържи своя профил в PayPal',

    'lede_html' => 'Пусни експорта с детайли за транзакциите от PayPal — в нидерландски профил той се води <em lang="nl">Rapport Transactiegegevens</em>. Отчетът за салдото (<span lang="nl">Saldorapport</span>) не върши работа — нужни са ни данни за всяко събитие.',

    'format_group_aria' => 'PayPal експортира само в CSV',
    'got_it_as' => 'Получи го като:',
    'badge_only_format' => 'единствен формат',

    'mini' => [
        'login_label' => 'Влез',
        'custom_label' => 'Персонализирани извлечения',
        'range_label' => 'Избери период',
        'range_sub' => 'Последните 12 месеца',
        'download_label' => 'Изтегли като CSV',
    ],

    'drop_lead' => 'Пусни CSV файла с детайли за транзакциите тук',
    'browse_file' => 'или потърси файл',

    'file_ready' => '· ✓ готово',

    'skip' => 'Пропусни тази стъпка',
    'continue' => 'Продължи →',

    'errors' => [
        'required' => 'Първо пусни в полето CSV файла PayPal Rapport Transactiegegevens.',
        'max' => 'Файлът е твърде голям. Експортите PayPal Rapport Transactiegegevens обикновено са доста под 10 MB.',
        'extensions' => 'Този файл не прилича на CSV от PayPal. Изтегли от PayPal Rapport Transactiegegevens (а не отчета за салдото Saldorapport) като CSV.',
        'unreadable' => 'Файлът не можа да бъде прочетен. Пълната грешка е в /dev/logs.',
    ],
];
