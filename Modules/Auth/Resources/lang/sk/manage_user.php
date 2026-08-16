<?php

declare(strict_types=1);

return [
    'page_title' => 'Správa: :name · Beatrax',
    'heading' => 'Správa: :name',
    'subtitle' => 'Zobraz, resetuj alebo znova vygeneruj kódy tohto používateľa.',

    'set_password' => [
        'heading' => 'Nastav tomuto používateľovi nové heslo',
        'description' => 'Pri ďalšom prihlásení sa zobrazí výzva na voľbu hesla.',
        'open' => 'Nastav tomuto používateľovi nové heslo',
        'body' => 'Nastav nové heslo pre tohto používateľa: :name. Pri ďalšom prihlásení sa zobrazí výzva na voľbu hesla.',
        'label' => 'Nové heslo',
        'submit' => 'Nastaviť heslo',
        'cancel' => 'Zrušiť',
    ],

    'regenerate' => [
        'heading' => 'Znova vygeneruj záložné kódy tohto používateľa',
        'description' => 'Staré kódy prestanú platiť.',
        'open' => 'Znova vygeneruj záložné kódy tohto používateľa',
        'body' => 'Doterajšie nepoužité kódy prestanú fungovať. Nových 10 kódov uvidíš iba raz a môžeš ich odovzdať.',
        'confirm_label' => 'Pokračuj napísaním používateľského mena',
        'submit' => 'Vygenerovať kódy',
        'keep' => 'Ponechať súčasné kódy',
        'download' => 'Stiahnuť ako .txt',
    ],

    'error_min_length' => 'Použi aspoň 12 znakov.',
    'password_set' => 'Heslo je nastavené. Používateľ: :name. Pri ďalšom prihlásení sa zobrazí výzva na voľbu hesla.',
    'codes_regenerated' => 'Používateľ :name má desať nových záložných kódov.',
];
