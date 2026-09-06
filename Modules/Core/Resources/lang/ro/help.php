<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Despre :subject',
        'close' => 'Închide',
    ],

    'page_title' => 'Unde sunt datele mele?',
    'intro' => 'Beatrax păstrează totul pe acest dispozitiv. Nu există niciun server Beatrax și niciun cont în cloud. Singurul lucru care iese de la sine este un apel — o verificare a unei versiuni noi, pe care o poți dezactiva. Tot restul te așteaptă pe tine: o căsuță de e-mail, o bancă prin Enable Banking, o interogare zilnică a cursurilor valutare, dispozitivele pe care le împerechezi pentru sincronizare, un releu pe care îl configurezi și orice link pe care dai clic. Fiecare dintre ele spune asta pe ecranul de unde îl activezi.',

    'lives_here' => 'Datele tale sunt aici',
    'copy' => 'Copiază',
    'copied' => 'Copiat',

    'location' => [
        'database' => 'Baza de date:',
        'artefacts_imports' => 'Extrase importate:',
        'artefacts_mail' => 'E-mailuri scanate:',
        'artefacts_drop' => 'Folder supravegheat:',
        'backups' => 'Copii de rezervă:',
        'secrets' => 'Credențialele conexiunilor:',
        'logs' => 'Jurnale:',
    ],

    'copy_aria' => [
        'database' => 'Copiază în clipboard calea bazei de date',
        'artefacts_imports' => 'Copiază în clipboard calea extraselor importate',
        'artefacts_mail' => 'Copiază în clipboard calea e-mailurilor scanate',
        'artefacts_drop' => 'Copiază în clipboard calea folderului supravegheat',
        'backups' => 'Copiază în clipboard calea copiilor de rezervă',
        'secrets' => 'Copiază în clipboard calea credențialelor conexiunilor',
        'logs' => 'Copiază în clipboard calea jurnalelor',
    ],

    'artefacts_heading' => 'Documentele tale sursă nu se află în copia de rezervă',
    'artefacts_body' => 'O copie de rezervă conține baza de date și nimic altceva. Extrasele pe care le-ai importat, e-mailurile aduse de scaner și bonurile lăsate în folderul supravegheat rămân acolo unde sunt, în cele trei foldere de mai sus. Punerea copiei de rezervă la loc sigur nu le copiază, așa că o arhivă completă înseamnă să iei cu tine și acele foldere — sau să folosești Exportă tot de mai jos, care le împachetează împreună cu copia de rezervă.',

    'export_heading' => 'Exportă tot',
    'export_body' => 'O singură arhivă cu o copie criptată a bazei tale de date și fiecare document sursă pe care i l-ai dat lui Beatrax. Dezarhiveaz-o oriunde, iar documentele sunt înăuntru așa cum au fost mereu, în folderele din care au venit.',
    'export_passphrase_label' => 'Frază de acces pentru baza de date',
    'export_confirm_label' => 'Repetă fraza de acces',
    'export_passphrase_hint' => 'Baza de date din arhivă este criptată cu această frază de acces și nu există nicio cale de a o deschide fără ea, așa că alege ceva ce vei mai avea. Documentele sursă intră așa cum sunt, deci ține arhiva într-un loc în care ai încredere.',
    'export_cta' => 'Exportă tot ca ZIP',
    'export_working' => 'Se construiește arhiva…',

    'delete_heading' => 'Ștergerea datelor tale',
    'delete_intro' => 'Datele tale sunt fișiere pe acest dispozitiv, așa că ștergerea lor înseamnă ștergerea acelor fișiere. Nu există aici un buton care să facă asta în locul tău, și e intenționat: istoricul tău este ținut de fapt de sistemul de fișiere, iar un buton care ar goli câteva tabele lăsând fișierele pe loc ar fi mai rău decât nimic.',
    'delete_uninstall' => 'Dezinstalarea Beatrax nu îți șterge datele. Este intenționat — o dezinstalare accidentală nu trebuie să distrugă ani de istoric — așa că tot ce urmează rămâne pe acest dispozitiv până îl elimini tu.',
    'delete_list_intro' => 'Ca să nu rămână nicio urmă, șterge fiecare dintre acestea:',
    'delete_journal_note' => 'Lângă baza de date stau două fișiere de jurnal, :wal și :shm. Cele mai recente modificări ale tale sunt acolo până sunt integrate în baza de date, așa că șterge-le pe toate trei împreună.',
    'no_telemetry' => 'Nu există nicio telemetrie de dezactivat și niciun cont la distanță de închis.',
];
