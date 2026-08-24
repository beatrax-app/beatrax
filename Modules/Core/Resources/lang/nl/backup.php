<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => "Deze telefoon kan een bestand dat de app aanbiedt niet opslaan, dus de versleutelde back-up maak je in de desktop-app. Koppel dit apparaat om beide gelijk te houden.",
        'unavailable' => 'Versleutelde back-ups zijn beschikbaar in de desktopversie (SQLite). Gebruik bij een serverdatabase de eigen back-uptools van je database.',
        'intro' => 'Download een met wachtwoordzin versleutelde kopie van je hele database — veilig om op een externe schijf of in cloudopslag te bewaren, want zonder de wachtwoordzin is deze onleesbaar (kwantumveilig XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Wachtwoordzin',
        'confirm_passphrase' => 'Bevestig wachtwoordzin',
        'keep_safe' => 'Bewaar de wachtwoordzin veilig — zonder deze kun je de back-up niet herstellen.',
        'submit' => 'Versleutelde back-up downloaden',
        'preparing' => 'Bezig met voorbereiden…',
    ],

    'restore' => [
        'heading' => 'Herstellen vanaf een back-up',
        'intro_html' => 'Vervang je huidige database door een versleutelde back-up. Het bestand wordt ontsleuteld en gecontroleerd voordat er iets verandert, en er wordt eerst een momentopname van je huidige gegevens opgeslagen — maar dit <strong class="text-slate-700 dark:text-slate-200">overschrijft alles</strong>, dus het is beveiligd.',
        'restored' => 'Hersteld. Herlaad de app om je herstelde gegevens te zien.',
        'snapshot_saved_prefix' => 'Er is een momentopname van je vorige gegevens opgeslagen in',
        'file_label' => 'Versleutelde back-up (.enc)',
        'uploading' => 'Bezig met uploaden…',
        'passphrase' => 'Wachtwoordzin',
        'confirm_prefix' => 'Typ',
        'confirm_suffix' => 'om te bevestigen',
        'submit' => 'Herstellen (overschrijft huidige gegevens)',
        'restoring' => 'Bezig met herstellen…',
    ],

    'errors' => [
        'passphrase_min' => 'Gebruik een wachtwoordzin van minimaal :min teken.|Gebruik een wachtwoordzin van minimaal :min tekens.',
        'passphrase_mismatch' => 'De twee wachtwoordzinnen komen niet overeen.',
        'download_sqlite_only' => 'Versleuteld downloaden is alleen beschikbaar in de SQLite-versie.',
        'create_failed' => 'Kon de back-up niet maken: :message',
        'confirm_phrase' => 'Typ :phrase om te bevestigen — dit vervangt je huidige gegevens.',
        'choose_file' => 'Kies een versleuteld back-upbestand (.enc) om te herstellen.',
        'enter_passphrase' => 'Voer de wachtwoordzin in waarmee de back-up is versleuteld.',
        'unreadable' => 'Het geüploade bestand kon niet worden gelezen. Probeer het opnieuw.',
    ],
];
