<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Seaded',
        'heading' => 'Pangaliides',
        'subtitle' => 'Too tehingud automaatselt ASN-ist või SNS-ist kolmanda osapoole PSD2 koondaja Enable Banking kaudu. Vaikimisi väljas.',
        'toggle_label' => 'Luba pangaliides',
        'toggle_connected' => 'Ühendatud pangaga :bank Enable Bankingu kaudu.',
        'toggle_off_help' => 'Vaikimisi väljas. Nõuab ühekordset kinnitust ja juhendatud seadistust.',
        // i18n-review: et · page.credentials_unreadable — "volitusi" is this
        // file's own noun for stored credentials, taken from disconnect.body,
        // but it usually reads as a granted authority rather than a secret.
        'credentials_unreadable' => 'Selles seadmes salvestatud pangaliidese volitusi ei õnnestu lugeda, seega ei saa Beatrax sinu pangaga ühendust.',
        'credentials_unreadable_next' => 'Käi juhendatud seadistus uuesti läbi, et need asendada. Juba imporditud tehinguid see ei mõjuta.',
        'reconfirm_body' => 'Sinu kinnitus aegus enne, kui jõudsime ühenduse lõpule viia. Kinnita uuesti, et pangaliidese lubamine lõpetada.',
        'reconfirm_button' => 'Kinnita uuesti, et lõpetada',
    ],

    'status_row' => [
        'heading' => 'Pangaliides',
        'manage' => 'Halda pangaliidest',
        'not_connected' => 'Ühtegi panka pole ühendatud. Ühenda pank, et tehinguid automaatselt importida.',
        'expired' => 'Nõusolek on aegunud — vaja on uuesti ühendada.',
        'revoked' => 'Sinu pank lõpetas ühenduse — ühenda uuesti.',
        'connected' => 'Ühendatud pangaga :bank Enable Bankingu kaudu. Viimati sünkroonitud :when.',
        'never' => 'mitte kunagi',
    ],

    'transparency' => [
        'aggregator_label' => 'Koondaja',
        'bank_label' => 'Pank',
        'consent_status_label' => 'Nõusoleku olek',
        'pill_expired' => 'Aegunud — ühenda uuesti',
        'pill_expiring' => 'Aegub varsti',
        'pill_connected' => 'Ühendatud',
        'pill_revoked' => 'Panga poolt lõpetatud — ühenda uuesti',
        'whats_fetched_label' => 'Mida tuuakse',
        'whats_fetched' => 'Kinnitatud tehingud ja jäägid, viimased 90 päeva',
        'last_successful_sync_label' => 'Viimane õnnestunud sünkroonimine',
        'never' => 'Mitte kunagi',
        'last_attempt_label' => 'Viimane katse',
        'last_attempt_failed' => ':when — ebaõnnestus (:reason)',
        'reason_consent_expired' => 'nõusolek aegus',
        'reason_error' => 'viga',
        'reason_truncated' => 'peatus varakult',
        'reason_nothing_imported' => 'midagi ei õnnestunud kirjendada',
        'reason_consent_revoked' => 'panga poolt lõpetatud',
        'disconnect_button' => 'Katkesta ühendus',
    ],

    'consent_banner' => [
        'heading' => 'Nõusolek on aegunud — ühenda uuesti',
        'heading_revoked' => 'Sinu pank lõpetas ühenduse',
        'body' => 'Sinu viimane õnnestunud sünkroonimine oli :when. Ühenda uuesti, et automaatne sünkroonimine jätkuks.',
        'body_revoked' => 'Sinu pank või Enable Banking võttis juurdepääsu tagasi, seega sünkroonimine peatus. Viimane õnnestunud sünkroonimine oli :when. Ühenda uuesti, et see jätkuks.',
        'never' => 'mitte kunagi',
        'reconnect' => 'Ühenda uuesti',
    ],

    'sync' => [
        'review_import' => 'Vaata import üle',
        'reconnect_first' => 'Ühenda kõigepealt uuesti',
        'auto_caption' => 'Sünkroonib automaatselt kord päevas.',
        'sync_now' => 'Sünkrooni kohe',

        'consent_expired' => 'Nõusolek on aegunud — ühenda uuesti.',
        'unavailable' => 'Enable Banking pole ajutiselt saadaval. Proovi peagi uuesti.',
        'new_found' => 'Leiti :count uus tehing.|Leiti :count uut tehingut.',
        'none' => 'Uusi tehinguid pole.',
        'none_importable' => 'Sinu pank saatis tehinguid, aga ühtegi neist ei õnnestunud kirjendada. Ava impordi ülevaatus, et näha miks.',
        'in_progress' => 'Sünkroonimine juba käib. Proovi hetke pärast uuesti.',
        'truncated' => 'Sinu pangal oli rohkem tehinguid, kui üks sünkroonimine tuua jõuab, seega see käivitus peatus varakult. Midagi ei märgitud sünkroonituks — järgmine sünkroonimine algab samast kohast.',
    ],

    'disconnect' => [
        'heading' => 'Kas katkestada pangaliidese ühendus?',
        'body' => 'See eemaldab sinu salvestatud Enable Bankingu volitused ja nõusoleku. Automaatne sünkroonimine peatub kohe. Beatraxi juba imporditud tehinguid see ei mõjuta.',
        'confirm' => 'Katkesta ühendus',
        'cancel' => 'Jäta ühendatuks',
    ],

    'ics' => [
        'section_label' => 'Failiimport — volitusi ei salvestata',
        'heading' => 'ICS krediitkaardi väljavõte',
        'step_login' => 'Logi sisse',
        'step_download' => 'Laadi väljavõte alla',
        'pdf_statement' => 'PDF-väljavõte',
        'step_drop' => 'Lohista see allapoole',
        'drop_zone_label' => 'Lohista oma väljavõtte fail siia',
        'drop_zone_hint' => 'või otsi fail üles',
        'browse_aria' => 'Otsi üles ICS väljavõtte fail',
        'import_button' => 'Impordi väljavõte',
        'validation' => [
            'required' => 'Lohista ICS väljavõte, mille Mijn ICS-ist alla laadisid.',
            'max' => 'See fail on liiga suur. ICS PDF-väljavõtted on tavaliselt alla 1 MB.',
            'extensions' => 'See ei ole PDF. Mijn ICS ekspordib ainult PDF-väljavõtteid.',
        ],
        'could_not_read' => 'Faili :filename ei õnnestunud lugeda. Täielik viga on kaustas /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Enne kolmanda osapoolega ühendamist',
        'body' => 'Pangaliidese lubamine saadab sinu pangaliidese nõusoleku ning seejärel tehingu- ja jäägiandmed sellest seadmest otse Enable Bankingule ja sinu pangale. Beatrax ei pea serverit, mis neid andmeid näeks — kuid Enable Banking ja sinu pank näevad. See erineb kõigist teistest Beatraxi impordiviisidest, mis ei saada andmeid kunagi kuhugi.',
        'acknowledge' => 'Saan aru, et minu tehinguandmeid jagatakse Enable Bankingu ja minu pangaga.',
        'confirm' => 'Luba pangaliides',
        'cancel' => 'Tühista',
    ],

    'wizard' => [
        'heading' => 'Ühenda oma pank',
        'intro' => 'Beatrax kasutab sinu enda Enable Bankingu rakendust, nii et sinu volitused ei satu kunagi jagatud serverisse. See on iga panga kohta ühekordne seadistus.',

        'step1_title' => 'Loo oma kohalik võtmepaar',
        'step1_body' => 'Beatrax loob selles seadmes RSA võtmepaari. Privaatvõti ei lahku sellest kunagi.',
        'generate_keypair' => 'Loo võtmepaar',
        'public_key_label' => 'Avalik võti',
        'copy_public_key' => 'Kopeeri avalik võti',
        'copied' => 'Kopeeritud',
        'redirect_uri_label' => 'Ümbersuunamise URI',
        'copy_redirect_uri' => 'Kopeeri ümbersuunamise URI',

        'step2_title' => 'Registreeri rakendus Enable Bankingus',
        'step2_body' => 'Ava Enable Bankingu arendajaportaal, loo rakendus ja kleebi sinna 1. sammu avalik võti ja ümbersuunamise URI.',
        'open_portal' => 'Ava Enable Bankingu portaal ↗',

        'step3_title' => 'Kleebi oma rakenduse ID',
        'application_id_label' => 'Rakenduse ID',
        'step3_help' => 'Salvestatakse andmebaasist väljapoole kohalikku faili, mida saad lugeda ainult sina. See ütleb Enable Bankingule, milline rakendus pöördub, seega läheb see kaasa iga päringuga — sinu privaatvõti mitte kunagi.',

        'step4_title' => 'Vali oma pank',
        'via_enable_banking' => 'Enable Bankingu kaudu',
        'other_institution' => 'Muu asutus',
        'institution_id_placeholder' => 'Asutuse id',

        'step5_title' => 'Anna nõusolek oma brauseris',
        'step5_body' => 'Klõpsa allpool, et avada oma panga sisselogimise ja nõusoleku ekraan. Tee sisselogimine ja võimalik kaheastmeline kinnitus ning sind tuuakse automaatselt siia tagasi, et pangaliidese lubamine lõpetada.',
        // i18n-review: et · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Puuduta allpool, et avada oma panga sisselogimise ja nõusoleku ekraan. Tee sisselogimine ja võimalik kaheastmeline kinnitus ning sind tuuakse automaatselt siia tagasi, et pangaliidese lubamine lõpetada.',

        'cancel' => 'Tühista',
        'continue' => 'Jätka →',
        'continue_to_bank' => 'Jätka panka :bank →',
        'your_bank' => 'sinu pank',

        'errors' => [
            'save_keypair_failed' => 'Sinu võtmepaari ei õnnestunud kettale salvestada — kontrolli saladuste kausta õigusi ja proovi uuesti.',
            'generate_failed' => 'Selles seadmes ei õnnestunud võtmepaari luua — kontrolli oma OpenSSL-i seadistust.',
            'export_failed' => 'Loodud võtmepaari ei õnnestunud eksportida.',
            'read_public_failed' => 'Loodud avalikku võtit ei õnnestunud lugeda.',
            'generate_first' => 'Loo enne jätkamist võtmepaar.',
            'paste_application_id' => 'Kleebi enne jätkamist Enable Bankingu portaali rakenduse ID.',
            'save_application_id_failed' => 'Sinu rakenduse ID-d ei õnnestunud kettale salvestada — kontrolli saladuste kausta õigusi ja proovi uuesti.',
            'choose_bank' => 'Vali enne jätkamist pank.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Lõpeta kõigepealt pangaliidese seadistusviisard.',
        'no_bank_chosen' => 'Vali enne ühendamist pank.',
        'no_consent_url' => 'Enable Banking ei tagastanud nõusoleku URL-i.',
        'unparseable_consent_url' => 'Enable Banking tagastas loetamatu nõusoleku URL-i.',
        'non_public_consent_host' => 'Enable Banking tagastas mitteavaliku nõusoleku hosti.',
        'unsafe_consent_url' => 'Enable Banking tagastas ebaturvalise nõusoleku URL-i.',
        'no_authorization_code' => 'Enable Bankingu tagasikutse ei tagastanud autoriseerimiskoodi.',
        'no_session_id' => 'Enable Banking ei tagastanud sessiooni id-d.',
        'oauth_state_mismatch' => 'See ühenduslink on aegunud või juba kasutatud. Alusta panga ühendamist uuesti.',
    ],
];
