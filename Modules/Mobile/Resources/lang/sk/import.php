<?php

declare(strict_types=1);

return [
    'page_title' => 'Import z iného zariadenia',

    'heading' => 'Import z iného zariadenia',
    'subtitle' => 'Nastav tomuto telefónu vlastný účet a zámok a potom ho spáruj s druhým zariadením — stiahne sa tvoja história.',

    'username' => 'Používateľské meno',
    'password' => 'Heslo',
    'password_help' => 'Aspoň 12 znakov — heslo sa nedá obnoviť, existujú len obnovovacie kódy.',
    'confirm_password' => 'Potvrď heslo',

    'requirements_aria' => 'Požiadavky na heslo',
    'req_length' => 'Aspoň 12 znakov',
    'req_match' => 'Obe heslá sa zhodujú',
    'req_met' => '(splnené)',
    'req_unmet' => '(zatiaľ nesplnené)',

    'pin' => 'PIN zámku aplikácie',
    'pin_help' => '6-10 číslic — odomyká toto zariadenie.',
    'confirm_pin' => 'Potvrď PIN',
    'continue' => 'Pokračovať',

    'failed_heading' => 'Nastavenie sa nedokončilo',
    'failed_body' => 'Tvoj účet bol vytvorený, ale nastavenie tohto zariadenia sa nepodarilo dokončiť. Pokojne to skús znova.',
    'try_again' => 'Skúsiť znova',

    'recovery_heading' => 'Ulož si tieto obnovovacie kódy',
    'recovery_body' => 'Vytlač si ich alebo ulož na bezpečné miesto. Znova sa už nezobrazia.',
    'already_heading' => 'Toto zariadenie je už nastavené',
    'already_body' => 'Tvoj účet na tomto zariadení existuje. Pokračuj na párovanie a prepoj ho s ostatnými zariadeniami.',
    'recovery_download' => 'Stiahnuť ako .txt',
    'recovery_copy' => 'Kopírovať kódy',
    'recovery_copied' => 'Skopírované',
    'recovery_copy_failed' => 'Kopírovanie sa nepodarilo. Kódy si radšej opíšte.',
    'recovery_saved' => 'Uložené medzi stiahnuté súbory.',
    'recovery_share_title' => 'Obnovovacie kódy Beatrax',
    'recovery_share_message' => 'Uschovajte ich na bezpečnom mieste.',
    'recovery_save_failed' => 'Súbor sa nepodarilo uložiť. Kódy si radšej opíšte.',
    'recovery_confirm' => 'Tieto kódy mám uložené na bezpečnom mieste.',
    'continue_to_pairing' => 'Pokračovať na párovanie',

    'errors' => [
        'username_required' => 'Používateľské meno je povinné.',
        'passwords_mismatch' => 'Heslá sa nezhodujú.',
        'password_length' => 'Použi aspoň 12 znakov.',
        'pin_length' => 'PIN musí mať aspoň 6 číslice.',
        'pin_digits' => 'PIN musí mať 6 až 10 číslic — iba číslice.',
        'pins_mismatch' => 'PIN-y sa nezhodujú. Skús to znova.',
        'session_expired' => 'Relácia vypršala skôr, než sa nastavenie dokončilo. Zadaj znova svoj PIN a heslo.',
        'retry_failed' => 'Nastavenie tohto zariadenia sa stále nepodarilo dokončiť. Skús to znova.',
        'account_failed' => 'Účet sa nepodarilo vytvoriť.',
    ],
];
