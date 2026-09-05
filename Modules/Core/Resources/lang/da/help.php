<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Om :subject',
        'close' => 'Luk',
    ],

    'page_title' => 'Hvor er mine data?',
    'intro' => 'Beatrax gemmer alt på denne enhed. Intet sendes til en server, intet synkroniseres til skyen, og intet forlader enheden, uden at du eksporterer det.',

    'lives_here' => 'Dine data ligger her',
    'copy' => 'Kopiér',
    'copied' => 'Kopieret',

    'location' => [
        'database' => 'Database:',
        'artefacts_imports' => 'Importerede kontoudtog:',
        'artefacts_mail' => 'Scannet post:',
        'artefacts_drop' => 'Overvåget mappe:',
        'backups' => 'Sikkerhedskopier:',
        'secrets' => 'Loginoplysninger til forbindelser:',
        'logs' => 'Logfiler:',
    ],

    'copy_aria' => [
        'database' => 'Kopiér stien til databasen til udklipsholderen',
        'artefacts_imports' => 'Kopiér stien til importerede kontoudtog til udklipsholderen',
        'artefacts_mail' => 'Kopiér stien til scannet post til udklipsholderen',
        'artefacts_drop' => 'Kopiér stien til den overvågede mappe til udklipsholderen',
        'backups' => 'Kopiér stien til sikkerhedskopier til udklipsholderen',
        'secrets' => 'Kopiér stien til loginoplysninger til forbindelser til udklipsholderen',
        'logs' => 'Kopiér stien til logfiler til udklipsholderen',
    ],

    'artefacts_heading' => 'Dine kildedokumenter ligger ikke i sikkerhedskopien',
    'artefacts_body' => 'En sikkerhedskopi indeholder databasen og intet andet. De kontoudtog, du har importeret, den post, scanneren hentede ind, og de kvitteringer, du lagde i den overvågede mappe, bliver liggende, hvor de er, i de tre mapper ovenfor. At lægge en sikkerhedskopi et sikkert sted kopierer dem ikke, så et fuldt arkiv betyder, at du også tager de mapper med — eller bruger Eksportér alt nedenfor, som pakker dem sammen med sikkerhedskopien for dig.',

    'export_heading' => 'Eksportér alt',
    'export_body' => 'Ét arkiv med en krypteret kopi af din database og hvert kildedokument, du har givet Beatrax. Pak det ud hvor som helst, og dine dokumenter ligger derinde, som de altid har været, i de mapper, de kom fra.',
    'export_passphrase_label' => 'Adgangskode til databasen',
    'export_confirm_label' => 'Gentag adgangskoden',
    'export_passphrase_hint' => 'Databasen inde i arkivet krypteres med denne adgangskode, og den kan ikke åbnes uden, så vælg noget, du stadig har om et år. Dine kildedokumenter ryger med, som de er, så gem arkivet et sted, du stoler på.',
    'export_cta' => 'Eksportér alt som ZIP',
    'export_working' => 'Arkivet bygges…',

    'delete_heading' => 'Sletning af dine data',
    'delete_intro' => 'Dine data er filer på denne enhed, så at slette dem betyder at slette de filer. Der er ingen knap her, der gør det for dig, og det er med vilje: det er filsystemet, der rummer din historik, og en knap, der tømte et par tabeller og lod filerne blive liggende, ville være værre end ingenting.',
    'delete_uninstall' => 'At afinstallere Beatrax sletter ikke dine data. Det er bevidst — en utilsigtet afinstallation må ikke ødelægge års historik — så alt nedenfor bliver på denne enhed, indtil du selv fjerner det.',
    'delete_list_intro' => 'Slet hver af disse for at fjerne ethvert spor:',
    'delete_journal_note' => 'Databasen har to journalfiler liggende ved siden af sig, :wal og :shm. Dine seneste ændringer ligger i dem, indtil de bliver skrevet ind i databasen, så slet alle tre samlet.',
    'no_telemetry' => 'Der er ingen telemetri at fravælge og ingen fjernkonto at lukke.',
];
