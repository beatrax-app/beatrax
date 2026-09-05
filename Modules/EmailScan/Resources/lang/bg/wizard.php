<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Настрой своя OAuth клиент за Gmail',
    'microsoft_title' => 'Настрой своя OAuth клиент за Microsoft 365',
    'intro' => 'Beatrax използва твой собствен проект в Google Cloud / твоя регистрация на приложение в Azure, така че данните ти за достъп никога не попадат на споделен сървър. Настройката е еднократна за всеки доставчик.',

    'copied' => 'Копирано',
    'cancel' => 'Отказ',
    'save_connect' => 'Запази и свържи',

    'secret_help' => 'Съхранява се криптиран в базата данни на това устройство. Beatrax го изпраща само до Google или Microsoft, за да получи и подновява токена ти за достъп — никъде другаде.',

    'gmail' => [
        'step1_title' => 'Отвори Google Cloud Console',
        'step1_body' => 'Отвори Google Cloud Console в нов раздел. Влез с профила в Google, който искаш да сканираш, след което създай нов проект (или избери съществуващ личен проект).',
        'step1_link' => 'Отвори Google Cloud Console',
        'step2_title' => 'Включи Gmail API',
        'step2_body' => 'В новия проект потърси „Gmail API“ в API Library и кликни Enable. Така проектът получава право да извиква Gmail от твое име.',
        'step3_title' => 'Настрой екрана за съгласие на OAuth',
        'step3_body' => 'Отвори APIs & Services → OAuth consent screen. Избери User type „External“, въведи „Beatrax“ като име на приложението и собствения си имейл като контакт за поддръжка и разработчик. Добави обхвата https://www.googleapis.com/auth/gmail.readonly. Кликни Save and continue, след това Back to Dashboard.',
        'step4_title' => 'Публикувай екрана за съгласие в „In production“',
        'step4_body' => 'В страницата OAuth consent screen кликни Publish App и потвърди. Това е задължително — без него токените за обновяване, които Beatrax получава, изтичат след 7 дни. Публикуването не изисква проверка от Google, когато единственият потребител си ти.',
        'step4_checkbox' => 'Публикувах екрана за съгласие на OAuth в In production',
        'step5_title' => 'Създай OAuth Client ID',
        'step5_body' => 'Отвори Credentials → Create Credentials → OAuth Client ID. Избери тип приложение „Web application“. Задай име „Beatrax“. Под „Authorized redirect URIs“ постави точно URI адреса по-долу.',
        'step6_title' => 'Постави идентификатора и тайната на клиента',
        'client_id_label' => 'Идентификатор на клиента',
        'client_secret_label' => 'Тайна на клиента',
    ],

    'microsoft' => [
        'step1_title' => 'Отвори Azure Portal',
        'step1_body' => 'Отвори центъра за администриране Microsoft Entra в нов раздел. Влез с профила в Microsoft, който искаш да сканираш.',
        'step1_link' => 'Отвори Azure Portal',
        'step2_title' => 'Регистрирай ново приложение',
        'step2_body' => 'Отвори App registrations → New registration. Наименувай го „Beatrax“. Под „Supported account types“ избери „Accounts in any organizational directory and personal Microsoft accounts“ (така свързваш лични кутии в Outlook.com и служебни в Microsoft 365 с едно и също приложение).',
        'step3_title' => 'Добави URI за пренасочване',
        'step3_body' => 'В същия формуляр за регистрация, под „Redirect URI“, избери платформа „Web“ и постави точно URI адреса по-долу.',
        'step4_title' => 'Дай разрешение Mail.Read',
        'step4_body' => 'Отвори API permissions → Add a permission → Microsoft Graph → Delegated permissions. Избери Mail.Read и offline_access. Кликни Add permissions. За личен профил не е нужно съгласие от администратор.',
        'step5_title' => 'Създай тайна на клиента',
        'step5_body' => 'Отвори Certificates & secrets → New client secret. Задай описание „Beatrax“ и срок на валидност 24 месеца. Копирай стойността веднага — Azure я показва само веднъж.',
        'step6_title' => 'Постави идентификатора на приложението (клиента) и тайната',
        'client_id_label' => 'Идентификатор на приложението (клиента)',
        'client_secret_label' => 'Стойност на тайната на клиента',
    ],

    'errors' => [
        'pick_provider' => 'Избери доставчик, преди да изпратиш.',
        'microsoft_client_id' => 'Въведи идентификатора на приложението (клиента) — UUID от вида 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Въведи стойността на тайната на клиента, която Azure ти показа при създаването ѝ.',
        'google_client_id' => 'Въведи идентификатор на OAuth клиент в Google, който завършва на .apps.googleusercontent.com.',
        'google_secret' => 'Въведи тайна на OAuth клиент в Google, която започва с GOCSPX-.',
        'google_published' => 'Потвърди, че си публикувал екрана за съгласие на OAuth в „In production“.',
        'write_failed' => 'OAuth клиентът не можа да бъде запазен — записът в базата данни на това устройство се провали. Опитай отново.',
    ],
];
