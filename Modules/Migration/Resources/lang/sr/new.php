<?php

declare(strict_types=1);

return [
    'page_title' => 'Uvoz iz YNAB / Actual',

    'eyebrow' => 'Migracije',
    'heading' => 'Uvoz iz YNAB / Actual',
    'intro' => 'Prenesi svoje stablo kategorija, istoriju budžeta i transakcije iz YNAB4, novog YNAB-a ili Actual Budgeta. Ništa se ne upisuje u glavnu knjigu dok ne pregledaš i ne potvrdiš.',
    'reconcile_context' => 'Provera novina u odnosu na tvoj poslednji uvoz iz :product.',

    'source_label' => 'Izvor',
    'file_label' => 'Datoteka',
    'parse_button' => 'Obradi izvoz',

    'hints' => [
        'ynab4' => 'Izvezi ceo budžet kao ZIP datoteku iz menija File → Export u YNAB4.',
        'nynab' => 'Izvezi budžet iz nYNAB-a preko File → Export Budget, pa spakuj izvezene CSV datoteke u ZIP.',
        'actual' => 'Izvezi budžet kao ZIP datoteku iz Settings → Export data u Actual Budgetu.',
    ],

    'errors' => [
        'unrecognised' => 'Ovo ne liči na YNAB4, nYNAB ili Actual izvoz koji možemo da pročitamo. Proveri datoteku i pokušaj ponovo.',
        'file_too_large' => 'Ta datoteka je prevelika za migracioni izvoz.',
        'archive_reader_unavailable' => 'Ova verzija aplikacije nema čitač ZIP-a koji bi otvorio ovaj izvoz, pa ovde ne može da se pročita. Uvezi ga u aplikaciji za računar ili ponovo zapakuj izvoz uobičajenom kompresijom.',
        'internal_detail' => 'Aplikacija nije mogla da pročita ovaj izvoz (:code). Potpuni podaci nalaze se u dnevniku aplikacije; navedi ovaj kôd ako prijavljuješ problem.',
    ],
];
