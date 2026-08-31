<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Je creditcard',
    'h1' => 'Pak je maandelijkse PDF-afschriften',
    'lede' => 'Zet al je maandelijkse PDF-afschriften neer — we combineren ze tot één voorbeeld.',

    'format_group_aria' => 'ICS exporteert alleen als PDF',
    'issuer_note' => 'ICS is voorlopig de enige kaartuitgever die we kunnen lezen, en alleen het Nederlandstalige afschrift. Heb je een kaart van een andere uitgever, sla deze stap dan over.',
    'got_it_as' => 'Gekregen als:',
    'badge_only_format' => 'enige formaat',

    'mini' => [
        'login_label' => 'Inloggen',
        'statements_label' => 'Afschriften openen',
        'months_label' => 'Kies maanden',
        'months_sub' => 'Eén PDF per maand',
        'download_label' => 'Downloaden',
    ],

    'drop_lead' => 'Zet je ICS-PDF’s hier neer',
    'browse_files' => 'of blader naar bestanden',
    'queue_aria' => 'PDF-afschriften in wachtrij',

    'skip' => 'Sla deze stap over',
    'continue' => 'Doorgaan →',

    'errors' => [
        'required' => 'Zet de maandelijkse PDF-afschriften neer die je bij Mijn ICS hebt gedownload.',
        'min' => 'Zet minstens één ICS PDF-afschrift neer voordat je verdergaat.',
        'each_required' => 'Zet het maandelijkse PDF-afschrift neer dat je bij Mijn ICS hebt gedownload.',
        'each_max' => 'Een van je bestanden is te groot. ICS PDF-afschriften zijn normaal onder de 1 MB per stuk.',
        'each_extensions' => 'Een van je bestanden is geen PDF. Mijn ICS exporteert alleen PDF — probeer het nieuwste maandafschrift.',
        'file_unreadable' => 'Kon :filename niet lezen. De volledige fout staat in /dev/logs.',
        'none_readable' => 'We konden geen van je ICS-PDF’s lezen. :detail',
        'full_error_in_logs' => 'De volledige fout staat in /dev/logs.',
    ],
];
