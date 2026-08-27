<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Nastavení',
        'heading' => 'Open banking',
        'subtitle' => 'Automaticky stahuj transakce z ASN nebo SNS přes Enable Banking, externího agregátora PSD2. Ve výchozím stavu vypnuto.',
        'toggle_label' => 'Zapnout open banking',
        'toggle_connected' => 'Připojeno přes Enable Banking. Banka: :bank.',
        'toggle_off_help' => 'Ve výchozím stavu vypnuto. Vyžaduje jednorázové potvrzení a nastavení krok za krokem.',
        'reconfirm_body' => 'Tvé potvrzení vypršelo dřív, než se podařilo připojení dokončit. Potvrď to znovu a zapínání open bankingu dokonči.',
        'reconfirm_button' => 'Potvrdit znovu a dokončit zapnutí',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Spravovat open banking',
        'not_connected' => 'Není připojená žádná banka. Připoj jednu a transakce se budou importovat automaticky.',
        'expired' => 'Souhlas vypršel — je potřeba se připojit znovu.',
        'connected' => 'Připojeno přes Enable Banking. Banka: :bank. Poslední synchronizace: :when.',
        'never' => 'nikdy',
    ],

    'transparency' => [
        'aggregator_label' => 'Agregátor',
        'bank_label' => 'Banka',
        'consent_status_label' => 'Stav souhlasu',
        'pill_expired' => 'Vypršel — připoj znovu',
        'pill_expiring' => 'Brzy vyprší',
        'pill_connected' => 'Připojeno',
        'whats_fetched_label' => 'Co se stahuje',
        'whats_fetched' => 'Zaúčtované transakce + zůstatky, posledních 90 dní',
        'last_successful_sync_label' => 'Poslední úspěšná synchronizace',
        'never' => 'Nikdy',
        'last_attempt_label' => 'Poslední pokus',
        'last_attempt_failed' => ':when — selhalo (:reason)',
        'reason_consent_expired' => 'souhlas vypršel',
        'reason_error' => 'chyba',
        'disconnect_button' => 'Odpojit',
    ],

    'consent_banner' => [
        'heading' => 'Souhlas vypršel — připoj znovu',
        'body' => 'Poslední úspěšná synchronizace: :when. Připoj se znovu a automatická synchronizace bude pokračovat.',
        'never' => 'nikdy',
        'reconnect' => 'Připojit znovu',
    ],

    'sync' => [
        'review_import' => 'Zkontrolovat import',
        'reconnect_first' => 'Nejdřív se připoj znovu',
        'auto_caption' => 'Synchronizuje se automaticky jednou denně.',
        'sync_now' => 'Synchronizovat teď',

        'consent_expired' => 'Souhlas vypršel — připoj se znovu.',
        'unavailable' => 'Enable Banking je dočasně nedostupné. Zkus to za chvíli znovu.',
        'new_found' => 'Nalezena :count nová transakce.|Nalezeny :count nové transakce.|Nalezeno :count nových transakcí.',
        'none' => 'Žádné nové transakce.',
    ],

    'disconnect' => [
        'heading' => 'Odpojit open banking?',
        'body' => 'Odstraní to tvé uložené přihlašovací údaje a souhlas pro Enable Banking. Automatická synchronizace se okamžitě zastaví. Transakce, které už jsou naimportované v Beatraxu, to neovlivní.',
        'confirm' => 'Odpojit',
        'cancel' => 'Nechat připojené',
    ],

    'ics' => [
        'section_label' => 'Import souboru — žádné přihlašovací údaje se neukládají',
        'heading' => 'Výpis z kreditní karty ICS',
        'step_login' => 'Přihlas se',
        'step_download' => 'Stáhni výpis z účtu',
        'pdf_statement' => 'Výpis v PDF',
        'step_drop' => 'Přetáhni ho níž',
        'drop_zone_label' => 'Sem přetáhni soubor s výpisem z účtu',
        'drop_zone_hint' => 'nebo soubor vyber v počítači',
        'browse_aria' => 'Vybrat soubor s výpisem ICS',
        'import_button' => 'Naimportovat výpis z účtu',
        'validation' => [
            'required' => 'Přetáhni sem výpis ICS stažený z Mijn ICS.',
            'max' => 'Tento soubor je příliš velký. Výpisy ICS v PDF mají obvykle méně než 1 MB.',
            'extensions' => 'Tohle není PDF. Mijn ICS exportuje výpisy jen v PDF.',
        ],
        'could_not_read' => 'Soubor :filename se nepodařilo přečíst. Úplnou chybu najdeš v /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Než se připojíš k třetí straně',
        'body' => 'Zapnutí open bankingu odešle tvůj souhlas s přihlášením do banky a potom i data o transakcích a zůstatcích přímo z tohoto zařízení do Enable Banking a tvé bance. Beatrax neprovozuje server, který by tato data viděl — ale Enable Banking a tvá banka je vidí. Tím se to liší od všech ostatních způsobů importu v Beatraxu, které data nikam neposílají.',
        'acknowledge' => 'Rozumím, že se moje data o transakcích budou sdílet s Enable Banking a s mojí bankou.',
        'confirm' => 'Zapnout open banking',
        'cancel' => 'Zrušit',
    ],

    'wizard' => [
        'heading' => 'Připoj svou banku',
        'intro' => 'Beatrax používá tvou vlastní aplikaci Enable Banking, takže tvé přihlašovací údaje nikdy nesáhnou na sdílený server. Je to jednorázové nastavení pro každou banku.',

        'step1_title' => 'Vygeneruj si lokální pár klíčů',
        'step1_body' => 'Beatrax vygeneruje pár klíčů RSA na tomto zařízení. Soukromý klíč ho nikdy neopustí.',
        'generate_keypair' => 'Vygenerovat pár klíčů',
        'public_key_label' => 'Veřejný klíč',
        'copy_public_key' => 'Kopírovat veřejný klíč',
        'copied' => 'Zkopírováno',
        'redirect_uri_label' => 'Redirect URI',
        'copy_redirect_uri' => 'Kopírovat redirect URI',

        'step2_title' => 'Zaregistruj aplikaci v Enable Banking',
        'step2_body' => 'Otevři vývojářský portál Enable Banking, vytvoř aplikaci a vlož do ní veřejný klíč a redirect URI z kroku 1.',
        'open_portal' => 'Otevřít portál Enable Banking ↗',

        'step3_title' => 'Vlož identifikátor aplikace',
        'application_id_label' => 'Identifikátor aplikace',
        'step3_help' => 'Ukládá se do lokálního souboru mimo databázi s omezenými oprávněními a nikdy neopustí toto zařízení.',

        'step4_title' => 'Vyber svou banku',
        'via_enable_banking' => 'přes Enable Banking',
        'other_institution' => 'Jiná instituce',
        'institution_id_placeholder' => 'Identifikátor instituce',

        'step5_title' => 'Dokonči souhlas v prohlížeči',
        'step5_body' => 'Klikni níž a otevře se přihlášení a obrazovka souhlasu tvé banky. Dokonči přihlášení i případné dvoufázové ověření a pak se sem automaticky vrátíš, ať můžeš zapínání Open Bankingu dokončit.',

        'cancel' => 'Zrušit',
        'continue' => 'Pokračovat →',
        'continue_to_bank' => 'Pokračovat — :bank →',
        'your_bank' => 'tvoje banka',

        'errors' => [
            'save_keypair_failed' => 'Pár klíčů se nepodařilo uložit na disk — zkontroluj oprávnění adresáře secrets a zkus to znovu.',
            'generate_failed' => 'Na tomto zařízení se nepodařilo vygenerovat pár klíčů — zkontroluj konfiguraci OpenSSL.',
            'export_failed' => 'Vygenerovaný pár klíčů se nepodařilo exportovat.',
            'read_public_failed' => 'Vygenerovaný veřejný klíč se nepodařilo přečíst.',
            'generate_first' => 'Než budeš pokračovat, vygeneruj pár klíčů.',
            'paste_application_id' => 'Než budeš pokračovat, vlož identifikátor aplikace z portálu Enable Banking.',
            'save_application_id_failed' => 'Identifikátor aplikace se nepodařilo uložit na disk — zkontroluj oprávnění adresáře secrets a zkus to znovu.',
            'choose_bank' => 'Než budeš pokračovat, vyber banku.',
        ],
    ],

    'alert' => [
        'reconsent' => 'Připoj svou banku znovu',
    ],

    'errors' => [
        'wizard_incomplete' => 'Nejdřív dokonči průvodce nastavením Open Bankingu.',
        'no_bank_chosen' => 'Před připojením vyber banku.',
        'no_consent_url' => 'Enable Banking nevrátilo URL souhlasu.',
        'unparseable_consent_url' => 'Enable Banking vrátilo URL souhlasu, které nejde zpracovat.',
        'non_public_consent_host' => 'Enable Banking vrátilo neveřejný host souhlasu.',
        'unsafe_consent_url' => 'Enable Banking vrátilo nebezpečné URL souhlasu.',
        'no_authorization_code' => 'Zpětné volání Enable Banking nevrátilo autorizační kód.',
        'no_session_id' => 'Enable Banking nevrátilo identifikátor relace.',
        'oauth_state_mismatch' => 'Tento odkaz pro připojení vypršel nebo již byl použit. Začněte připojení banky znovu.',
    ],
];
