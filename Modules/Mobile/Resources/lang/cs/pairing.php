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
    'camera_off_no_search' => 'Přístup k fotoaparátu je vypnutý a vyhledání druhého zařízení v síti na iPhonu zatím nefunguje — zadaný kód tak nemá čím ho najít. Povol fotoaparát Beatraxu v nastavení zařízení a naskenuj kód z druhého zařízení.',
    'no_search' => 'Vyhledání druhého zařízení v síti na iPhonu zatím nefunguje, takže zadaný kód nemá co najít. Naskenuj kód místo toho fotoaparátem — ten síť prohledávat nemusí.',
    'word_code_aria' => 'Zadej slovní kód z druhého zařízení',
    'submit_code' => 'Odeslat kód',
    'cancel' => 'Zrušit',
    'skip_import' => 'Pokračovat bez importu',

    'confirm_heading' => 'Porovnej tato slova s druhým zařízením',
    'safety_words_aria' => 'Slova bezpečnostního čísla: :words',
    'confirm_body' => 'Obě zařízení musí ukazovat úplně stejná slova. Pokud se liší, klepni na Zrušit — může probíhat útok typu man-in-the-middle.',
    'awaiting_peer' => 'Čeká se na potvrzení z druhého zařízení...',
    'confirm_match' => 'Potvrdit — slova sedí',

    'success_heading' => 'Zařízení spárováno',
    'success_body' => 'Tomuto zařízení se teď důvěřuje. Tvoje data se po připojení synchronizují.',
    'encryption_incomplete' => 'Zařízení je spárováno, ale šifrování dat uložených v něm se nedokončilo. Data zatím nejsou uložena zašifrovaně.',
    'done' => 'Hotovo',

    'errors' => [
        'relay_unreachable' => 'Druhé zařízení není dostupné. Zkontroluj, že jsou obě ve stejné síti a že je na počítači zapnutá synchronizace.',
        'no_road_home' => 'Toto zařízení neumí prohledávat síť a kód, který jsi naskenoval, neobsahuje žádnou adresu druhého zařízení. Požádej ho o nový kód a naskenuj ten.',
        'invalid_code' => 'Tento kód je neplatný nebo vypršel. Nech na druhém zařízení vygenerovat nový.',
        'code_incomplete' => 'Tento kód není úplný. Porovnej ho s druhým zařízením a zadej ho celý.',
        'code_not_accepted' => 'Žádné zařízení v této síti tento kód nepřijalo. Zkontroluj kód a jestli ho druhé zařízení stále zobrazuje.',
        'no_peer_answered' => 'Na této síti na tento kód nic neodpovědělo. Zkontroluj, že na druhém zařízení běží synchronizace, nebo naskenuj jeho kód fotoaparátem — ten síť prohledávat nemusí.',
        'no_peer_answered_ios' => 'Na této síti na tento kód nic neodpovědělo. Vyhledání druhého zařízení v síti na iPhonu zatím nefunguje, takže naskenuj jeho kód fotoaparátem.',
        'no_peer_answered_camera_off' => 'Na této síti na tento kód nic neodpovědělo. Vyhledání druhého zařízení v síti na iPhonu zatím nefunguje a přístup k fotoaparátu je vypnutý — povol proto fotoaparát Beatraxu v nastavení zařízení a naskenuj kód z druhého zařízení.',
        'rate_limited' => 'Příliš mnoho pokusů. Počkej minutu a zkus to znovu.',
        'identity_locked' => 'Identita tvého zařízení je zamčená. Odemkni aplikaci a zkus to znovu.',
        'identity_needs_lock' => 'Nejprve nastavte zámek aplikace — chrání identitu vašeho zařízení.',
        'safety_number_changed' => 'Druhé zařízení se během porovnávání změnilo. Než potvrdíš, zkontroluj slova níže znovu.',
    ],
];
