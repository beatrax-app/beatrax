<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Over :subject',
        'close' => 'Sluiten',
    ],

    'page_title' => 'Waar staan mijn gegevens?',
    'intro' => 'Beatrax bewaart alles op dit apparaat. Er is geen Beatrax-server en geen cloudaccount. Naar buiten gaat alleen wat je zelf koppelt — een postvak, een bank via Enable Banking, de apparaten die je koppelt voor synchronisatie — plus een dagelijkse opvraging van wisselkoersen. Elke verbinding zegt dat op het scherm waar je hem aanzet.',

    'lives_here' => 'Je gegevens staan hier',
    'copy' => 'Kopiëren',
    'copied' => 'Gekopieerd',

    'location' => [
        'database' => 'Database:',
        'artefacts_imports' => 'Geïmporteerde afschriften:',
        'artefacts_mail' => 'Gescande e-mail:',
        'artefacts_drop' => 'Bewaakte inleesmap:',
        'backups' => 'Back-ups:',
        'secrets' => 'Koppelingsgegevens:',
        'logs' => 'Logboeken:',
    ],

    'copy_aria' => [
        'database' => 'Databasepad naar klembord kopiëren',
        'artefacts_imports' => 'Pad van geïmporteerde afschriften naar klembord kopiëren',
        'artefacts_mail' => 'Pad van gescande e-mail naar klembord kopiëren',
        'artefacts_drop' => 'Pad van bewaakte inleesmap naar klembord kopiëren',
        'backups' => 'Back-uppad naar klembord kopiëren',
        'secrets' => 'Pad van koppelingsgegevens naar klembord kopiëren',
        'logs' => 'Logboekpad naar klembord kopiëren',
    ],

    'artefacts_heading' => 'Je brondocumenten zitten niet in de back-up',
    'artefacts_body' => 'Een back-up bevat de database en verder niets. De afschriften die je hebt geïmporteerd, de e-mail die de scanner ophaalde en de bonnen die je in de bewaakte map zette blijven staan waar ze staan, in de drie mappen hierboven. Een back-up ergens veilig neerzetten kopieert ze dus niet: een volledig archief betekent die mappen ook meenemen — of hieronder Alles exporteren gebruiken, dat ze samen met de back-up inpakt.',

    'export_heading' => 'Alles exporteren',
    'export_body' => 'Eén archief met een versleutelde kopie van je database en elk brondocument dat je aan Beatrax hebt gegeven. Pak het uit waar je wilt en je documenten staan erin zoals ze altijd waren, in de mappen waar ze vandaan kwamen.',
    'export_passphrase_label' => 'Wachtwoordzin voor de database',
    'export_confirm_label' => 'Herhaal de wachtwoordzin',
    'export_passphrase_hint' => 'De database in het archief wordt met deze wachtwoordzin versleuteld en is zonder wachtwoordzin niet te openen, dus kies er een die je bewaart. Je brondocumenten gaan er ongewijzigd in, dus bewaar het archief op een plek die je vertrouwt.',
    'export_cta' => 'Alles exporteren als ZIP',
    'export_working' => 'Archief wordt gemaakt…',

    'delete_heading' => 'Je gegevens verwijderen',
    'delete_intro' => 'Je gegevens zijn bestanden op dit apparaat, dus verwijderen betekent die bestanden verwijderen. Er is hier geen knop die dat voor je doet, en dat is met opzet: het bestandssysteem houdt je geschiedenis vast, en een knop die een paar tabellen leegmaakte terwijl de bestanden bleven staan zou erger zijn dan niets.',
    'delete_uninstall' => 'Beatrax verwijderen wist je gegevens niet. Dat is bewust — een per ongeluk verwijderde app mag geen jaren geschiedenis vernietigen — dus alles hieronder blijft op dit apparaat staan totdat je het zelf weghaalt.',
    'delete_list_intro' => 'Verwijder elk van deze om alle sporen te wissen:',
    'delete_journal_note' => 'Naast de database staan twee journaalbestanden, :wal en :shm. Je meest recente wijzigingen staan daarin totdat ze in de database worden opgenomen, dus verwijder alle drie samen.',
    'no_telemetry' => 'Er is geen telemetrie om af te melden en geen extern account om op te heffen.',
];
