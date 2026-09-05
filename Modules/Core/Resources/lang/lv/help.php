<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Par :subject',
        'close' => 'Aizvērt',
    ],

    'page_title' => 'Kur ir mani dati?',
    'intro' => 'Beatrax glabā visu šajā ierīcē. Nekas netiek sūtīts uz serveri, nekas netiek sinhronizēts ar mākoni, nekas nepamet šo ierīci, kamēr to neeksportējat.',

    'lives_here' => 'Jūsu dati atrodas šeit',
    'copy' => 'Kopēt',
    'copied' => 'Nokopēts',

    'location' => [
        'database' => 'Datubāze:',
        'artefacts_imports' => 'Importētie konta pārskati:',
        'artefacts_mail' => 'Skenētais pasts:',
        'artefacts_drop' => 'Uzraudzītā mape:',
        'backups' => 'Dublējumi:',
        'secrets' => 'Savienojumu pieteikšanās dati:',
        'logs' => 'Žurnāli:',
    ],

    'copy_aria' => [
        'database' => 'Kopēt datubāzes ceļu starpliktuvē',
        'artefacts_imports' => 'Kopēt importēto konta pārskatu ceļu starpliktuvē',
        'artefacts_mail' => 'Kopēt skenētā pasta ceļu starpliktuvē',
        'artefacts_drop' => 'Kopēt uzraudzītās mapes ceļu starpliktuvē',
        'backups' => 'Kopēt dublējumu ceļu starpliktuvē',
        'secrets' => 'Kopēt savienojumu pieteikšanās datu ceļu starpliktuvē',
        'logs' => 'Kopēt žurnālu ceļu starpliktuvē',
    ],

    'artefacts_heading' => 'Jūsu avota dokumenti neatrodas dublējumā',
    'artefacts_body' => 'Dublējumā ir datubāze un nekas cits. Konta pārskati, ko importējāt, pasts, ko ievilka skeneris, un čeki, ko ielikāt uzraudzītajā mapē, paliek tur, kur ir, — trijās iepriekš uzskaitītajās mapēs. Dublējuma pārvietošana uz drošu vietu tos nenokopē, tāpēc pilns arhīvs nozīmē paņemt līdzi arī šīs mapes — vai izmantot tālāk pieejamo Eksportēt visu, kas tās iesaiņo kopā ar dublējumu.',

    'export_heading' => 'Eksportēt visu',
    'export_body' => 'Viens arhīvs ar šifrētu jūsu datubāzes kopiju un katru avota dokumentu, ko esat devis Beatrax. Atarhivējiet to jebkur, un dokumenti būs iekšā tādi paši kā vienmēr, tajās mapēs, no kurām nāca.',
    'export_passphrase_label' => 'Datubāzes paroles frāze',
    'export_confirm_label' => 'Atkārtojiet paroles frāzi',
    'export_passphrase_hint' => 'Datubāze arhīva iekšienē tiek šifrēta ar šo paroles frāzi, un bez tās to nekādi nevar atvērt, tāpēc izvēlieties tādu, kas jums saglabāsies. Avota dokumenti nonāk arhīvā tādi, kādi ir, tāpēc glabājiet arhīvu vietā, kurai uzticaties.',
    'export_cta' => 'Eksportēt visu kā ZIP',
    'export_working' => 'Arhīvs tiek veidots…',

    'delete_heading' => 'Datu dzēšana',
    'delete_intro' => 'Jūsu dati ir faili šajā ierīcē, tāpēc to dzēšana nozīmē šo failu dzēšanu. Šeit nav pogas, kas to izdarītu jūsu vietā, un tas ir ar nolūku: jūsu vēsturi patiesībā tur failu sistēma, un vadīkla, kas iztukšotu dažas tabulas, atstājot failus vietā, būtu sliktāka par neko.',
    'delete_uninstall' => 'Beatrax atinstalēšana nedzēš jūsu datus. Tas ir apzināti — nejauša atinstalēšana nedrīkst iznīcināt gadiem krātu vēsturi —, tāpēc viss zemāk minētais paliek šajā ierīcē, līdz to noņemat pats.',
    'delete_list_intro' => 'Lai nepaliktu nekādu pēdu, izdzēsiet katru no šiem:',
    'delete_journal_note' => 'Blakus datubāzei atrodas divi žurnāla faili, :wal un :shm. Jaunākās izmaiņas glabājas tajos, līdz tās tiek ierakstītas datubāzē, tāpēc izdzēsiet visus trīs kopā.',
    'no_telemetry' => 'Nav telemetrijas, no kuras atteikties, un nav attālināta konta, ko slēgt.',
];
