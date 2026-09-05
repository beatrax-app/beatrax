<?php

declare(strict_types=1);

return [
    'heading' => 'Ierīces un sinhronizācija',

    'enable_sync' => 'Ieslēgt sinhronizāciju',
    'enable_sync_help' => 'Droši koplietojiet savus datus starp uzticamām ierīcēm. Nepieciešama lietotnes bloķēšana. Tiklīdz tā ir ieslēgta, dati tiek šifrēti un lietotnes bloķēšanu vairs nevar izslēgt.',

    'app_lock_notice' => 'Vispirms iestatiet lietotnes bloķēšanu, lai ieslēgtu sinhronizāciju.',
    'go_to_app_lock' => 'Doties uz lietotnes bloķēšanu',

    'identity_unreadable' => 'Šīs ierīces sinhronizācijas identitāte tika izveidota ar citu lietotnes bloķēšanu un vairs neatveras. Kamēr tā ir, šī ierīce nevar ne sinhronizēt, ne savienoties pārī. Atjaunojot datu bāzes dublējumu, ar kuru tā tika izveidota, tā atkal kļūst lasāma.',
    'identity_unreadable_replace_help' => 'Vari arī sākt no jauna: ierīce saņem jaunu identitāti, vecā paliek neizmantota, un iepriekš savienotās ierīces būs jāsavieno pārī atkārtoti.',
    'identity_unreadable_replace' => 'Izveidot šai ierīcei jaunu identitāti',

    'encrypted_at_rest' => 'Dati šifrēti glabāšanā',
    'encrypted_at_rest_scope' => 'Piezīmes, darījumu apraksti un maksājumu saņēmēju vārdi un IBAN ir šifrēti virsgrāmatā ar tavu lietotnes bloķēšanas paroles frāzi. Summas, datumi un tava paša konta nosaukums un IBAN nav šifrēti. Meklēšanas indekss glabā savu lasāmu kopiju no tā, kam tu maksā, no taviem darījumu aprakstiem un no tavām nodokļu piezīmēm, un daži tirgotāju nosaukumi ir lasāmi citviet datubāzes failā.',
    'on' => 'Ieslēgts',
    'securing' => 'Aizsargā jūsu datus…',
    'do_not_close' => 'Neaizveriet šo logu.',
    'encryption_progress_aria' => 'Šifrēšanas gaita',
    'not_encrypted_offer' => 'Jūsu dati nav šifrēti miera stāvoklī. Šifrēšana slēpj, kam maksājat, ja šī ierīce tiek pazaudēta vai nozagta — summas, datumi un meklēšanas indekss paliek lasāmi.',
    'enable_encryption' => 'Ieslēgt šifrēšanu',

    'your_devices' => 'Jūsu ierīces',

    'device_name' => 'Ierīces nosaukums',
    'save' => 'Saglabāt',
    'peer_default_name' => 'Sapārotā ierīce',
    'rename_device' => 'Pārdēvēt ierīci',
    'rename_device_caption' => 'Pārdēvēt',
    'this_device' => 'Šī ierīce',
    'removed' => 'Noņemta',
    'confirmed' => 'Apstiprināta',
    'awaiting_confirmation' => 'Gaida apstiprinājumu',
    'safety_number_words' => 'Drošības numura vārdi:',
    'paired' => 'Sapārota',
    'remove_aria' => 'Noņemt: :name',
    'remove' => 'Noņemt',
    'pair_new_device' => 'Sapārot jaunu ierīci',

    'pairing_waiting' => 'Pabeidziet pārošanu ar :name',
    'pairing_waiting_help' => 'Abiem ekrāniem jārāda vieni un tie paši seši vārdi, pirms pārošana ir spēkā. Atveriet to vēlreiz, lai tos salīdzinātu.',
    'pairing_waiting_resume' => 'Turpināt pārošanu',
    'pairing_waiting_lock_override' => 'Atbloķēšana atkārtoti atver šo pārošanu, nevis ļauj tai beigties, tāpēc tā ilgst ilgāk par jūsu iestatīto lietotnes bloķēšanas laiku. Tā beidzas, kad to pabeidzat vai atceļat.',

    'relay_endpoint' => 'Retranslatora adrese',
    'relay_endpoint_help' => 'Neobligāti. Ja norādīts, bezsaistes ierīces sinhronizējas caur šo retranslatoru. Atstājiet tukšu, lai izmantotu tikai tiešu LAN&#8209;savienojumu.',
    'relay_endpoint_help_phone' => 'Neobligāti. Ja norādīts, izmaiņas ceļo caur šo retranslatoru arī tad, kad jūsu ierīces nav vienā tīklā. Šī ierīce tās saņem, kad sinhronizējat no šī ekrāna — nekad fonā, jo lietotnes bloķēšana glabā vienīgo atslēgu. Atstājiet tukšu, lai izmantotu tikai tiešu LAN&#8209;savienojumu.',
    'relay_endpoint_aria' => 'Retranslatora adreses URL',
    'relay_insecure_warning' => 'Šī retranslatora adrese izmanto vienkāršu HTTP. Lai gan retranslators jūsu datus nekad neatšifrē, nedrošs savienojums atklāj tīkla novērotājiem šifrēto datu apjomu un laikus. Vislabākā privātuma nodrošināšanai izmantojiet <strong>https://</strong> adresi.',

    'enable_at_rest' => 'Ieslēgt šifrēšanu glabāšanā',
    'enable_at_rest_body' => 'Jūsu dati tiks šifrēti, izmantojot lietotnes bloķēšanas paroles frāzi. Pirms pārveides automātiski tiks izveidots dublējums.',
    'no_recovery_warning' => 'Ja pazaudējat lietotnes bloķēšanas paroles frāzi un jums nav ne dublējuma, ne citas uzticamas ierīces, datus atgūt nebūs iespējams.',
    'recover_help' => 'Lai atgūtu piekļuvi, sapārojiet šo ierīci no citas uzticamas ierīces vai izmantojiet atsevišķu šifrētu dublējumu.',
    'amounts_plaintext' => 'Summas glabāšanā netiek šifrētas — atlikumi un kopsummas paliek nolasāmi, lai mēneša kopsummas joprojām saskaitītos pareizi.',
    'search_plaintext' => 'Meklēšanas indekss glabā tirgotāju un aprakstu tekstu atklātā veidā, lai pilnteksta meklēšana turpinātu darboties.',
    'keep_unencrypted' => 'Atstāt datus nešifrētus',
    'encryption_enabled' => 'Šifrēšana ieslēgta',
    'encryption_enabled_scope' => 'Piezīmes, apraksti un tas, kam tu maksā, tagad ir šifrēti ar tavu lietotnes bloķēšanas paroles frāzi. Summas, datumi un meklēšanas indekss paliek lasāmi.',
    'done_encryption_enabled' => 'Gatavs — šifrēšana ieslēgta',
    'encryption_failed' => 'Šifrēšanas iestatīšana neizdevās',
    'encryption_failed_body' => 'Jūsu dati netika mainīti. Dublējums tika saglabāts.',
    'close_no_changes' => 'Aizvērt — izmaiņas netika veiktas',

    'remove_this_device' => 'Noņemt šo ierīci',
    'removing' => 'Noņem:',
    'remove_rotates_key' => 'Noņemot šo ierīci, šifrēšanas atslēga tiek nomainīta, tāpēc tā vairs nesaņems turpmākos atjauninājumus.',
    'remove_cannot_erase' => 'Datus, kas tajā ierīcē jau atrodas, izdzēst nav iespējams. Ja šī ierīce ir pazaudēta vai nozagta, uzskatiet visus tajā esošos datus par atklātiem.',
    'remove_is_local' => 'Tavām pārējām ierīcēm ir savs saraksts. Kamēr neizņemsi to arī tur, tās turpinās ar to sinhronizēties.',
    'remove_device' => 'Noņemt ierīci',
    'keep_device' => 'Paturēt ierīci',
    'rotating_key' => 'Maina šifrēšanas atslēgu…',

    'flash' => [
        'app_lock_first' => 'Vispirms iestatiet lietotnes bloķēšanu, lai ieslēgtu sinhronizāciju.',
        'enable_failed' => 'Neizdevās ieslēgt sinhronizāciju. Pārliecinieties, ka lietotnes bloķēšana ir aktīva, un mēģiniet vēlreiz.',
        'identity_replaced' => 'Šai ierīcei ir jauna sinhronizācijas identitāte. Savieno pārī pārējās ierīces vēlreiz.',
        'identity_replace_failed' => 'Neizdevās nolikt malā veco ierīces identitāti. Mēģini vēlreiz.',
        'cannot_remove_self' => 'Šo ierīci nevarat noņemt — tā ir ierīce, kuru pašlaik izmantojat.',
        'remove_failed' => 'Neizdevās noņemt ierīci. Mēģiniet vēlreiz.',
        'app_lock_first_settings' => 'Vispirms iestatiet lietotnes bloķēšanu, lai mainītu sinhronizācijas iestatījumus.',
        'relay_cleared' => 'Retranslatora adrese notīrīta.',
        'relay_saved' => 'Retranslatora adrese saglabāta.',
        'relay_save_failed' => 'Neizdevās saglabāt retranslatora adresi: :message',
    ],
    'app_lock_permanent' => 'Tiklīdz dati ir šifrēti, lietotnes bloķēšanu vairs nevar izslēgt — tā glabā vienīgo atslēgu, un ceļa atpakaļ uz nešifrētiem datiem nav.',
    'backlog_heading' => 'Gaida pievienošanu',
    'backlog_deferred' => 'Šī ierīce ir saņēmusi datus no citas ierīces un vēl nav tos pievienojusi tavai uzskaitei. Nekas nepazūd — tie tiek pievienoti automātiski, parasti mirklī.',
    'backlog_awaiting_key' => 'Šī ierīce ir saņēmusi datus, kuriem tai vēl nav atslēgas. Nekas nepazūd. Atver lietotni savienotajā ierīcē, kamēr šī ir atvērta, lai abas varētu savienoties un atslēgu nosūtīt.',
    'introduced_heading' => 'Par to galvo cita ierīce',
    'introduced_trust' => 'Cita tava ierīce ir nodevusi tālāk tādas ierīces identitāti, ar kuru šī nekad nav bijusi sapārota. Apstiprināšana ļauj šai ierīcei pārbaudīt, ko tā ierīce ir parakstījusi, un neko citu — pieslēgties šeit tā nevar, un atslēga tai nekad netiek nosūtīta. Nav otra ekrāna, ar ko salīdzināt, tāpēc tu uzticies tai ierīcei, kas identitāti nodeva tālāk.',
    'introduced_by' => 'Nodevusi ierīce :name',
    'introduced_confirmed' => 'Apstiprināta parakstiem',
    'introduced_unconfirmed' => 'Nav apstiprināta',
    'introduced_fingerprint' => 'Saņemtās atslēgas nospiedums:',
    // i18n-review: lv · introduced_withheld — the zero arm copies the genitive
    // plural health.skipped uses, so it reads "0 izmaiņu nav lasāmas"; a native
    // reader settles whether that arm wants "nav lasāmu" instead.
    'introduced_withheld' => ':count izmaiņu nav lasāmas, kamēr neapstiprini|:count izmaiņa nav lasāma, kamēr neapstiprini|:count izmaiņas nav lasāmas, kamēr neapstiprini',
    'introduced_confirm' => 'Apstiprināt šo ierīci',
    'introduced_dismiss' => 'Noraidīt',
    'introduced_dismiss_aria' => 'Noraidīt: :name',
];
