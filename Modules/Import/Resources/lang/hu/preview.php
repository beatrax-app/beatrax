<?php

declare(strict_types=1);

return [
    'page_title' => 'Import előnézete',
    'heading' => 'Import előnézete',
    'discard' => 'Import elvetése',
    'confirm' => 'Import megerősítése',
    'subtitle' => 'Nézd át a beolvasott sorokat. A megerősítésig semmi nem kerül a főkönyvedbe.',

    'expired_html' => 'Az előnézet lejárt. <a href="/imports/new" class="underline">Töltsd fel újra a fájlt</a>, és próbáld meg ismét.',

    'save_name' => 'Név mentése',
    'account_name_label' => 'Számla neve',
    'account_placeholder' => 'pl. Fő megtakarítási számla',
    'rename_aria' => 'Ennek a partnernek az átnevezése',

    'unknown_iban_prefix' => 'Ismeretlen IBAN-t találtunk:',
    'unknown_iban_suffix' => 'Nevezd el ezt a számlát.',

    'ics' => [
        'heading' => 'Nevezd el az ICS-kártyaszámládat.',
        'help' => 'Most először importálsz ICS-adatokat. Adj nevet ennek a kártyának, hogy egységesen jelenjen meg az alkalmazásban.',
        'placeholder' => 'pl. ICS-kártya',
    ],

    'paypal' => [
        'heading' => 'Nevezd el a PayPal-számládat.',
        'help' => 'Most először importálsz PayPal-adatokat. Adj nevet ennek a tárcának, hogy egységesen jelenjen meg az alkalmazásban.',
        'placeholder' => 'pl. PayPal',
    ],

    'col_date' => 'Dátum',
    'col_funding_source' => 'Finanszírozási forrás',
    'col_counterparty' => 'Partner',
    'col_amount' => 'Összeg',
    'col_status' => 'Állapot',

    'status' => [
        'new' => 'Új',
        'new_title' => 'Bekerül a főkönyvedbe.',
        'duplicate' => 'Duplikátum',
        'duplicate_title' => 'Már importálva — kimarad.',
        'enriched' => 'Kiegészítve',
        'enriched_title' => 'A meglévő sor erősebb forráshivatkozással frissül.',
        'error' => 'Hiba',
    ],

    'chain' => [
        'heading' => 'Láncok feloldása…',
        'pending' => 'Várólistán. A láncfeloldó hamarosan elindul.',
        'running' => 'Finanszírozási láncok összekapcsolása és a kivonatelszámolások felbontása.',
        'failed_prefix' => 'A láncfeloldás sikertelen:',
        'unknown_error' => 'ismeretlen hiba történt',
        'open_horizon' => 'Horizon megnyitása',
        'failed_suffix' => 'az újrapróbáláshoz vagy a megtekintéshez.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Ez az IBAN nem része az aktuális előnézetnek.',
    ],
];
