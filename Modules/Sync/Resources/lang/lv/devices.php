<?php

declare(strict_types=1);

return [
    'heading' => 'Ierīces un sinhronizācija',

    'enable_sync' => 'Ieslēgt sinhronizāciju',
    'enable_sync_help' => 'Droši koplietojiet savus datus starp uzticamām ierīcēm. Nepieciešama lietotnes bloķēšana.',

    'app_lock_notice' => 'Vispirms iestatiet lietotnes bloķēšanu, lai ieslēgtu sinhronizāciju.',
    'go_to_app_lock' => 'Doties uz lietotnes bloķēšanu',

    'encrypted_at_rest' => 'Dati šifrēti glabāšanā',
    'encrypted_at_rest_help' => 'Jūsu dati ir aizsargāti ar lietotnes bloķēšanas paroles frāzi.',
    'on' => 'Ieslēgts',
    'securing' => 'Aizsargā jūsu datus…',
    'do_not_close' => 'Neaizveriet šo logu.',
    'not_encrypted_offer' => 'Jūsu dati glabāšanā nav šifrēti. Iestatiet šifrēšanu, lai tos pasargātu, ja šī ierīce pazūd vai tiek nozagta.',
    'enable_encryption' => 'Ieslēgt šifrēšanu',

    'your_devices' => 'Jūsu ierīces',

    // Iestatījumos paliek norāde uz pārvietoto sadaļu; pati sadaļa
    // tagad atrodas /sync kopā ar statusu un sinhronizācijas darbību.
    'moved_help' => 'Sapārošana, ierīču nosaukumi un šifrēšana tagad atrodas kopā ar sinhronizācijas statusu.',
    'moved_cta' => 'Atvērt sinhronizāciju un ierīci',
    'device_name' => 'Ierīces nosaukums',
    'save' => 'Saglabāt',
    'peer_default_name' => 'Sapārotā ierīce',
    'rename_device' => 'Pārdēvēt ierīci',
    'this_device' => 'Šī ierīce',
    'removed' => 'Noņemta',
    'confirmed' => 'Apstiprināta',
    'awaiting_confirmation' => 'Gaida apstiprinājumu',
    'safety_number_words' => 'Drošības numura vārdi:',
    'paired' => 'Sapārota',
    'remove_aria' => 'Noņemt: :name',
    'remove' => 'Noņemt',
    'pair_new_device' => 'Sapārot jaunu ierīci',

    'relay_endpoint' => 'Retranslatora adrese',
    'relay_endpoint_help' => 'Neobligāti. Ja norādīts, bezsaistes ierīces sinhronizējas caur šo retranslatoru. Atstājiet tukšu, lai izmantotu tikai tiešu LAN&#8209;savienojumu.',
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
    'encryption_enabled_body' => 'Jūsu dati tagad glabāšanā ir šifrēti.',
    'done_encryption_enabled' => 'Gatavs — šifrēšana ieslēgta',
    'encryption_failed' => 'Šifrēšanas iestatīšana neizdevās',
    'encryption_failed_body' => 'Jūsu dati netika mainīti. Dublējums tika saglabāts.',
    'close_no_changes' => 'Aizvērt — izmaiņas netika veiktas',

    'remove_this_device' => 'Noņemt šo ierīci',
    'removing' => 'Noņem:',
    'remove_rotates_key' => 'Noņemot šo ierīci, šifrēšanas atslēga tiek nomainīta, tāpēc tā vairs nesaņems turpmākos atjauninājumus.',
    'remove_cannot_erase' => 'Datus, kas tajā ierīcē jau atrodas, izdzēst nav iespējams. Ja šī ierīce ir pazaudēta vai nozagta, uzskatiet visus tajā esošos datus par atklātiem.',
    'remove_device' => 'Noņemt ierīci',
    'keep_device' => 'Paturēt ierīci',
    'rotating_key' => 'Maina šifrēšanas atslēgu…',

    'flash' => [
        'app_lock_first' => 'Vispirms iestatiet lietotnes bloķēšanu, lai ieslēgtu sinhronizāciju.',
        'enable_failed' => 'Neizdevās ieslēgt sinhronizāciju. Pārliecinieties, ka lietotnes bloķēšana ir aktīva, un mēģiniet vēlreiz.',
        'cannot_remove_self' => 'Šo ierīci nevarat noņemt — tā ir ierīce, kuru pašlaik izmantojat.',
        'remove_failed' => 'Neizdevās noņemt ierīci. Mēģiniet vēlreiz.',
        'app_lock_first_settings' => 'Vispirms iestatiet lietotnes bloķēšanu, lai mainītu sinhronizācijas iestatījumus.',
        'relay_cleared' => 'Retranslatora adrese notīrīta.',
        'relay_saved' => 'Retranslatora adrese saglabāta.',
        'relay_save_failed' => 'Neizdevās saglabāt retranslatora adresi: :message',
    ],
];
