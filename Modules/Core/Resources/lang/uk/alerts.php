<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Системні сповіщення',

    'actions' => [
        'install_next_launch' => 'Встановити при наступному запуску',
        'install_next_launch_aria' => 'Встановити при наступному запуску — позначає системне сповіщення #:id як вирішене',
        'skip_version' => 'Пропустити цю версію',
        'release_notes' => 'Опис версії →',
        'update_now' => 'Оновити зараз',
        'update_now_aria' => 'Оновити зараз — позначає системне сповіщення #:id як вирішене',
        'remind_later' => 'Нагадати пізніше',
        'mark_resolved' => 'Позначити як вирішене',
        'mark_resolved_aria' => 'Позначити як вирішене — системне сповіщення #:id',
    ],

    'messages' => [
        'update_available' => 'Доступне оновлення — версія Beatrax :version готова. Встановиться при наступному запуску.',
        'update_stale' => 'У тебе версія :current — версія :latest доступна вже 30 днів. Онови зараз.',
        'update_critical' => 'Доступне критичне оновлення — версія :version виправляє :summary. Встанови якнайшвидше.',
        'backup_corrupt_with_path' => 'Резервна копія, записана :timestamp, не пройшла перевірку цілісності. Переглянь :path. Виправ це, перш ніж покладатися на резервні копії.',
        'backup_corrupt_no_path' => 'Резервне копіювання, розпочате :timestamp, перервалося до створення файлу — вихідна БД не пройшла перевірку цілісності. Виправ це, перш ніж покладатися на резервні копії.',

        'backup_overdue' => 'Найсвіжішій перевіреній резервній копії вже :hoursгод. Виконай <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> або зачекай на запланований запуск о 03:00.',
        'wal_mode_missing' => 'SQLite працює не в режимі WAL (зараз :mode). Одночасні записи можуть зупинятися. Виконай <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>, щоб отримати вказівки.',
        'synchronous_misconfigured' => 'Рівень synchronous у SQLite — :level (очікується NORMAL/1). Гарантії збереження даних можуть відрізнятися від конфігурації. Виконай <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>, щоб отримати вказівки.',
        'reconnect_link' => 'Підключити знову →',
    ],
];
