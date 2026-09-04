<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Nastavenia',
        'heading' => 'Open banking',
        'subtitle' => 'Automaticky sťahuj transakcie z ASN alebo SNS cez Enable Banking, externý agregátor PSD2. Predvolene vypnuté.',
        'toggle_label' => 'Zapnúť open banking',
        'toggle_connected' => 'Pripojené cez Enable Banking. Banka: :bank.',
        'toggle_off_help' => 'Predvolene vypnuté. Vyžaduje jednorazové potvrdenie a sprievodcu nastavením.',
        'credentials_unreadable' => 'Prihlasovacie údaje pre open banking uložené na tomto zariadení sa nepodarilo prečítať, takže sa Beatrax nedokáže spojiť s tvojou bankou.',
        'credentials_unreadable_next' => 'Prejdi znova sprievodcu nastavením a nahraď ich. Transakcie, ktoré sú už importované, ostanú nedotknuté.',
        'reconfirm_body' => 'Tvoje potvrdenie expirovalo skôr, než sa podarilo dokončiť pripojenie. Potvrď to znova a dokonči zapnutie open bankingu.',
        'reconfirm_button' => 'Potvrdiť znova a dokončiť',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Spravovať open banking',
        'not_connected' => 'Nie je pripojená žiadna banka. Pripoj jednu a transakcie sa budú importovať automaticky.',
        'expired' => 'Súhlas expiroval — treba sa znova pripojiť.',
        'revoked' => 'Tvoja banka ukončila pripojenie — pripoj sa znova.',
        'connected' => 'Pripojené cez Enable Banking. Banka: :bank. Posledná synchronizácia: :when.',
        'never' => 'nikdy',
    ],

    'transparency' => [
        'aggregator_label' => 'Agregátor',
        'bank_label' => 'Banka',
        'consent_status_label' => 'Stav súhlasu',
        'pill_expired' => 'Expirovaný — pripoj sa znova',
        'pill_expiring' => 'Čoskoro vyprší',
        'pill_connected' => 'Pripojené',
        'pill_revoked' => 'Ukončené bankou — pripoj sa znova',
        'whats_fetched_label' => 'Čo sa sťahuje',
        'whats_fetched' => 'Zaúčtované transakcie + zostatky, posledných 90 dní',
        'last_successful_sync_label' => 'Posledná úspešná synchronizácia',
        'never' => 'Nikdy',
        'last_attempt_label' => 'Posledný pokus',
        'last_attempt_failed' => ':when — zlyhalo (:reason)',
        'reason_consent_expired' => 'súhlas expiroval',
        'reason_error' => 'chyba',
        'reason_truncated' => 'zastavené predčasne',
        'reason_nothing_imported' => 'nič sa nepodarilo zaznamenať',
        'reason_consent_revoked' => 'ukončené bankou',
        'disconnect_button' => 'Odpojiť',
    ],

    'consent_banner' => [
        'heading' => 'Súhlas expiroval — pripoj sa znova',
        'heading_revoked' => 'Tvoja banka ukončila pripojenie',
        'body' => 'Posledná úspešná synchronizácia: :when. Pripoj sa znova a automatická synchronizácia bude pokračovať.',
        'body_revoked' => 'Tvoja banka alebo Enable Banking odobrala prístup, takže synchronizácia sa zastavila. Posledná úspešná synchronizácia: :when. Pripoj sa znova a bude pokračovať.',
        'never' => 'nikdy',
        'reconnect' => 'Znova pripojiť',
    ],

    'sync' => [
        'review_import' => 'Skontrolovať import',
        'reconnect_first' => 'Najprv sa znova pripoj',
        'auto_caption' => 'Synchronizuje sa automaticky raz denne.',
        'sync_now' => 'Synchronizovať teraz',

        'consent_expired' => 'Súhlas expiroval — pripoj sa znova.',
        'unavailable' => 'Enable Banking je dočasne nedostupné. Skús to o chvíľu znova.',
        'new_found' => 'Nájdená :count nová transakcia.|Nájdené :count nové transakcie.|Nájdených :count nových transakcií.',
        'none' => 'Žiadne nové transakcie.',
        'none_importable' => 'Tvoja banka poslala transakcie, ale ani jednu sa nepodarilo zaznamenať. Otvor kontrolu importu a zisti prečo.',
        'in_progress' => 'Synchronizácia už prebieha. Skúste to o chvíľu znova.',
        'truncated' => 'Tvoja banka mala viac transakcií, než zvládne jedna synchronizácia stiahnuť, takže tento beh skončil predčasne. Nič sa nezaznamenalo ako synchronizované — ďalšia synchronizácia začne na rovnakom mieste.',
    ],

    'disconnect' => [
        'heading' => 'Odpojiť open banking?',
        'body' => 'Odstráni to uložené prihlasovacie údaje a súhlas Enable Banking. Automatická synchronizácia sa okamžite zastaví. Transakcie, ktoré sú už importované v Beatraxe, ostanú nedotknuté.',
        'confirm' => 'Odpojiť',
        'cancel' => 'Nechať pripojené',
    ],

    'ics' => [
        'section_label' => 'Import súboru — neukladajú sa žiadne prihlasovacie údaje',
        'heading' => 'Výpis z kreditnej karty ICS',
        'step_login' => 'Prihlás sa',
        'step_download' => 'Stiahni výpis',
        'pdf_statement' => 'Výpis v PDF',
        'step_drop' => 'Presuň ho sem nižšie',
        'drop_zone_label' => 'Sem presuň súbor s výpisom',
        'drop_zone_hint' => 'alebo súbor vyhľadaj',
        'browse_aria' => 'Vyhľadať súbor s výpisom ICS',
        'import_button' => 'Importovať výpis',
        'validation' => [
            'required' => 'Presuň sem výpis ICS stiahnutý z Mijn ICS.',
            'max' => 'Tento súbor je príliš veľký. Výpisy ICS v PDF mávajú menej než 1 MB.',
            'extensions' => 'Toto nie je PDF. Mijn ICS exportuje výpisy iba v PDF.',
        ],
        'could_not_read' => 'Súbor :filename sa nepodarilo prečítať. Úplná chyba je v /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Skôr než pripojíš tretiu stranu',
        'body' => 'Zapnutie open bankingu odošle tvoj súhlas s prihlásením do banky a potom aj údaje o transakciách a zostatkoch priamo z tohto zariadenia do Enable Banking a do tvojej banky. Beatrax neprevádzkuje server, ktorý by tieto údaje videl — Enable Banking a tvoja banka ich však vidia. Odlišuje sa to od všetkých ostatných spôsobov importu v Beatraxe, ktoré nikdy nikam neposielajú údaje.',
        'acknowledge' => 'Rozumiem, že moje údaje o transakciách sa budú zdieľať s Enable Banking a s mojou bankou.',
        'confirm' => 'Zapnúť open banking',
        'cancel' => 'Zrušiť',
    ],

    'wizard' => [
        'heading' => 'Pripoj svoju banku',
        'intro' => 'Beatrax používa tvoju vlastnú aplikáciu Enable Banking, takže tvoje prihlasovacie údaje sa nikdy nedostanú na zdieľaný server. Ide o jednorazové nastavenie pre každú banku.',

        'step1_title' => 'Vygeneruj lokálny pár kľúčov',
        'step1_body' => 'Beatrax vygeneruje na tomto zariadení pár kľúčov RSA. Súkromný kľúč ho nikdy neopustí.',
        'generate_keypair' => 'Vygenerovať pár kľúčov',
        'public_key_label' => 'Verejný kľúč',
        'copy_public_key' => 'Kopírovať verejný kľúč',
        'copied' => 'Skopírované',
        'redirect_uri_label' => 'Redirect URI',
        'copy_redirect_uri' => 'Kopírovať redirect URI',

        'step2_title' => 'Zaregistruj aplikáciu v Enable Banking',
        'step2_body' => 'Otvor vývojársky portál Enable Banking, vytvor aplikáciu a vlož do nej verejný kľúč a redirect URI z kroku 1.',
        'open_portal' => 'Otvoriť portál Enable Banking ↗',

        'step3_title' => 'Vlož identifikátor aplikácie',
        'application_id_label' => 'Identifikátor aplikácie',
        'step3_help' => 'Ukladá sa do lokálneho súboru mimo databázy s obmedzenými právami a nikdy neopustí toto zariadenie.',

        'step4_title' => 'Vyber si banku',
        'via_enable_banking' => 'cez Enable Banking',
        'other_institution' => 'Iná inštitúcia',
        'institution_id_placeholder' => 'Identifikátor inštitúcie',

        'step5_title' => 'Dokonči udelenie súhlasu v prehliadači',
        'step5_body' => 'Klikni nižšie a otvorí sa prihlasovacia obrazovka a obrazovka súhlasu tvojej banky. Prihlás sa, dokonči prípadné dvojfaktorové overenie a potom sa sem automaticky vrátiš, aby sa zapnutie Open Bankingu dokončilo.',
        // i18n-review: sk · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Ťukni nižšie a otvorí sa prihlasovacia obrazovka a obrazovka súhlasu tvojej banky. Prihlás sa, dokonči prípadné dvojfaktorové overenie a potom sa sem automaticky vrátiš, aby sa zapnutie Open Bankingu dokončilo.',

        'cancel' => 'Zrušiť',
        'continue' => 'Pokračovať →',
        'continue_to_bank' => 'Pokračovať — :bank →',
        'your_bank' => 'tvoja banka',

        'errors' => [
            'save_keypair_failed' => 'Pár kľúčov sa nepodarilo uložiť na disk — skontroluj práva priečinka so secrets a skús to znova.',
            'generate_failed' => 'Na tomto zariadení sa nepodarilo vygenerovať pár kľúčov — skontroluj konfiguráciu OpenSSL.',
            'export_failed' => 'Vygenerovaný pár kľúčov sa nepodarilo exportovať.',
            'read_public_failed' => 'Vygenerovaný verejný kľúč sa nepodarilo prečítať.',
            'generate_first' => 'Skôr než budeš pokračovať, vygeneruj pár kľúčov.',
            'paste_application_id' => 'Skôr než budeš pokračovať, vlož identifikátor aplikácie z portálu Enable Banking.',
            'save_application_id_failed' => 'Identifikátor aplikácie sa nepodarilo uložiť na disk — skontroluj práva priečinka so secrets a skús to znova.',
            'choose_bank' => 'Skôr než budeš pokračovať, vyber banku.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Najprv dokonči sprievodcu nastavením Open Banking.',
        'no_bank_chosen' => 'Pred pripojením vyber banku.',
        'no_consent_url' => 'Enable Banking nevrátilo URL súhlasu.',
        'unparseable_consent_url' => 'Enable Banking vrátilo URL súhlasu, ktoré sa nedá spracovať.',
        'non_public_consent_host' => 'Enable Banking vrátilo neverejného hostiteľa súhlasu.',
        'unsafe_consent_url' => 'Enable Banking vrátilo nebezpečné URL súhlasu.',
        'no_authorization_code' => 'Spätné volanie Enable Banking nevrátilo autorizačný kód.',
        'no_session_id' => 'Enable Banking nevrátilo identifikátor relácie.',
        'oauth_state_mismatch' => 'Tento odkaz na pripojenie vypršal alebo už bol použitý. Začnite pripojenie banky znova.',
    ],
];
