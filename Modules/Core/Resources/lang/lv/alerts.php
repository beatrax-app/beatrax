<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Sistēmas brīdinājumi',

    'actions' => [
        'download_and_install' => 'Lejupielādēt un instalēt',
        'download_and_install_aria' => 'Lejupielādēt un instalēt — atzīmē sistēmas brīdinājumu #:id kā atrisinātu',
        'skip_version' => 'Izlaist šo versiju',
        'release_notes' => 'Laidiena piezīmes →',
        'update_now' => 'Atjaunināt tagad',
        'update_now_aria' => 'Atjaunināt tagad — atzīmē sistēmas brīdinājumu #:id kā atrisinātu',
        'remind_later' => 'Atgādināt vēlāk',
        'mark_resolved' => 'Atzīmēt kā atrisinātu',
        'mark_resolved_aria' => 'Atzīmēt kā atrisinātu — sistēmas brīdinājums #:id',
        'assign_in_budgets' => 'Sadalīt Budžetos',
        'dismiss' => 'Aizvērt',
        'dismiss_aria' => 'Aizvērt — sistēmas brīdinājums #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'budžeta brīdinājumus',
        'daily-triggers' => 'ikdienas atgādinājumus un kopsavilkumu',
    ],

    'messages' => [
        'update_available' => 'Pieejams atjauninājums — Beatrax :version. Nekas netiek lejupielādēts, kamēr neizvēlaties instalēt; pēc tam Beatrax aizveras un atveras no jauna ar jauno versiju.',
        'update_refused' => 'Beatrax lejupielādēja versiju :version un atteicās to instalēt — fails neatbilda izdevēja parakstam, tāpēc šajā ierīcē nekas netika mainīts. To var izraisīt bojāta lejupielāde. Ja tas atkārtojas, neinstalē Beatrax no šī avota.',
        'update_stale' => 'Jūs izmantojat versiju :current — versija :latest ir pieejama jau 30 dienas. Atjauniniet tagad.',
        'update_critical' => 'Pieejams kritisks atjauninājums — versija :version novērš :summary. Instalējiet to pēc iespējas ātrāk.',
        'backup_corrupt_with_path' => 'Dublējums, kas izveidots :timestamp, neizturēja integritātes pārbaudi. Pārbaudiet :path. Atrisiniet to, pirms paļaujaties uz dublējumiem.',
        'backup_corrupt_no_path' => 'Dublējums, kas tika sākts :timestamp, tika pārtraukts, pirms izveidojās kāds fails — avota datubāze neizturēja integritātes pārbaudi. Atrisiniet to, pirms paļaujaties uz dublējumiem.',
        'backup_write_failed' => ':timestamp sāktais dublējums netika pabeigts — datubāze izturēja pārbaudes, bet dublējuma failus neizdevās ierakstīt. Pārbaudi brīvo vietu un dublējumu mapes atļaujas.',
        'backup_restore_failed' => ':timestamp sāktā atjaunošana netika pabeigta. Tavi iepriekšējie dati pirms tam tika saglabāti failā :snapshot.',

        'backup_overdue' => 'Jaunākais pārbaudītais dublējums ir :hoursh vecs. Beatrax šo dublējumu izveido pati, reizi dienā, kamēr lietotne ir atvērta — ar roku nekas nav jāpalaiž. Ja tas paliek tik vecs, lietotne nav bijusi atvērta brīdī, kad pienāca ikdienas izpilde.',
        'backup_none_found' => 'Dublējumu mapē netika atrasts neviens pārbaudīts dublējums. Beatrax šo dublējumu izveido pati, reizi dienā, kamēr lietotne ir atvērta — ar roku nekas nav jāpalaiž.',
        'wal_mode_missing' => 'Datubāze nav WAL režīmā (pašlaik :mode), tāpēc saglabāšana var apstāties, kamēr darbojas fona uzdevums. Beatrax iestata WAL katrā palaišanas reizē, tāpēc restartēšana to parasti atrisina.',
        'synchronous_misconfigured' => 'Datubāzes noturības līmenis ir :level, nevis gaidītais NORMAL. Beatrax to iestata katrā palaišanas reizē, tāpēc restartēšana to parasti atrisina.',
        'oauth_scrub_set_failed' => 'OAuth noslēpumu maskēšana nedarbojas. Žurnālos un audita izrakstos līdz nākamajai veiksmīgajai ielādei var būt nemaskētas pilnvaras.',
        'oauth_reauth_required' => 'OAuth noslēpumi ir pārvietoti uz katra lietotāja krātuvi. Atkārtoti autorizējiet Gmail un Microsoft, lai atsāktu e-pasta skenēšanu. Vecais noslēpumu fails atgriešanai tika pārdēvēts par :file.',
        'oauth_reconsent' => 'Atkārtoti savienojiet savu :provider',
        'auth_recovery_code_consumed' => 'Atkopšanas kodu izmantoja :username.',
        'auth_recovery_code_failed' => 'Neizdevies atkopšanas koda mēģinājums lietotājam :username.',
        'auth_lock_hard_cap_reached' => 'Izrakstīšanās pēc pārāk daudziem neizdevušiemies PIN mēģinājumiem.',
        'open_banking_reconsent' => 'Atkārtoti savienojiet savu banku',
        'open_banking_nothing_imported' => 'Jūsu banka atsūtīja darījumus, bet Beatrax nevarēja reģistrēt nevienu no tiem, tāpēc jūsu uzskaitē nekas nenonāca. Atveriet sadaļas Atvērtā banku saskarne iestatījumus, lai redzētu kāpēc.',
        'auth_lock_corrupted_key' => 'Jūsu PIN nevar atbloķēt lietotni šajā ierīcē: saglabātā atslēga nav nolasāma. Piesakieties ar konta paroli, lai iestatītu jaunu PIN.',
        'sync_gdk_rewrap_failed' => 'Pēc lietotnes bloķēšanas paroles frāzes maiņas neizdevās atkārtoti ietīt GDK atslēgu saišķi — šifrētie dati var nebūt atgūstami, līdz saišķis tiek atkārtoti ietīts.',
        'worker_crashed' => 'Beatrax fona apstrāde negaidīti apstājās. Importēšana un e-pasta skenēšana ir pauzēta. Lai to restartētu, atveriet lietotni vēlreiz.',
        'auth_lock_key_material_stranded' => 'Šim kontam ir aktīva miera stāvokļa šifrēšana, taču neviens lietotnes bloķēšanas apvalks vairs netur datu atslēgu, tāpēc katra šifrētā piezīme, apraksts un darījuma partnera informācija tiek nolasīta kā tukša. Atjaunojiet šifrētu dublējumu, kas izveidots, kamēr atslēga vēl darbojās, vai iestatiet šo kontu no jauna ierīcē, kurai atslēga vēl ir.',
        'auth_lock_recovery_wrap_stale' => 'Konta parole tika mainīta, neietinot atkārtoti lietotnes bloķēšanas atkopšanas apvalku, tāpēc šī parole vairs neatver lietotnes bloķēšanu. PIN joprojām atver. Atkārtoti sasaistiet konta paroli bloķēšanas iestatījumos, kamēr PIN vēl ir zināms — citādi aiz aizmirsta PIN nepaliek nekas.',
        'reconnect_link' => 'Pievienot atkārtoti →',
        // i18n-review: lv · pots_category_link_retired — Latvian selects the FIRST
        // segment for zero, so that arm carries the plural. The alert is raised only
        // once a pot has released money, so it never renders; confirm the case
        // before this line is reused where zero can reach it.
        'pots_category_link_retired' => 'Aplokšņu budžets ir aizstājis ar kategoriju saistītās krājkases. Summa :amount no :count arhivētām krājkasēm atkal ir nepiešķirta un gaida, kad to sadalīsiet.|Aplokšņu budžets ir aizstājis ar kategoriju saistītās krājkases. Summa :amount no :count arhivētas krājkases atkal ir nepiešķirta un gaida, kad to sadalīsiet.|Aplokšņu budžets ir aizstājis ar kategoriju saistītās krājkases. Summa :amount no :count arhivētām krājkasēm atkal ir nepiešķirta un gaida, kad to sadalīsiet.',
        'notifications_deferred_pass_failed' => 'Beatrax šajā ierīcē nevarēja aprēķināt :pass, tāpēc daži var trūkt. Tas mēģinās vēlreiz katru reizi, kad atvērsiet lietotni.',
    ],
];
