<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Налаштуй свій OAuth-клієнт Gmail',
    'microsoft_title' => 'Налаштуй свій OAuth-клієнт Microsoft 365',
    'intro' => 'Beatrax використовує твій власний проєкт Google Cloud / реєстрацію застосунку Azure, тож твої облікові дані ніколи не потрапляють на спільний сервер. Це одноразове налаштування для кожного провайдера.',

    'copied' => 'Скопійовано',
    'cancel' => 'Скасувати',
    'save_connect' => 'Зберегти та під’єднати',

    'secret_help' => 'Зберігається зашифрованим у базі даних на цьому пристрої. Beatrax надсилає його лише Google або Microsoft, щоб отримати й оновлювати твій токен доступу — більше нікуди.',

    'gmail' => [
        'step1_title' => 'Відкрий Google Cloud Console',
        'step1_body' => 'Відкрий Google Cloud Console у новій вкладці. Увійди в обліковий запис Google, який хочеш сканувати, а потім створи новий проєкт (або вибери наявний особистий проєкт).',
        'step1_link' => 'Відкрити Google Cloud Console',
        'step2_title' => 'Увімкни Gmail API',
        'step2_body' => 'У новому проєкті знайди «Gmail API» в API Library і натисни Enable. Це дасть проєкту змогу звертатися до Gmail від твого імені.',
        'step3_title' => 'Налаштуй екран згоди OAuth',
        'step3_body' => 'Відкрий APIs & Services → OAuth consent screen. Вибери User type «External», введи «Beatrax» як назву застосунку та власну електронну адресу як контакт підтримки й контакт розробника. Додай scope https://www.googleapis.com/auth/gmail.readonly. Натисни Save and continue, а потім Back to Dashboard.',
        'step4_title' => 'Переведи екран згоди в стан «In production»',
        'step4_body' => 'На сторінці екрана згоди OAuth натисни Publish App і підтверди. Це обов’язково — без цього токени оновлення, які отримує Beatrax, спливають через 7 днів. Публікація не потребує перевірки Google, якщо єдиний користувач — це ти.',
        'step4_checkbox' => 'Екран згоди OAuth переведено в стан In production',
        'step5_title' => 'Створи OAuth Client ID',
        'step5_body' => 'Відкрий Credentials → Create Credentials → OAuth Client ID. Вибери тип застосунку «Web application». Задай назву «Beatrax». У полі «Authorized redirect URIs» встав точно наведений нижче URI.',
        'step6_title' => 'Встав свій client ID і client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Відкрий Azure Portal',
        'step1_body' => 'Відкрий Microsoft Entra admin center у новій вкладці. Увійди в обліковий запис Microsoft, який хочеш сканувати.',
        'step1_link' => 'Відкрити Azure Portal',
        'step2_title' => 'Зареєструй новий застосунок',
        'step2_body' => 'Відкрий App registrations → New registration. Назви його «Beatrax». У розділі «Supported account types» вибери «Accounts in any organizational directory and personal Microsoft accounts» (це дає змогу під’єднати особисті скриньки Outlook.com і робочі Microsoft 365 одним застосунком).',
        'step3_title' => 'Додай redirect URI',
        'step3_body' => 'У тій самій формі реєстрації, у розділі «Redirect URI», вибери платформу «Web» і встав точно наведений нижче URI.',
        'step4_title' => 'Надай дозвіл Mail.Read',
        'step4_body' => 'Відкрий API permissions → Add a permission → Microsoft Graph → Delegated permissions. Вибери Mail.Read і offline_access. Натисни Add permissions. Для особистого облікового запису згода адміністратора не потрібна.',
        'step5_title' => 'Створи client secret',
        'step5_body' => 'Відкрий Certificates & secrets → New client secret. Задай опис «Beatrax» і термін дії 24 місяці. Скопіюй значення секрета одразу — Azure показує його лише раз.',
        'step6_title' => 'Встав свій application (client) ID і секрет',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Значення client secret',
    ],

    'errors' => [
        'pick_provider' => 'Вибери провайдера, перш ніж надсилати.',
        'microsoft_client_id' => 'Введи application (client) ID — UUID на кшталт 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Введи значення client secret, яке Azure показав під час створення секрета.',
        'google_client_id' => 'Введи Google OAuth client ID, що закінчується на .apps.googleusercontent.com.',
        'google_secret' => 'Введи Google OAuth client secret, що починається з GOCSPX-.',
        'google_published' => 'Підтверди, що екран згоди OAuth переведено в стан «In production».',
        'write_failed' => 'Не вдалося зберегти твій OAuth-клієнт — запис у базу даних на цьому пристрої не вдався. Спробуй ще раз.',
    ],
];
