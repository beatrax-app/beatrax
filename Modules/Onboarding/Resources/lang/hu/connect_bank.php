<?php

declare(strict_types=1);

return [
    'eyebrow' => 'A bankod',
    'h1' => 'Szerezz be egy számlakivonatot, majd húzd ide',
    'lede' => 'Válaszd ki a bankodtól kapott formátumot, majd húzd ide a fájlt. A CAMT.053-at és az MT940-et automatikusan felismerjük.',

    'format_group_aria' => 'Bankszámlakivonat formátuma',
    'got_it_as' => 'Így kaptad meg:',
    'badge_recommended' => 'ajánlott',

    'mini' => [
        'login_label' => 'Bejelentkezés',
        'login_sub' => 'A bankod weboldalán',
        'statements_label' => 'Kivonatok megnyitása',
        'statements_sub' => 'A bankod menüjében',
        'range_label' => 'Válassz időszakot',
        'range_sub' => 'Utolsó 90 nap',
        'download_label' => 'Letöltés',
    ],

    'csv_picker_aria' => 'Melyik bank exportálta a CSV-t?',
    'csv_picker_from' => 'Innen:',

    'drop_lead_camt053' => 'Húzd ide a CAMT.053 fájlt',
    'drop_lead_mt940' => 'Húzd ide az MT940 fájlt',
    'drop_lead_csv_layout' => 'Húzd ide az :layout CSV-t',
    'drop_lead_pick_bank' => 'Válaszd ki, melyik bank exportálta a CSV-t — enélkül nem tudjuk helyesen beolvasni.',
    'drop_lead_default' => 'Húzd ide a számlakivonat fájlt',
    'browse_file' => 'vagy tallózz egy fájlt',

    'format_help_camt053' => 'A CAMT.053 XML formátumú kivonat — keresd a netbankban a kivonatok vagy a letöltések között.',
    'format_help_mt940' => 'Az MT940 egyszerű szöveges kivonat, .sta vagy .940 kiterjesztéssel, az XML és a CSV mellett.',
    'format_help_csv' => 'A CSV a táblázatos export. Minden bank máshogy rendezi az oszlopokat, ezért válaszd ki az illeszkedő elrendezést. Ha a tiéd nincs a listán, kérj a bankodtól inkább CAMT.053 vagy MT940 fájlt.',

    'account_name_default' => 'Bankszámla',
    'account_name_layout' => ':layout számla',

    'file_ready' => '· ✓ kész',

    'skip' => 'Lépés kihagyása',
    'continue' => 'Folytatás →',

    'errors' => [
        'file_required' => 'Előbb húzd a számlakivonat fájlt a mezőbe.',
        'file_max' => 'Ez a fájl túl nagy. 10 MB alatti kivonatot húzz ide.',
        'file_extensions' => 'Ez a fájl nem tűnik bankszámlakivonatnak. Húzz ide CAMT.053 XML, CSV vagy MT940 fájlt.',
        'pick_bank' => 'A folytatás előtt válaszd ki, melyik bank exportálta a CSV-t.',
        'unreadable' => 'Nem sikerült beolvasni ezt a fájlt. A teljes hiba a /dev/logs alatt található.',
    ],
];
