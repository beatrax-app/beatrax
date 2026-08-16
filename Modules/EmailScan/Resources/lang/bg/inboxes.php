<?php

declare(strict_types=1);

return [
    'heading' => 'Пощенски кутии',
    'intro' => 'Свържи пощенски кутии в Gmail и Microsoft 365, за да може Beatrax да ги сканира за разписки.',

    'connection_canceled' => 'Свързването е отменено.',
    'connection_failed' => 'Свързването не можа да бъде завършено.',

    'backfilling' => 'Ретроактивен импорт',
    'messages_suffix' => 'съобщения',

    'connect_heading' => 'Свържи имейла си',
    'connect_body' => 'Импортирай разписки от PayPal, ICS Cards, Google Play и други търговци, като дадеш на Beatrax достъп само за четене до една или повече твои пощенски кутии.',
    'connect_gmail' => 'Свържи Gmail',
    'connect_microsoft' => 'Свържи Microsoft 365',
    'readonly_note' => 'Beatrax само чете съобщенията. Никога не изпраща, не етикетира, не премества и не изтрива нищо в пощенската ти кутия.',

    'month' => '1 месец',
    'months' => ':count месеца',
    'not_scanned_yet' => 'още не е сканирано',
    'last_scanned' => 'последно сканиране',
    'window_prefix' => 'Период:',
    'edit' => 'Редактирай',

    'badge' => [
        'idle' => 'В покой',
        'backfilling' => 'Ретроактивен импорт',
        'scanning' => 'Сканиране',
        'rate_limited' => 'Ограничена честота',
        'needs_reauth' => 'Изисква ново удостоверяване',
        'error' => 'Грешка',
    ],

    'retry_seconds' => 'нов опит след :nс',
    'retry_minutes' => 'нов опит след :nм',
    'retry_hours' => 'нов опит след :nч',

    'reconnect' => 'Свържи отново',
    'scan_now' => 'Сканирай сега',
    'scan_in_progress_title' => 'Вече тече сканиране',

    'add_another' => 'Добави още една пощенска кутия',
    'gmail_card_body' => 'Свържи акаунт в Gmail, за да може Beatrax да го сканира за разписки.',
    'microsoft_card_body' => 'Свържи акаунт в Microsoft 365 или Outlook.com, за да може Beatrax да го сканира за разписки.',

    'discovered_heading' => 'Открити податели',
    'discovered_body' => 'Податели, които изглежда изпращат разписки, но още не са в списъка ти с познати податели. Добави тези, които искаш Beatrax да сканира; останалите отхвърли.',
    'last_seen' => 'последно видяно',
    'seen_times' => 'Видяно :count пъти',
    'add' => 'Добави',
    'add_aria' => 'Добави :email',
    'dismiss' => 'Отхвърли',
    'dismiss_aria' => 'Отхвърли :email',

    'toast' => [
        'scan_in_progress' => 'Вече тече сканиране.',
        'scan_started' => 'Сканирането започна.',
        'sender_added' => 'Подателят е добавен.',
        'sender_dismissed' => 'Подателят е отхвърлен.',
    ],
];
