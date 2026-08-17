<?php

declare(strict_types=1);

return [
    'heading' => 'Поштові скриньки',
    'intro' => 'Підключи скриньки Gmail і Microsoft 365, щоб Beatrax міг сканувати їх на чеки.',

    'connection_canceled' => 'Підключення скасовано.',
    'connection_failed' => 'Не вдалося завершити підключення.',

    'backfilling' => 'Дозавантаження',
    'messages_suffix' => 'повідомлень',

    'connect_heading' => 'Підключи свою пошту',
    'connect_body' => 'Імпортуй чеки з PayPal, ICS Cards, Google Play та інших продавців, надавши Beatrax доступ лише для читання до однієї чи кількох своїх скриньок.',
    'connect_gmail' => 'Підключити Gmail',
    'connect_microsoft' => 'Підключити Microsoft 365',
    'readonly_note' => 'Beatrax лише читає повідомлення. Він ніколи нічого не надсилає, не позначає, не переміщує й не видаляє у твоїй скриньці.',

    'month' => '1 місяць',
    'months' => ':count міс.',
    'not_scanned_yet' => 'ще не скановано',
    'last_scanned' => 'останнє сканування',
    'window_prefix' => 'Період:',
    'edit' => 'Редагувати',

    'badge' => [
        'idle' => 'Очікування',
        'backfilling' => 'Дозавантаження',
        'scanning' => 'Сканування',
        'rate_limited' => 'Ліміт запитів',
        'needs_reauth' => 'Потрібна повторна авторизація',
        'error' => 'Помилка',
    ],

    'retry_seconds' => 'повтор через :nс',
    'retry_minutes' => 'повтор через :nхв',
    'retry_hours' => 'повтор через :nгод',

    'reconnect' => 'Підключити знову',
    'disconnect' => 'Відключити',
    'scan_now' => 'Сканувати зараз',
    'scan_in_progress_title' => 'Сканування вже триває',

    'add_another' => 'Додати ще одну скриньку',
    'gmail_card_body' => 'Підключи обліковий запис Gmail, щоб Beatrax міг сканувати його на чеки.',
    'microsoft_card_body' => 'Підключи обліковий запис Microsoft 365 або Outlook.com, щоб Beatrax міг сканувати його на чеки.',

    'discovered_heading' => 'Виявлені відправники',
    'discovered_body' => 'Відправники, які схожі на тих, що надсилають чеки, але яких ще немає у твоєму списку відомих. Додай тих, кого Beatrax має сканувати; решту відхили.',
    'last_seen' => 'востаннє',
    'seen_times' => 'Побачено разів: :count',
    'add' => 'Додати',
    'add_aria' => 'Додати :email',
    'dismiss' => 'Відхилити',
    'dismiss_aria' => 'Відхилити :email',

    'toast' => [
        'scan_in_progress' => 'Сканування вже триває.',
        'scan_started' => 'Сканування розпочато.',
        'sender_added' => 'Відправника додано.',
        'sender_dismissed' => 'Відправника відхилено.',
    ],
];
