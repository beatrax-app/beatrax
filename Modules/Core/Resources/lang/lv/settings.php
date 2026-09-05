<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Attēlojums',
        'money' => 'Nauda',
        'insights' => 'Ieskati un brīdinājumi',
        'security' => 'Drošība un ierīces',
        'data' => 'Imports un dati',
        'app' => 'Lietotne',
    ],

    'title' => 'Iestatījumi',
    'subtitle' => 'Preferences tam, kā jūsu finanses tiek rādītas lietotnē.',

    'appearance' => [
        'heading' => 'Izskats',
        'theme' => 'Motīvs',
        'theme_light' => 'Gaišs',
        'theme_dark' => 'Tumšs',
        'theme_system' => 'Sistēmas',
        'theme_help' => 'Sistēmas motīvs seko operētājsistēmas gaišajam vai tumšajam iestatījumam.',
    ],

    'language' => [
        'apply' => 'Lietot',
        'heading' => 'Valoda',
        'label' => 'Saskarnes valoda',

        'system' => 'Sistēmas',
        'help' => 'Maina ekrānā redzamos vārdus un to, kā tiek rakstītas summas. Sistēmas iestatījums seko pārlūka vai operētājsistēmas valodai, pēc noklusējuma izmantojot angļu valodu.',
    ],

    'country' => [
        'heading' => 'Valsts',
        'label' => 'Tava valsts',
        'help' => 'Nosaka, kuras valsts nodokļu noteikumus, valsts iestādes un bankas maksas lietotne atpazīst. Valodu un summu pierakstu tas nemaina.',
        'choose' => 'Izvēlieties valsti…',
        'switch_note' => 'Pārslēgšana pievieno jaunas kategorijas — esošās atzīmes netiek mainītas.',

        'wording_note' => 'Nodokļu kategoriju nosaukumi tiek rādīti tavā valodā; :country nodokļu deklarācija lieto savus terminus.',

        'countries' => [
            'at' => 'Austrija',
            'be' => 'Beļģija',
            'bg' => 'Bulgārija',
            'ca' => 'Kanāda',
            'ch' => 'Šveice',
            'cy' => 'Kipra',
            'cz' => 'Čehija',
            'de' => 'Vācija',
            'dk' => 'Dānija',
            'ee' => 'Igaunija',
            'es' => 'Spānija',
            'fi' => 'Somija',
            'fr' => 'Francija',
            'gb' => 'Apvienotā Karaliste',
            'gr' => 'Grieķija',
            'hr' => 'Horvātija',
            'hu' => 'Ungārija',
            'ie' => 'Īrija',
            'is' => 'Islande',
            'it' => 'Itālija',
            'lt' => 'Lietuva',
            'lu' => 'Luksemburga',
            'lv' => 'Latvija',
            'mt' => 'Malta',
            'nl' => 'Nīderlande',
            'no' => 'Norvēģija',
            'pl' => 'Polija',
            'pt' => 'Portugāle',
            'ro' => 'Rumānija',
            'se' => 'Zviedrija',
            'si' => 'Slovēnija',
            'sk' => 'Slovākija',
            'us' => 'Amerikas Savienotās Valstis',
        ],
    ],

    'currency_display' => [
        'heading' => 'Summas attēlojums',
        'label' => 'Summu noklusējuma skats',
        'eur_only' => 'Norēķina summa',
        'original' => 'Sākotnējā summa',
        'help' => 'Attiecas uz darījumu sarakstu un Pārskata kopsummām. Katrā lapā to joprojām varat pārslēgt, bet tikai darījumu sarakstā.',
    ],

    'base_currency' => [
        'heading' => 'Pārskata valūta',
        'label' => 'Pārskata valūta',
        'help' => 'Visas kopsummas un apkopojumi tiek konvertēti šajā valūtā. Katram kontam blakus joprojām tiek rādīta tā sākotnējā valūta.',
    ],

    'exchange_rates' => [
        'heading' => 'Valūtas kursi',
        'fetch_online' => 'Iegūt aktuālos kursus tiešsaistē',
        'online_on' => 'Kursi katru dienu tiek iegūti no ECB vai no Frankfurter, ja ECB nav sasniedzams. Tikai valūtu pāru pieprasījumi — nekādu personas datu.',
        'last_updated' => 'Pēdējoreiz atjaunināts: :date.',
        'online_off' => 'Joprojām tiek izmantoti jau esošie kursi, bet komplektā iekļautais momentuzņēmums kalpo kā rezerve. Nekādi dati šo ierīci nepamet.',
        'fetch_aria' => 'Iegūt aktuālos valūtas kursus tiešsaistē',
        'refreshing' => 'Atsvaidzina…',
        'next_refresh' => 'Automātiskā atsvaidzināšana: reizi dienā',
        'refresh_gave_up' => 'Neizdevās atsvaidzināt kursus. Joprojām tiek izmantoti ierīcē esošie kursi.',
        'refresh_now' => 'Atsvaidzināt tagad',
    ],

    'period' => [
        'heading' => 'Periods',
        'label' => 'Perioda sākuma diena',
        'help' => 'No 1 līdz 28. Lielākā daļa lietotāju atstāj 1 (kalendārais mēnesis). Izvēlieties 25, ja alga pienāk 25. datumā un „jūsu mēnesis” sākas tieši tad.',

        'move_confirm' => 'Ja periods sākas :day. dienā, visas aplokšņu summas tiek pārkārtotas un saskaitītas kopā tur, kur divi mēneši saplūst vienā. Dienas atgriešana tās vairs nesadala.',
        'move_cancel' => 'Atcelt',
        'move_apply' => 'Lietot',
    ],

    'recurring' => [
        'heading' => 'Regulāro maksājumu atpazīšana',
        'window_label' => 'Atpazīšanas logs (mēneši)',
        'window_help' => 'Cik mēnešu vēstures skenēt, grupējot darījumus regulāros modeļos.',
        'income_label' => 'Ieņēmumu minimums (mazākajās vienībās)',
        'income_help' => 'Ieņēmumi zem šī sliekšņa netiek automātiski grupēti. Glabā mazākajās vienībās — :minor nozīmē :example. Iestatiet 0, lai slieksni izslēgtu.',
    ],

    'drift' => [
        'heading' => 'Izmaiņu brīdinājumi',
        'label' => 'Noklusējuma izmaiņu brīdinājuma slieksnis',
        'help' => 'Brīdinājumi tiek nosūtīti, kad regulārā maksājuma jaunākā summa no iepriekšējās atšķiras vairāk par šo procentu. Atsevišķām sērijām norādītie iestatījumi ir noteicošie.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (noklusējums)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Saglabāt iestatījumus',
    'saved' => 'Saglabāts.',

    'anomaly_heading' => 'Noviržu atpazīšana',
    'notifications_heading' => 'Paziņojumi',

    'forecasting' => [
        'heading' => 'Prognozēšana',
        'intro' => 'Beatrax prognozē jūsu atlikumu, balstoties uz kontu pašreizējo stāvokli. Kontiem bez konta izraksta atlikumiem (PayPal, vecie CSV importi) šeit norādiet sākuma atlikumu, lai prognozes sāktos no zināma punkta.',
        'no_accounts' => 'Vēl nav neviena konta — importējiet konta izrakstu, lai to pievienotu.',
    ],

    'auto_import' => [
        'heading' => 'Automātiskais imports',
        'label' => 'Automātiski importēt no nomešanas mapes',

        'active_html' => 'Nomešanas mape ir aktīva. Beatrax ik pēc 5 minūtēm pārbauda <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code>, meklējot jaunus failus.',
        'inactive_html' => 'Kad ieslēgts, Beatrax ik pēc 5 minūtēm pārbauda <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code>, meklējot <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> un <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> failus, un importē tos pa to pašu apstrādes ķēdi, ko vednis. Apstrādātie faili tiek pārvietoti uz <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, lai tie nekad netiktu importēti divreiz.',
        'active_phone_html' => 'Nomešanas mape ir aktīva. Beatrax fonā pārbauda <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code>, meklējot jaunus failus. Kad fona pārbaude notiks, izlemj tavs tālrunis — tās var būt minūtes vai stundas.',
        'inactive_phone_html' => 'Kad ieslēgts, Beatrax fonā pārbauda <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code>, meklējot <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> un <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> failus, un importē tos pa to pašu apstrādes ķēdi, ko vednis. Kad fona pārbaude notiks, izlemj tavs tālrunis — tās var būt minūtes vai stundas. Apstrādātie faili tiek pārvietoti uz <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, lai tie nekad netiktu importēti divreiz.',
    ],

    'aliases' => [
        'heading' => 'Aizstājvārdi',
        'intro' => 'Pārskatiet un rediģējiet saprotamos nosaukumus, kas Beatrax iemācīti neskaidriem konta izraksta aprakstiem.',
        'manage' => 'Pārvaldīt aizstājvārdus →',
    ],

    'tax_heading' => 'Nodokļi',
    'data_backup_heading' => 'Dati un dublējumi',

    'about_updates' => [
        'heading' => 'Par atjauninājumiem',
        'body' => 'Pēc instalēšanas Beatrax atjaunina sevi automātiski. Kad ir uzstādīta pati pirmā versija, nākamās pienāk ar paziņojumu lietotnē — GitHub vairs nav jāapmeklē. Ja kāds atjauninājums neizdotos, jaunāko instalētāju vienmēr varat lejupielādēt manuāli laidienu lapā.',
        'body_phone' => 'Šeit Beatrax sevi neatjaunina. Tālruņa lietotnes jaunās versijas pienāk caur App Store vai Google Play, tāpat kā pārējās jūsu lietotnes. Laidienu lapā ir uzskaitīts, kas katrā ir mainījies.',
        'check_label' => 'Automātiski meklēt atjauninājumus',
        'check_on' => 'Beatrax pajautā laidienu plūsmai, vai pastāv jaunāka parakstīta versija. Nekas netiek lejupielādēts, kamēr jūs pats neizvēlaties to instalēt.',
        'check_off' => 'Atjauninājumi netiek meklēti un nekas nepamet šo ierīci. Jaunās versijas atradīsiet, pats atverot laidienu lapu.',
        'open_releases' => 'Atvērt laidienu lapu →',
    ],

    'privacy' => [
        'heading' => 'Privātuma politika',
        'body' => 'Beatrax tur tavas finanses tavās paša ierīcēs. Politika skaidro, ko tas nozīmē, ko sūta izvēles tiešsaistes funkcijas un kā noņemt savus datus.',
        'open' => 'Lasīt privātuma politiku →',
        'url_hint' => 'Ja saite neatveras, apmeklē:',
    ],

    'first_run_tour' => [
        'heading' => 'Pirmās palaišanas ievads',
        'body' => 'Palaidiet iestatīšanas vedni vēlreiz, ja vēlaties atkārtoti iziet ievada soļus.',
        'run_again' => 'Palaist iestatīšanas vedni vēlreiz',
    ],

    'developer' => [
        'heading' => 'Izstrādātājam',
        'label' => 'Izstrādes konsole lietotnē',
        'help' => 'Rādīt izstrādes konsoli adresē /dev. Katrā pieteikšanās reizē atiestata paplašināto iestatījumu slēdzi.',
        'aria' => 'Izstrādes režīms',
    ],

    'errors' => [
        'period_move_failed' => 'Budžeta mēnesi neizdevās pārvietot, tāpēc tas palika, kur bija.',
        'currency_required' => 'Izvēlieties valūtu.',
        'window_months' => 'Izvēlieties no 2 līdz 60 mēnešiem.',
        'threshold' => 'Izvēlieties slieksni no 1%, 2%, 5%, 10%, 25% vai 50%.',
        'amount' => 'Ievadiet summu no :zero un vairāk.',
        'period_day' => 'Izvēlieties dienu no 1 līdz 28.',
        'currency_view' => 'Izvēlieties vienu no pieejamajām iespējām.',
    ],
];
