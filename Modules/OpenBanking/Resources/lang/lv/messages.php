<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Iestatījumi',
        'heading' => 'Atvērtā banku saskarne',
        'subtitle' => 'Automātiski ielādējiet darījumus no ASN vai SNS, izmantojot Enable Banking — trešās puses PSD2 apkopotāju. Pēc noklusējuma izslēgts.',
        'toggle_label' => 'Ieslēgt atvērto banku saskarni',
        'toggle_connected' => 'Savienots ar :bank, izmantojot Enable Banking.',
        'toggle_off_help' => 'Pēc noklusējuma izslēgts. Nepieciešams vienreizējs apstiprinājums un vadīta iestatīšana.',
        'connect_another' => 'Pievienot citu banku',
        // i18n-review: lv · page.credentials_unreadable — three genitives stack
        // before the noun: "atvērtās banku saskarnes piekļuves datus". Whether
        // the full term belongs here, or the screen already supplies it, is open.
        'credentials_unreadable' => 'Šajā ierīcē saglabātos atvērtās banku saskarnes piekļuves datus nevar nolasīt, tāpēc Beatrax nevar izveidot savienojumu ar jūsu banku.',
        'credentials_unreadable_next' => 'Veiciet vadīto iestatīšanu vēlreiz, lai tos aizstātu. Jau importētos darījumus tas neietekmē.',
        'reconfirm_body' => 'Jūsu apstiprinājuma termiņš beidzās, pirms savienojums tika pabeigts. Apstipriniet vēlreiz, lai pabeigtu atvērtās banku saskarnes ieslēgšanu.',
        'reconfirm_button' => 'Apstiprināt vēlreiz un pabeigt',
    ],

    'status_row' => [
        'heading' => 'Atvērtā banku saskarne',
        'manage' => 'Pārvaldīt atvērto banku saskarni',
        'not_connected' => 'Nav pievienota neviena banka. Pievienojiet banku, lai darījumus importētu automātiski.',
        'expired' => 'Piekrišanas termiņš beidzies — nepieciešams atkārtots savienojums.',
        'revoked' => 'Tava banka pārtrauca savienojumu — savienojies no jauna.',
        'connected' => 'Savienots ar :bank, izmantojot Enable Banking. Pēdējā sinhronizācija :when.',
        'never' => 'nekad',
    ],

    'transparency' => [
        'aggregator_label' => 'Apkopotājs',
        'bank_label' => 'Banka',
        'consent_status_label' => 'Piekrišanas statuss',
        'pill_expired' => 'Beidzies — savienojiet no jauna',
        'pill_expiring' => 'Drīz beigsies',
        'pill_connected' => 'Savienots',
        'pill_revoked' => 'Pārtraukusi tava banka — savienoties no jauna',
        'whats_fetched_label' => 'Kas tiek ielādēts',
        'whats_fetched' => 'Grāmatotie darījumi un atlikumi, pēdējās 90 dienas',
        'last_successful_sync_label' => 'Pēdējā veiksmīgā sinhronizācija',
        'never' => 'Nekad',
        'last_attempt_label' => 'Pēdējais mēģinājums',
        'last_attempt_failed' => ':when — neizdevās (:reason)',
        'reason_consent_expired' => 'piekrišanas termiņš beidzies',
        'reason_error' => 'kļūda',
        'reason_truncated' => 'apturēts pāragri',
        'reason_nothing_imported' => 'neko neizdevās reģistrēt',
        'reason_consent_revoked' => 'pārtraukusi tava banka',
        'disconnect_button' => 'Atvienot',
    ],

    'consent_banner' => [
        'heading' => 'Piekrišanas termiņš beidzies — savienojiet no jauna',
        'heading_revoked' => 'Tava banka pārtrauca savienojumu',
        'body' => 'Pēdējā veiksmīgā sinhronizācija bija :when. Savienojiet no jauna, lai atsāktu automātisko sinhronizāciju.',
        'body_revoked' => 'Tava banka vai Enable Banking atsauca piekļuvi, tāpēc sinhronizācija apstājās. Pēdējā veiksmīgā sinhronizācija bija :when. Savienojies no jauna, lai tā turpinātos.',
        'never' => 'nekad',
        'reconnect' => 'Savienot no jauna',
    ],

    'sync' => [
        'review_import' => 'Pārskatīt importu',
        'reconnect_first' => 'Vispirms savienojiet no jauna',
        'auto_caption' => 'Sinhronizējas automātiski reizi dienā.',
        'sync_now' => 'Sinhronizēt tagad',

        'consent_expired' => 'Piekrišanas termiņš beidzies — savienojiet no jauna.',
        'unavailable' => 'Enable Banking īslaicīgi nav pieejams. Mēģiniet vēlreiz pēc brīža.',
        'new_found' => 'Atrasti :count jaunu darījumu.|Atrasts :count jauns darījums.|Atrasti :count jauni darījumi.',
        'none' => 'Nav jaunu darījumu.',
        'none_importable' => 'Tava banka atsūtīja darījumus, bet nevienu no tiem neizdevās reģistrēt. Atver importa pārskatu, lai redzētu kāpēc.',
        'in_progress' => 'Sinhronizācija jau notiek. Mēģiniet vēlreiz pēc brīža.',
        'truncated' => 'Tavai bankai bija vairāk darījumu, nekā viena sinhronizācija spēj ielādēt, tāpēc šī izpilde tika apturēta pāragri. Nekas netika reģistrēts kā sinhronizēts — nākamā sinhronizācija sāksies tajā pašā vietā.',
    ],

    'disconnect' => [
        'heading' => 'Atvienot atvērto banku saskarni?',
        'body' => 'Tādējādi tiek dzēsti saglabātie Enable Banking piekļuves dati un piekrišana. Automātiskā sinhronizācija tiek nekavējoties pārtraukta. Jau importētos darījumus tas neietekmē.',
        'confirm' => 'Atvienot',
        'cancel' => 'Palikt savienotam',
    ],

    'ics' => [
        'section_label' => 'Faila imports — piekļuves dati netiek glabāti',
        'heading' => 'ICS kredītkartes konta izraksts',
        'step_login' => 'Piesakieties',
        'step_download' => 'Lejupielādējiet konta izrakstu',
        'pdf_statement' => 'PDF konta izraksts',
        'step_drop' => 'Ievelciet to zemāk',
        'drop_zone_label' => 'Ievelciet konta izraksta failu šeit',
        'drop_zone_hint' => 'vai izvēlieties failu',
        'browse_aria' => 'Izvēlēties ICS konta izraksta failu',
        'import_button' => 'Importēt konta izrakstu',
        'validation' => [
            'required' => 'Ievelciet ICS konta izrakstu, ko lejupielādējāt no Mijn ICS.',
            'max' => 'Šis fails ir pārāk liels. ICS PDF konta izraksti parasti ir mazāki par 1 MB.',
            'extensions' => 'Šis nav PDF fails. Mijn ICS eksportē tikai PDF konta izrakstus.',
        ],
        'could_not_read' => 'Neizdevās nolasīt :filename. Pilns kļūdas apraksts ir /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Pirms pievienojat trešo pusi',
        'body' => 'Ieslēdzot atvērto banku saskarni, jūsu piekrišana bankas pieteikšanās datiem un pēc tam darījumu un atlikumu dati tiek nosūtīti tieši no šīs ierīces uz Enable Banking un jūsu banku. Beatrax neuztur serveri, kas šos datus redzētu — bet Enable Banking un jūsu banka tos redz. Tas atšķiras no visām pārējām Beatrax importa metodēm, kuras datus nekur nesūta.',
        'acknowledge' => 'Es saprotu, ka mani darījumu dati tiks kopīgoti ar Enable Banking un manu banku.',
        'confirm' => 'Ieslēgt atvērto banku saskarni',
        'cancel' => 'Atcelt',
    ],

    'wizard' => [
        'heading' => 'Pievienojiet savu banku',
        'intro' => 'Beatrax izmanto jūsu paša Enable Banking lietotni, tāpēc jūsu piekļuves dati nekad nenonāk koplietotā serverī. Šī ir vienreizēja iestatīšana katrai bankai.',

        'step1_title' => 'Izveidojiet vietējo atslēgu pāri',
        'step1_body' => 'Beatrax šajā ierīcē izveido RSA atslēgu pāri. Privātā atslēga to nekad nepamet.',
        'generate_keypair' => 'Izveidot atslēgu pāri',
        'public_key_label' => 'Publiskā atslēga',
        'copy_public_key' => 'Kopēt publisko atslēgu',
        'copied' => 'Nokopēts',
        'redirect_uri_label' => 'Novirzīšanas URI',
        'copy_redirect_uri' => 'Kopēt novirzīšanas URI',

        'step2_title' => 'Reģistrējiet lietotni Enable Banking portālā',
        'step2_body' => 'Atveriet Enable Banking izstrādātāju portālu, izveidojiet lietotni un ielīmējiet 1. solī iegūto publisko atslēgu un novirzīšanas URI.',
        'open_portal' => 'Atvērt Enable Banking portālu ↗',

        'step3_title' => 'Ielīmējiet savu lietotnes ID',
        'application_id_label' => 'Lietotnes ID',
        'step3_help' => 'Tiek glabāts vietējā failā ārpus datubāzes, un to varat lasīt tikai jūs. Tas identificē jūsu lietotni pakalpojumā Enable Banking, tāpēc ceļo līdzi katram pieprasījumam — jūsu privātā atslēga nekad.',

        'step4_title' => 'Izvēlieties savu banku',
        'via_enable_banking' => 'caur Enable Banking',
        'other_institution' => 'Cita iestāde',
        'institution_id_placeholder' => 'Iestādes id',

        'step5_title' => 'Pabeidziet piekrišanu pārlūkā',
        'step5_body' => 'Noklikšķiniet zemāk, lai atvērtu savas bankas pieteikšanās un piekrišanas ekrānu. Pabeidziet pieteikšanos un divpakāpju apstiprinājumu, un jūs automātiski atgriezīsieties šeit, lai pabeigtu atvērtās banku saskarnes ieslēgšanu.',
        // i18n-review: lv · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Pieskarieties zemāk, lai atvērtu savas bankas pieteikšanās un piekrišanas ekrānu. Pabeidziet pieteikšanos un divpakāpju apstiprinājumu, un jūs automātiski atgriezīsieties šeit, lai pabeigtu atvērtās banku saskarnes ieslēgšanu.',

        'cancel' => 'Atcelt',
        'continue' => 'Turpināt →',
        'continue_to_bank' => 'Turpināt uz :bank →',
        'your_bank' => 'savu banku',

        'errors' => [
            'save_keypair_failed' => 'Neizdevās saglabāt atslēgu pāri diskā — pārbaudiet slepeno datu direktorijas atļaujas un mēģiniet vēlreiz.',
            'generate_failed' => 'Neizdevās izveidot atslēgu pāri šajā ierīcē — pārbaudiet savu OpenSSL konfigurāciju.',
            'export_failed' => 'Neizdevās eksportēt izveidoto atslēgu pāri.',
            'read_public_failed' => 'Neizdevās nolasīt izveidoto publisko atslēgu.',
            'generate_first' => 'Pirms turpināt, izveidojiet atslēgu pāri.',
            'paste_application_id' => 'Pirms turpināt, ielīmējiet lietotnes ID no Enable Banking portāla.',
            'save_application_id_failed' => 'Neizdevās saglabāt lietotnes ID diskā — pārbaudiet slepeno datu direktorijas atļaujas un mēģiniet vēlreiz.',
            'choose_bank' => 'Pirms turpināt, izvēlieties banku.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Vispirms pabeidziet atvērtās banku saskarnes iestatīšanu.',
        'no_bank_chosen' => 'Pirms savienošanas izvēlieties banku.',
        'no_consent_url' => 'Enable Banking neatgrieza piekrišanas URL.',
        'unparseable_consent_url' => 'Enable Banking atgrieza nenolasāmu piekrišanas URL.',
        'non_public_consent_host' => 'Enable Banking atgrieza nepublisku piekrišanas resursdatoru.',
        'unsafe_consent_url' => 'Enable Banking atgrieza nedrošu piekrišanas URL.',
        'no_authorization_code' => 'Enable Banking atzvana atbildē nebija autorizācijas koda.',
        'no_session_id' => 'Enable Banking neatgrieza sesijas id.',
        'bank_not_linked' => 'Šī banka šajā ierīcē nav pievienota. Pievieno to no jauna, lai sinhronizācija atsāktos.',
        'oauth_state_mismatch' => 'Šī savienojuma saite ir beigusies vai jau izmantota. Sāciet bankas savienošanu no jauna.',
    ],
];
