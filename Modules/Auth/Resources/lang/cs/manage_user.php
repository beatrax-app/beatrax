<?php

declare(strict_types=1);

return [
    'page_title' => 'Správa: :name · Beatrax',
    'heading' => 'Správa: :name',
    'subtitle' => 'Zobraz, resetuj nebo znovu vygeneruj kódy tohoto uživatele.',

    'set_password' => [
        'heading' => 'Nastavit tomuto uživateli nové heslo',
        'description' => 'Při příštím přihlášení si bude muset zvolit heslo.',
        'open' => 'Nastavit tomuto uživateli nové heslo',
        'body' => 'Nastav nové heslo pro uživatele :name. Při příštím přihlášení si bude muset zvolit heslo.',
        'label' => 'Nové heslo',
        'submit' => 'Nastavit heslo',
        'cancel' => 'Zrušit',
    ],

    'regenerate' => [
        'heading' => 'Vygenerovat tomuto uživateli nové záložní kódy',
        'description' => 'Staré kódy přestanou platit.',
        'open' => 'Vygenerovat tomuto uživateli nové záložní kódy',
        'body' => 'Stávající nepoužité kódy přestanou fungovat. 10 nových kódů uvidíš jen jednou a můžeš je předat dál.',
        'confirm_label' => 'Pro pokračování napiš uživatelské jméno',
        'submit' => 'Vygenerovat kódy',
        'keep' => 'Ponechat stávající kódy',
        'download' => 'Stáhnout jako .txt',
    ],

    'error_min_length' => 'Použij aspoň 12 znaků.',
    'password_set' => 'Heslo nastaveno pro uživatele :name. Při příštím přihlášení si bude muset zvolit heslo.',
    'codes_regenerated' => 'Pro uživatele :name bylo vygenerováno deset nových záložních kódů.',
];
