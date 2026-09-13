<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Par :subject',
        'close' => 'Aizvērt',
    ],

    'page_title' => 'Kur ir mani dati?',
    // i18n-review: lv · intro — the formal register matches the rest of this
    // file, but the em-dash list of connections and "ikdienas valūtas kursu
    // pieprasījums" are both constructions of mine. A native should say whether
    // the apposition reads, or wants a colon list instead.
    'intro' => 'Beatrax glabā visu šajā ierīcē. Nav ne Beatrax servera, ne mākoņa konta. Pats no sevis aiziet tikai viens pieprasījums — jaunas versijas pārbaude, ko varat izslēgt. Viss pārējais gaida jūs: pastkaste, banka caur Enable Banking, ikdienas valūtas kursu pieprasījums, ierīces, ko sapārojat sinhronizācijai, retranslators, ko konfigurējat, un ikviena saite, uz kuras noklikšķināt. Katrs no tiem to pasaka ekrānā, kurā to ieslēdzat.',

    'lives_here' => 'Jūsu dati atrodas šeit',
    'copy' => 'Kopēt',
    'copied' => 'Nokopēts',

    'location' => [
        'database' => 'Datubāze:',
        'artifacts_imports' => 'Importētie konta pārskati:',
        'artifacts_mail' => 'Skenētais pasts:',
        'artifacts_drop' => 'Uzraudzītā mape:',
        'backups' => 'Dublējumi:',
        'secrets' => 'Savienojumu pieteikšanās dati:',
        'logs' => 'Žurnāli:',
    ],

    'copy_aria' => [
        'database' => 'Kopēt datubāzes ceļu starpliktuvē',
        'artifacts_imports' => 'Kopēt importēto konta pārskatu ceļu starpliktuvē',
        'artifacts_mail' => 'Kopēt skenētā pasta ceļu starpliktuvē',
        'artifacts_drop' => 'Kopēt uzraudzītās mapes ceļu starpliktuvē',
        'backups' => 'Kopēt dublējumu ceļu starpliktuvē',
        'secrets' => 'Kopēt savienojumu pieteikšanās datu ceļu starpliktuvē',
        'logs' => 'Kopēt žurnālu ceļu starpliktuvē',
    ],

    'artifacts_heading' => 'Jūsu avota dokumenti neatrodas dublējumā',
    'artifacts_body' => 'Dublējumā ir datubāze un nekas cits. Konta pārskati, ko importējāt, pasts, ko ievilka skeneris, un čeki, ko ielikāt uzraudzītajā mapē, paliek tur, kur ir, — trijās iepriekš uzskaitītajās mapēs. Dublējuma pārvietošana uz drošu vietu tos nenokopē, tāpēc pilns arhīvs nozīmē paņemt līdzi arī šīs mapes — vai izmantot tālāk pieejamo Eksportēt visu, kas tās iesaiņo kopā ar dublējumu.',

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

    /** @link ../../../../../.docs/features/budgets/moving-the-budget-month.md#the-mapping-keep-the-distance-from-the-month-the-reader-is-in */
    'period' => 'Laika posms, pār kuru tiek mērīti tavi budžeti, tava pārskata lapa un ikviens „šī perioda“ skaitlis. „:label“ izšķir, kur tas sākas — iestati to nākamajā dienā pēc algas, un periods saturēs tieši to naudu, kas tā segšanai izmaksāta. Šīs dienas pārbīde pārkārto katru aplokšņu summu, ko jau esi sadalījis, un tur, kur divi vecie periodi saplūst vienā jaunā, to summas tiek saskaitītas; dienas atgriešana tās vairs nesadala.',

    /** @link ../../../../../.docs/features/position/architecture.md#composition-never-a-raw-select */
    'net_worth' => 'Viss, par ko Beatrax zina, ka tas ir tavs, mīnus viss, ko esi parādā: katra importēta vai pievienota konta atlikums, turklāt karšu un aizdevumu atlikumi tiek skaitīti pret tevi. Pilnīgāks par to, ko esi tai devis, tas nav — konts, ko Beatrax nekad nav redzējusi, šajā skaitlī nav. Citas valūtas atlikumi tiek pārrēķināti pēc zem skaitļa rādītā kursa, un tas, ko neizdevās novērtēt, tur ir nosaukts, nevis klusi izlaists.',
];
