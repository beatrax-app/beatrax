<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Ditt PayPal-konto',
    'h1' => 'Koppla ditt PayPal-konto',

    'lede_html' => 'Släpp din PayPal-aktivitetsexport — en rad per transaktion, inte saldosammanställningen. PayPal namnger sina rapporter på ditt kontos språk, och än så länge läser vi det nederländska paret: <em lang="nl">Rapport Transactiegegevens</em>, inte <span lang="nl">Saldorapport</span>. Kommer din på ett annat språk, byt PayPal till nederländska innan du laddar ner.',

    'format_group_aria' => 'PayPal exporterar endast som CSV',
    'got_it_as' => 'Du fick det som:',
    'badge_only_format' => 'enda formatet',

    'mini' => [
        'login_label' => 'Logga in',
        'custom_label' => 'Anpassade kontoutdrag',
        'range_label' => 'Välj en period',
        'range_sub' => 'Senaste 12 månaderna',
        'download_label' => 'Ladda ner som CSV',
    ],

    'drop_lead' => 'Släpp din aktivitetsexport här',
    'browse_file' => 'eller bläddra efter en fil',

    'file_ready' => '· ✓ klar',

    'skip' => 'Hoppa över det här steget',
    'continue' => 'Fortsätt →',

    'errors' => [
        'required' => 'Släpp först din PayPal-aktivitetsexport i rutan.',
        'max' => 'Filen är för stor. En PayPal-aktivitetsexport ligger normalt en bra bit under 10 MB.',
        'extensions' => 'Filen ser inte ut som en PayPal-CSV. Ladda ner aktivitetsexporten — en rad per transaktion, inte saldosammanställningen — som CSV.',
        'unreadable' => 'Kunde inte läsa filen. Hela felet finns i /dev/logs.',
    ],
];
