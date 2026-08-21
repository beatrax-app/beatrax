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
        'reconfirm_body' => 'Sinu kinnitus aegus enne, kui jõudsime ühenduse lõpule viia. Kinnita uuesti, et pangaliidese lubamine lõpetada.',
        'reconfirm_button' => 'Kinnita uuesti, et lõpetada',
    ],

    'status_row' => [
        'heading' => 'Pangaliides',
        'manage' => 'Halda pangaliidest',
        'not_connected' => 'Ühtegi panka pole ühendatud. Ühenda pank, et tehinguid automaatselt importida.',
        'expired' => 'Nõusolek on aegunud — vaja on uuesti ühendada.',
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
        'whats_fetched_label' => 'Mida tuuakse',
        'whats_fetched' => 'Kinnitatud tehingud ja jäägid, viimased 90 päeva',
        'last_successful_sync_label' => 'Viimane õnnestunud sünkroonimine',
        'never' => 'Mitte kunagi',
        'last_attempt_label' => 'Viimane katse',
        'last_attempt_failed' => ':when — ebaõnnestus (:reason)',
        'reason_consent_expired' => 'nõusolek aegus',
        'reason_error' => 'viga',
        'disconnect_button' => 'Katkesta ühendus',
    ],

    'consent_banner' => [
        'heading' => 'Nõusolek on aegunud — ühenda uuesti',
        'body' => 'Sinu viimane õnnestunud sünkroonimine oli :when. Ühenda uuesti, et automaatne sünkroonimine jätkuks.',
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
        'step3_help' => 'See salvestatakse andmebaasist väljapoole kohalikku faili piiratud õigustega ega lahku kunagi sellest seadmest.',

        'step4_title' => 'Vali oma pank',
        'via_enable_banking' => 'Enable Bankingu kaudu',
        'other_institution' => 'Muu asutus',
        'institution_id_placeholder' => 'Asutuse id',

        'step5_title' => 'Anna nõusolek oma brauseris',
        'step5_body' => 'Klõpsa allpool, et avada oma panga sisselogimise ja nõusoleku ekraan. Tee sisselogimine ja võimalik kaheastmeline kinnitus ning sind tuuakse automaatselt siia tagasi, et pangaliidese lubamine lõpetada.',

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

    'alert' => [
        'reconsent' => 'Ühenda oma pank uuesti',
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
    ],
];
