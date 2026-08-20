<?php

declare(strict_types=1);

return [
    'page_title' => 'Import z jiného zařízení',

    'heading' => 'Import z jiného zařízení',
    'subtitle' => 'Nastav tomuto telefonu vlastní účet a zámek a pak ho spáruj s druhým zařízením, ať se stáhne tvá historie.',

    'username' => 'Uživatelské jméno',
    'password' => 'Heslo',
    'password_help' => 'Aspoň 12 znaků — heslo se nedá resetovat, existují jen obnovovací kódy.',
    'confirm_password' => 'Potvrď heslo',
    'pin' => 'PIN pro zámek aplikace',
    'pin_help' => '6-10 číslic — odemyká toto zařízení.',
    'confirm_pin' => 'Potvrď PIN',
    'continue' => 'Pokračovat',

    'failed_heading' => 'Nastavení se nedokončilo',
    'failed_body' => 'Tvůj účet byl vytvořen, ale nastavení tohoto zařízení se nepodařilo dokončit. Můžeš to bez obav zkusit znovu.',
    'try_again' => 'Zkusit znovu',

    'recovery_heading' => 'Ulož si tyto obnovovací kódy',
    'recovery_body' => 'Vytiskni si je nebo je ulož na bezpečné místo. Znovu se už nezobrazí.',
    'already_heading' => 'Toto zařízení je už nastavené',
    'already_body' => 'Tvůj účet na tomto zařízení existuje. Pokračuj k párování a propoj ho s dalšími zařízeními.',
    'recovery_download' => 'Stáhnout jako .txt',
    'recovery_copy' => 'Kopírovat kódy',
    'recovery_copied' => 'Zkopírováno',
    'recovery_copy_failed' => 'Kopírování se nezdařilo. Kódy si raději opište.',
    'recovery_saved' => 'Uloženo mezi stažené soubory.',
    'recovery_share_title' => 'Obnovovací kódy Beatrax',
    'recovery_share_message' => 'Uschovejte je na bezpečném místě.',
    'recovery_save_failed' => 'Soubor se nepodařilo uložit. Kódy si raději opište.',
    'recovery_confirm' => 'Kódy mám uložené na bezpečném místě.',
    'continue_to_pairing' => 'Pokračovat k párování',

    'errors' => [
        'passwords_mismatch' => 'Hesla se neshodují.',
        'password_length' => 'Použij aspoň 12 znaků.',
        'pin_length' => 'PIN musí mít aspoň 6 číslice.',
        'pins_mismatch' => 'PINy se neshodují. Zkus to znovu.',
        'session_expired' => 'Tvá relace vypršela dřív, než se nastavení dokončilo. Zadej prosím znovu PIN a heslo.',
        'retry_failed' => 'Nastavení tohoto zařízení se pořád nedaří dokončit. Zkus to prosím znovu.',
        'account_failed' => 'Účet se nepodařilo vytvořit.',
    ],
];
