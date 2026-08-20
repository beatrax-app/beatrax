<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Spárované zařízení',
    'page_title' => 'Spárovat zařízení',

    'scan_heading' => 'Spárovat toto zařízení',
    'scan_subtitle' => 'Namiř fotoaparát na kód zobrazený na druhém zařízení.',
    'camera_permission_pending' => 'Přístup k fotoaparátu je vypnutý. Povol ho Beatraxu v nastavení zařízení a zkus to znovu.',
    'open_camera' => 'Otevřít fotoaparát',
    'opening_camera' => 'Čeká se na přístup k fotoaparátu…',
    'close_camera' => 'Zavřít fotoaparát',
    'viewfinder_aria' => 'Hledáček fotoaparátu — namiř ho na kód na druhém zařízení',
    'viewfinder_idle' => 'Fotoaparát je vypnutý. Otevři ho a naskenuj kód zobrazený na druhém zařízení.',
    'scan_prompt' => 'Naskenuj kód na druhém zařízení',
    'enter_code_instead' => 'Zadat kód ručně',

    'enter_heading' => 'Zadej kód',
    'camera_off' => 'Přístup k fotoaparátu je vypnutý. Zadej místo toho kód z druhého zařízení.',
    'word_code_aria' => 'Zadej slovní kód z druhého zařízení',
    'submit_code' => 'Odeslat kód',
    'cancel' => 'Zrušit',

    'confirm_heading' => 'Porovnej tato slova s druhým zařízením',
    'safety_words_aria' => 'Slova bezpečnostního čísla: :words',
    'confirm_body' => 'Obě zařízení musí ukazovat úplně stejná slova. Pokud se liší, klepni na Zrušit — může probíhat útok typu man-in-the-middle.',
    'awaiting_peer' => 'Čeká se na potvrzení z druhého zařízení...',
    'confirm_match' => 'Potvrdit — slova sedí',

    'success_heading' => 'Zařízení spárováno',
    'success_body' => 'Tomuto zařízení se teď důvěřuje. Tvoje data se po připojení synchronizují.',
    'done' => 'Hotovo',

    'errors' => [
        'relay_unreachable' => 'Druhé zařízení není dostupné. Zkontroluj, že jsou obě ve stejné síti a že je na počítači zapnutá synchronizace.',
        'invalid_code' => 'Tento kód je neplatný nebo vypršel. Nech na druhém zařízení vygenerovat nový.',
        'identity_locked' => 'Identita tvého zařízení je zamčená. Odemkni aplikaci a zkus to znovu.',
        'identity_needs_lock' => 'Nejprve nastavte zámek aplikace — chrání identitu vašeho zařízení.',
    ],
];
