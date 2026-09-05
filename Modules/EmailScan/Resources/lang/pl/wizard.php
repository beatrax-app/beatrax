<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Skonfiguruj własnego klienta OAuth dla Gmaila',
    'microsoft_title' => 'Skonfiguruj własnego klienta OAuth dla Microsoft 365',
    'intro' => 'Beatrax korzysta z Twojego własnego projektu Google Cloud / rejestracji aplikacji Azure, więc dane uwierzytelniające nigdy nie trafiają na wspólny serwer. To jednorazowa konfiguracja dla każdego dostawcy.',

    'copied' => 'Skopiowano',
    'cancel' => 'Anuluj',
    'save_connect' => 'Zapisz i połącz',

    'secret_help' => 'Jest przechowywany zaszyfrowany w bazie danych na tym urządzeniu. Beatrax wysyła go tylko do Google albo Microsoftu, aby uzyskać i odnawiać Twój token dostępu — nigdzie indziej.',

    'gmail' => [
        'step1_title' => 'Otwórz Google Cloud Console',
        'step1_body' => 'Otwórz Google Cloud Console w nowej karcie. Zaloguj się na konto Google, które chcesz skanować, a następnie utwórz nowy projekt (albo wybierz istniejący projekt prywatny).',
        'step1_link' => 'Otwórz Google Cloud Console',
        'step2_title' => 'Włącz Gmail API',
        'step2_body' => 'W nowym projekcie wyszukaj „Gmail API” w API Library i kliknij Enable. Dzięki temu projekt będzie mógł wywoływać Gmaila w Twoim imieniu.',
        'step3_title' => 'Skonfiguruj ekran zgody OAuth',
        'step3_body' => 'Otwórz APIs & Services → OAuth consent screen. Wybierz User type „External”, wpisz „Beatrax” jako nazwę aplikacji oraz własny adres e-mail jako kontakt pomocniczy i kontakt dewelopera. Dodaj zakres https://www.googleapis.com/auth/gmail.readonly. Kliknij Save and continue, a potem Back to Dashboard.',
        'step4_title' => 'Przenieś ekran zgody do stanu „In production”',
        'step4_body' => 'Na stronie ekranu zgody OAuth kliknij Publish App i potwierdź. To jest wymagane — bez tego tokeny odświeżania, które otrzymuje Beatrax, wygasają po 7 dniach. Publikacja nie wymaga weryfikacji Google, gdy aplikacja ma tylko jednego użytkownika.',
        'step4_checkbox' => 'Ekran zgody OAuth został opublikowany jako In production',
        'step5_title' => 'Utwórz OAuth Client ID',
        'step5_body' => 'Otwórz Credentials → Create Credentials → OAuth Client ID. Wybierz typ aplikacji „Web application”. Ustaw nazwę „Beatrax”. W polu „Authorized redirect URIs” wklej dokładnie poniższy URI.',
        'step6_title' => 'Wklej swój client ID i client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Otwórz Azure Portal',
        'step1_body' => 'Otwórz Microsoft Entra admin center w nowej karcie. Zaloguj się na konto Microsoft, które chcesz skanować.',
        'step1_link' => 'Otwórz Azure Portal',
        'step2_title' => 'Zarejestruj nową aplikację',
        'step2_body' => 'Otwórz App registrations → New registration. Nazwij ją „Beatrax”. W sekcji „Supported account types” wybierz „Accounts in any organizational directory and personal Microsoft accounts” (dzięki temu jedna aplikacja obsłuży zarówno prywatne skrzynki Outlook.com, jak i służbowe Microsoft 365).',
        'step3_title' => 'Dodaj redirect URI',
        'step3_body' => 'W tym samym formularzu rejestracji, w sekcji „Redirect URI”, wybierz platformę „Web” i wklej dokładnie poniższy URI.',
        'step4_title' => 'Przyznaj uprawnienie Mail.Read',
        'step4_body' => 'Otwórz API permissions → Add a permission → Microsoft Graph → Delegated permissions. Zaznacz Mail.Read i offline_access. Kliknij Add permissions. Dla konta prywatnego zgoda administratora nie jest potrzebna.',
        'step5_title' => 'Utwórz client secret',
        'step5_body' => 'Otwórz Certificates & secrets → New client secret. Ustaw opis „Beatrax” i ważność 24 miesięcy. Skopiuj wartość sekretu od razu — Azure pokazuje ją tylko jeden raz.',
        'step6_title' => 'Wklej identyfikator aplikacji (client) ID i sekret',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Wybierz dostawcę przed wysłaniem.',
        'microsoft_client_id' => 'Podaj identyfikator aplikacji (client) ID — UUID w rodzaju 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Podaj wartość client secret, którą Azure pokazał przy tworzeniu sekretu.',
        'google_client_id' => 'Podaj identyfikator klienta OAuth Google kończący się na .apps.googleusercontent.com.',
        'google_secret' => 'Podaj sekret klienta OAuth Google zaczynający się od GOCSPX-.',
        'google_published' => 'Potwierdź, że ekran zgody OAuth został przeniesiony do stanu „In production”.',
        'write_failed' => 'Nie udało się zapisać klienta OAuth — zapis do bazy danych na tym urządzeniu nie powiódł się. Spróbuj ponownie.',
    ],
];
