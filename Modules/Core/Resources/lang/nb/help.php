<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Om :subject',
        'close' => 'Lukk',
    ],

    'page_title' => 'Hvor er dataene mine?',
    'intro' => 'Beatrax lagrer alt på denne enheten. Ingenting sendes til en server, ingenting synkroniseres til skyen, og ingenting forlater enheten uten at du eksporterer det.',

    'lives_here' => 'Dataene dine ligger her',
    'copy' => 'Kopier',
    'copied' => 'Kopiert',

    'location' => [
        'database' => 'Database:',
        'artefacts_imports' => 'Importerte kontoutskrifter:',
        'artefacts_mail' => 'Skannet e-post:',
        'artefacts_drop' => 'Overvåket mappe:',
        'backups' => 'Sikkerhetskopier:',
        'secrets' => 'Påloggingsdetaljer for tilkoblinger:',
        'logs' => 'Logger:',
    ],

    'copy_aria' => [
        'database' => 'Kopier stien til databasen til utklippstavlen',
        'artefacts_imports' => 'Kopier stien til importerte kontoutskrifter til utklippstavlen',
        'artefacts_mail' => 'Kopier stien til skannet e-post til utklippstavlen',
        'artefacts_drop' => 'Kopier stien til den overvåkede mappen til utklippstavlen',
        'backups' => 'Kopier stien til sikkerhetskopier til utklippstavlen',
        'secrets' => 'Kopier stien til påloggingsdetaljer for tilkoblinger til utklippstavlen',
        'logs' => 'Kopier stien til logger til utklippstavlen',
    ],

    'artefacts_heading' => 'Kildedokumentene dine ligger ikke i sikkerhetskopien',
    'artefacts_body' => 'En sikkerhetskopi inneholder databasen og ingenting annet. Kontoutskriftene du importerte, e-posten skanneren hentet inn og kvitteringene du la i den overvåkede mappen blir liggende der de er, i de tre mappene ovenfor. Å legge en sikkerhetskopi et trygt sted kopierer dem ikke, så et fullstendig arkiv betyr at du må ta med disse mappene også — eller bruke Eksporter alt nedenfor, som pakker dem sammen med sikkerhetskopien for deg.',

    'export_heading' => 'Eksporter alt',
    'export_body' => 'Ett arkiv med en kryptert kopi av databasen din og hvert kildedokument du har gitt Beatrax. Pakk det ut hvor du vil, og dokumentene ligger der akkurat som før, i mappene de kom fra.',
    'export_passphrase_label' => 'Passordfrase for databasen',
    'export_confirm_label' => 'Gjenta passordfrasen',
    'export_passphrase_hint' => 'Databasen inne i arkivet krypteres med denne passordfrasen, og den kan ikke åpnes uten, så velg noe du fortsatt har senere. Kildedokumentene går inn slik de er, så oppbevar arkivet et sted du stoler på.',
    'export_cta' => 'Eksporter alt som ZIP',
    'export_working' => 'Arkivet bygges…',

    'delete_heading' => 'Slette dataene dine',
    'delete_intro' => 'Dataene dine er filer på denne enheten, så å slette dem betyr å slette de filene. Det finnes ingen knapp her som gjør det for deg, og det er med vilje: det er filsystemet som faktisk holder historikken din, og en knapp som tømte noen tabeller og lot filene ligge, ville vært verre enn ingenting.',
    'delete_uninstall' => 'Å avinstallere Beatrax sletter ikke dataene dine. Det er bevisst — en utilsiktet avinstallering skal ikke ødelegge mange års historikk — så alt nedenfor blir liggende på denne enheten til du fjerner det selv.',
    'delete_list_intro' => 'Slett hver av disse for å fjerne alle spor:',
    'delete_journal_note' => 'Databasen har to journalfiler ved siden av seg, :wal og :shm. De nyeste endringene dine ligger i dem til de skrives inn i databasen, så slett alle tre samtidig.',
    'no_telemetry' => 'Det finnes ingen telemetri å reservere seg mot og ingen fjernkonto å avslutte.',
];
