<?php

declare(strict_types=1);

return [
    'page_title' => 'Uvoz iz YNAB / Actual',

    'eyebrow' => 'Migracije',
    'heading' => 'Uvoz iz YNAB / Actual',
    'intro' => 'Prenesi svoje stablo kategorija, povijest proračuna i transakcije iz YNAB4, novog YNAB-a ili Actual Budgeta. Ništa se ne upisuje u glavnu knjigu dok ne pregledaš i potvrdiš.',
    'reconcile_context' => 'Provjera novosti u odnosu na tvoj posljednji uvoz iz :product.',

    'source_label' => 'Izvor',
    'file_label' => 'Datoteka',
    'parse_button' => 'Obradi izvoz',

    'hints' => [
        'ynab4' => 'Izvezi cijeli proračun kao ZIP datoteku iz izbornika File → Export u YNAB4.',
        'nynab' => 'Izvezi proračun iz nYNAB-a preko File → Export Budget, zatim zapakiraj izvezene CSV datoteke u ZIP.',
        'actual' => 'Izvezi proračun kao ZIP datoteku iz Settings → Export data u Actual Budgetu.',
    ],

    'errors' => [
        'unrecognised' => 'Ovo ne izgleda kao YNAB4, nYNAB ili Actual izvoz koji možemo pročitati. Provjeri datoteku i pokušaj ponovno.',
        'file_too_large' => 'Ta je datoteka prevelika za migracijski izvoz.',
        'archive_reader_unavailable' => 'Ova verzija aplikacije nema čitač ZIP-a koji bi otvorio ovaj izvoz, pa se ovdje ne može pročitati. Uvezi ga u aplikaciji za računalo ili ponovno zapakiraj izvoz s uobičajenom kompresijom.',
        'internal_detail' => 'Aplikacija nije mogla pročitati ovaj izvoz (:code). Potpuni podaci nalaze se u zapisniku aplikacije; navedi ovaj kôd ako prijavljuješ problem.',
    ],
];
