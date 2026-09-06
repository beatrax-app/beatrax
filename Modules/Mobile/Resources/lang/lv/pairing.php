<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Sapārotā ierīce',
    'page_title' => 'Sapārot ierīci',

    'scan_heading' => 'Sapārojiet šo ierīci',
    'scan_subtitle' => 'Pavērsiet kameru pret kodu, kas redzams otrā ierīcē.',
    'camera_permission_pending' => 'Piekļuve kamerai ir izslēgta. Atļaujiet to Beatrax ierīces iestatījumos un mēģiniet vēlreiz.',
    'open_camera' => 'Atvērt kameru',
    'opening_camera' => 'Gaida piekļuvi kamerai…',
    'close_camera' => 'Aizvērt kameru',
    'viewfinder_aria' => 'Kameras skatu meklētājs — pavērsiet to pret kodu otrā ierīcē',
    'viewfinder_idle' => 'Kamera ir izslēgta. Atveriet to, lai noskenētu kodu, kas redzams otrā ierīcē.',
    'scan_prompt' => 'Noskenējiet kodu otrā ierīcē',
    'enter_code_instead' => 'Ievadīt kodu ar roku',

    'enter_heading' => 'Ievadiet kodu',
    'camera_off' => 'Piekļuve kamerai ir izslēgta. Tā vietā ievadiet kodu no otras ierīces.',
    'camera_off_no_search' => 'Kameras piekļuve ir izslēgta, un otras ierīces meklēšana tīklā iPhone vēl nedarbojas — tāpēc ierakstīts kods to pats neatradīs. Ieslēdz Beatrax kameras piekļuvi ierīces iestatījumos un noskenē otrā ierīcē redzamo kodu vai nosūti kodu šeit, un šis ekrāns pajautās, kur tā atrodas.',
    'no_search' => 'Otras ierīces meklēšana tīklā iPhone vēl nedarbojas, tāpēc ierakstīts kods to pats neatradīs. Skenē kodu ar kameru — tai tīkla meklēšana nav vajadzīga. Ja nevari skenēt, nosūti kodu, un šis ekrāns pajautās, kur otra ierīce atrodas.',
    'word_code_aria' => 'Ievadiet vārdu kodu no otras ierīces',
    'initiator_address' => 'Kur ir otra ierīce?',
    'initiator_address_help' => 'Tās adrese šajā tīklā, kā hosts un ports. Dators to rāda sadaļā Ierīces un sinhronizācija. Nosūti kodu vēlreiz, kad esi to ievadījis.',
    'submit_code' => 'Nosūtīt kodu',
    'cancel' => 'Atcelt',
    'skip_import' => 'Turpināt bez importēšanas',

    'confirm_heading' => 'Salīdziniet šos vārdus ar otru ierīci',
    'safety_words_aria' => 'Drošības numura vārdi: :words',
    'confirm_body' => 'Abās ierīcēs jābūt tieši tiem pašiem vārdiem. Ja tie atšķiras, pieskarieties Atcelt — iespējams, notiek starpnieka uzbrukums.',
    'awaiting_peer' => 'Gaida otras ierīces apstiprinājumu…',
    'confirm_match' => 'Apstiprināt — tie sakrīt',

    'success_heading' => 'Ierīce sapārota',
    'success_body' => 'Šī ierīce tagad ir uzticama. Dati tiks sinhronizēti, tiklīdz izveidosies savienojums.',
    'encryption_incomplete' => 'Ierīce ir sapārota, taču tajā saglabāto datu šifrēšana netika pabeigta. Dati vēl netiek glabāti šifrēti.',
    'done' => 'Gatavs',

    'errors' => [
        'relay_unreachable' => 'Nevar sasniegt otru ierīci. Pārliecinieties, ka abas ir vienā tīklā un ka datorā ir ieslēgta sinhronizācija.',
        'no_road_home' => 'Šī ierīce nevar meklēt tīklā, un noskenētajā kodā nav otras ierīces adreses. Palūdz tai parādīt jaunu kodu un noskenē to.',
        'invalid_code' => 'Šis kods nav derīgs vai tam ir beidzies termiņš. Palūdziet otrai ierīcei izveidot jaunu.',
        'already_under_way' => 'Šī ierīce šo kodu jau ir pieņēmusi un gaida otras ierīces apstiprinājumu. Ja tas nepienāk, palūdziet jaunu kodu un izmantojiet to.',
        'vouched_but_refused' => 'Otrai ierīcei šis kods joprojām ir, bet šī ierīce to nevarēja pieņemt. Palūdziet tai jaunu kodu un izmantojiet to.',
        'code_incomplete' => 'Šis kods nav pilnīgs. Salīdziniet to ar otru ierīci un ievadiet to pilnībā.',
        'initiator_address_invalid' => 'Šī nav adrese, uz kuru šī ierīce var zvanīt. Ievadi to kā hostu un portu, piemēram, 192.168.1.20:8100.',
        'code_not_accepted' => 'Neviena šī tīkla ierīce nepieņēma šo kodu. Pārbaudi kodu un vai otra ierīce to joprojām rāda.',
        'no_peer_answered' => 'Šajā tīklā uz šo kodu neviens neatbildēja. Pārbaudi, vai otrā ierīcē darbojas sinhronizācija, vai arī noskenē tās kodu ar kameru — kamerai tīklā nekas nav jāmeklē.',
        'no_peer_answered_ios' => 'Šajā tīklā uz šo kodu neviens neatbildēja. Otras ierīces meklēšana tīklā iPhone vēl nedarbojas, tāpēc noskenē tās kodu ar kameru.',
        'no_peer_answered_camera_off' => 'Šajā tīklā uz šo kodu neviens neatbildēja. Otras ierīces meklēšana tīklā iPhone vēl nedarbojas, un piekļuve kamerai ir izslēgta — tāpēc ierīces iestatījumos atkal atļauj kameru Beatrax un noskenē otras ierīces kodu.',
        'rate_limited' => 'Pārāk daudz mēģinājumu. Pagaidi minūti un mēģini vēlreiz.',
        'identity_locked' => 'Ierīces identitāte ir bloķēta. Atbloķējiet lietotni un mēģiniet vēlreiz.',
        'identity_needs_lock' => 'Vispirms iestatiet lietotnes bloķēšanu — tā aizsargā ierīces identitāti.',
        'safety_number_changed' => 'Otra ierīce mainījās salīdzināšanas laikā. Pirms apstiprināšanas vēlreiz pārbaudiet zemāk esošos vārdus.',
    ],
];
