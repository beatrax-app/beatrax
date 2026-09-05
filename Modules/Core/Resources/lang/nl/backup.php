<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Deze app kan geen bestand aan je apparaat doorgeven, dus de versleutelde back-up maak je in de desktop-app. Koppel dit apparaat om beide gelijk te houden.',
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
        'intro_html' => 'Vervang je huidige database door een versleutelde back-up. Het bestand wordt ontsleuteld en gecontroleerd voordat er iets verandert, en er wordt eerst een momentopname van je huidige gegevens opgeslagen — maar dit <strong class="text-slate-700 dark:text-slate-200">overschrijft alles</strong>, dus het is beveiligd. Je wordt afgemeld, want je aanmelding staat ook in de database.',
        'restored' => 'Je back-up is hersteld. Meld je aan met de gebruikersnaam en het wachtwoord die golden toen die werd gemaakt.',
        'snapshot_saved_prefix' => 'Er is een momentopname van je vorige gegevens opgeslagen in',
        'file_label' => 'Back-upbestand (.enc) of exportarchief (.zip)',
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
        'choose_file' => 'Kies waaruit je wilt herstellen: het .enc-back-upbestand of de .zip die de export met één klik schreef.',
        'upload_failed' => 'Het bestand is niet volledig geüpload. Het is mogelijk te groot voor dit apparaat — herstellen in de desktop-app accepteert een grotere back-up.',
        'enter_passphrase' => 'Voer de wachtwoordzin in waarmee de back-up is versleuteld.',
        'unreadable' => 'Het geüploade bestand kon niet worden gelezen. Probeer het opnieuw.',
        'restore_wrong_passphrase' => 'Die wachtwoordzin opende deze back-up niet, en er is niets gewijzigd. Typ hem opnieuw en probeer het nog eens. Klopt hij zeker, dan is het bestand veranderd sinds het is gemaakt en moet je een andere kopie terugzetten.',
        'restore_not_a_backup' => 'Dit bestand bevat geen Beatrax-back-up, dus er valt niets terug te zetten en er is niets gewijzigd. Kies het .enc-bestand dat de app schreef toen je de back-up maakte, of de .zip die de export met één klik schreef.',
        'restore_contents_unreadable' => 'De back-up ging open, maar de database erin is beschadigd, dus die is niet teruggezet en er is niets gewijzigd. Zet een oudere back-up terug.',
        'restore_could_not_read' => 'Het back-upbestand kon niet worden gelezen, dus het terugzetten is niet uitgevoerd en er is niets gewijzigd. Controleer of dit apparaat vrije ruimte heeft en probeer het opnieuw.',
        'restore_not_supported' => 'Terugzetten werkt op de versie die haar gegevens in één bestand bewaart, en dat is deze niet, dus er is niets gewijzigd. Gebruik bij een serverdatabase het herstelgereedschap van die database zelf.',
        'restore_failed' => 'Het terugzetten is niet uitgevoerd en er is niets gewijzigd. Probeer het opnieuw — blijft het misgaan, dan staat in het app-logboek wat het tegenhield.',
    ],
];
