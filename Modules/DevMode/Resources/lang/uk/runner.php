<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan runner',
    'subtitle' => 'Команди SAFE запускай одним кліком; команди DESTRUCTIVE — за потрійним бар’єром.',
    'run_a_command' => 'Запустити команду',
    'filter_aria' => 'Фільтр запусків',
    'filter' => [
        'all' => 'Усі',
        'running' => 'Виконуються',
        'failed' => 'Невдалі',
        'destructive' => 'Деструктивні',
    ],
    'worker_running' => 'Обробник черги: ПРАЦЮЄ',
    'worker_not_running' => 'Обробник черги: НЕ ПРАЦЮЄ',
    'no_runs' => 'Запусків ще немає. Натисни «Запустити команду» або скористайся палітрою команд (⌘K).',
    // i18n-review: uk · no_runs_touch — the same line for a touch
    // screen; check the verb governs this case.
    'no_runs_touch' => 'Запусків ще немає. Натисни «Запустити команду» або скористайся палітрою команд (⌘K).',
    'recent_runs_aria' => 'Останні запуски',
    'modal_heading' => 'Запустити команду SAFE',
    'modal_intro' => 'Обери команду рівня SAFE, щоб виконати її одразу. Команд DESTRUCTIVE тут немає — скористайся повторним запуском на стрічці подій або палітрою ⌘K.',
    'args_badge' => 'args',
    'args_badge_title' => 'Відкриває форму аргументів',

    'spawning_unavailable' => 'Команди Artisan виконуються в окремому процесі, а ця платформа не дозволяє застосунку його запустити. Запусти їх у застосунку для комп\'ютера.',

    'status' => [
        'running' => 'Виконується',
        'done' => 'Готово',
        'failed' => 'Не вдалося',
        'cancelled' => 'Скасовано',
    ],
    'cancel' => 'Скасувати',
    'rerun' => 'Запустити знову',
    'started' => 'Розпочато :when',
    'exit' => 'код виходу',

    'toast' => [
        'unknown_command' => 'Невідома команда: :command',
        'missing_args' => 'Не можна виконати :command — бракує :noun: :list',
        'invalid_args' => 'Не можна виконати :command — :reason',
        'arg' => 'аргумента|аргументів|аргументів',
        'started' => 'Запущено :command (запуск :runId)',
        'run_expired' => 'Запис про запуск застарів — повторний запуск неможливий.',
        'reran' => 'Повторно запущено :command (запуск :runId)',
        'rerun_forbidden' => 'Цей запуск належить іншому розробнику.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Створити резервну копію бази даних', 'description' => 'Записує копію SQLite з відміткою часу в каталог резервних копій (або за вказаним шляхом).'],
        'doctor' => ['label' => 'Запустити doctor', 'description' => 'Показує встановлені версії PHP / Composer / SQLite і перевіряє мінімальні вимоги.'],
        'failed_jobs' => ['label' => 'Очистити невдалі завдання', 'description' => 'Видаляє опрацьовані записи з таблиці failed_jobs, якою керує Laravel.'],
        'cache_clear' => ['label' => 'Очистити кеш', 'description' => 'Очищає сховище кешу застосунку.'],
        'route_list' => ['label' => 'Показати маршрути', 'description' => 'Виводить кожен зареєстрований HTTP-маршрут у stdout.'],
        'config_show' => ['label' => 'Показати конфігурацію', 'description' => 'Виводить значення за вказаним ключем конфігурації з крапками.'],
        'view_clear' => ['label' => 'Очистити кеш виглядів', 'description' => 'Очищає кеш скомпільованих Blade-виглядів.'],
        'queue_retry' => ['label' => 'Повторити невдалі завдання', 'description' => 'Повторює одне завдання (за id) або кожне невдале завдання (порожній id).'],
        // i18n-review: uk · command.rederive_fingerprints — «відбиток» is the word the Auth
        // files already use for a key and a biometric fingerprint; here it names the
        // transaction fingerprint. Confirm the same noun carries all three.
        'rederive_fingerprints' => ['label' => 'Перерахувати відбитки', 'description' => 'Перераховує відбиток кожної транзакції за поточною версією нормалізації.'],
        'db_restore' => ['label' => 'Відновити базу даних', 'description' => 'Замінює поточну базу даних вказаним файлом резервної копії.'],
        'migrate_fresh' => ['label' => 'Видалити таблиці та мігрувати заново', 'description' => 'Видаляє кожну таблицю, а потім виконує кожну міграцію заново.'],
        'reset_password' => ['label' => 'Скинути пароль', 'description' => 'Інтерактивно скидає пароль користувача (неінтерактивне використання відхиляється).'],
        'regenerate_recovery_codes' => ['label' => 'Згенерувати нові коди відновлення', 'description' => 'Заново генерує 10 одноразових кодів відновлення для користувача.'],
        'grant_dev' => ['label' => 'Надати доступ розробника', 'description' => 'Встановлює is_developer=true для вказаного користувача.'],
        'install' => ['label' => 'Виконати встановлення', 'description' => 'Ідемпотентне налаштування першого запуску. Повторний запуск на вже налаштованому встановленні є деструктивним.'],
    ],

    'arg' => [
        'destination' => ['label' => 'Файл призначення', 'help' => 'Залиш порожнім, щоб використати типовий каталог резервних копій.', 'placeholder' => '/шлях/до/backup.sqlite (необов’язково)'],
        'action' => ['label' => 'Дія'],
        'config' => ['label' => 'Ключ конфігурації', 'help' => 'Файл конфігурації або ключ з крапками, який треба вивести, напр. `app` чи `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Id завдання', 'help' => 'Залиш порожнім, щоб повторити кожне невдале завдання; вкажи id, щоб повторити один запис.', 'placeholder' => 'усі (або конкретний id)'],
        'queue' => ['label' => 'Назва черги', 'help' => 'Необов’язковий фільтр за чергою; типово — усі черги.', 'placeholder' => 'default'],
        'from' => ['label' => 'Шлях до файлу резервної копії', 'help' => 'Замінює поточну базу даних файлом за вказаним шляхом.', 'placeholder' => '/шлях/до/backup.sqlite'],
        'username' => ['label' => 'Ім’я користувача', 'placeholder' => 'alice'],
    ],
];
