<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Системни сигнали',

    'actions' => [
        'install_next_launch' => 'Инсталирай при следващото стартиране',
        'install_next_launch_aria' => 'Инсталирай при следващото стартиране — отбелязва системния сигнал #:id като решен',
        'skip_version' => 'Пропусни тази версия',
        'release_notes' => 'Бележки към версията →',
        'update_now' => 'Обнови сега',
        'update_now_aria' => 'Обнови сега — отбелязва системния сигнал #:id като решен',
        'remind_later' => 'Напомни ми по-късно',
        'mark_resolved' => 'Отбележи като решен',
        'mark_resolved_aria' => 'Отбележи като решен — системен сигнал #:id',
    ],

    'messages' => [
        'update_available' => 'Налична е нова версия — Beatrax :version е готова. Ще се инсталира при следващото стартиране.',
        'update_stale' => 'Използваш версия :current — версия :latest е налична от 30 дни. Обнови сега.',
        'update_critical' => 'Налично е критично обновяване — версия :version поправя :summary. Инсталирай я възможно най-скоро.',
        'backup_corrupt_with_path' => 'Резервното копие, записано в :timestamp, не премина проверката за цялост. Провери :path. Реши проблема, преди да разчиташ на резервните копия.',
        'backup_corrupt_no_path' => 'Резервното копие, опитано в :timestamp, беше прекъснато, преди да бъде създаден какъвто и да е файл — изходната база данни не премина проверката за цялост. Реши проблема, преди да разчиташ на резервните копия.',

        'backup_overdue' => 'Последното проверено резервно копие е отпреди :hoursh. Изпълни <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> или изчакай планираното изпълнение в 03:00.',
        'wal_mode_missing' => 'SQLite не работи в режим WAL (в момента :mode). Едновременните записи може да блокират. Изпълни <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> за насоки.',
        'synchronous_misconfigured' => 'Нивото synchronous на SQLite е :level (очаквано NORMAL/1). Поведението при надеждност може да се различава от конфигурацията. Изпълни <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> за насоки.',
        'reconnect_link' => 'Свържи отново →',
    ],
];
