<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'O :subject',
        'close' => 'Zatvori',
    ],

    'page_title' => 'Gde su moji podaci?',
    'intro' => 'Beatrax sve čuva na ovom uređaju. Ne postoji Beatrax server niti nalog u oblaku. Napolje odlazi samo ono što sam povežeš — prijemno sanduče, banka preko Enable Bankinga, uređaji koje upariš za sinhronizaciju — i uz to dnevni upit za kurseve. Svaka veza to kaže na ekranu na kom je uključuješ.',

    'lives_here' => 'Tvoji podaci se nalaze ovde',
    'copy' => 'Kopiraj',
    'copied' => 'Kopirano',

    'location' => [
        'database' => 'Baza podataka:',
        'artefacts_imports' => 'Uvezeni izvodi:',
        'artefacts_mail' => 'Skenirana pošta:',
        'artefacts_drop' => 'Nadgledana fascikla:',
        'backups' => 'Rezervne kopije:',
        'secrets' => 'Akreditivi povezanih usluga:',
        'logs' => 'Zapisi:',
    ],

    'copy_aria' => [
        'database' => 'Kopiraj putanju baze podataka u ostavu',
        'artefacts_imports' => 'Kopiraj putanju uvezenih izvoda u ostavu',
        'artefacts_mail' => 'Kopiraj putanju skenirane pošte u ostavu',
        'artefacts_drop' => 'Kopiraj putanju nadgledane fascikle u ostavu',
        'backups' => 'Kopiraj putanju rezervnih kopija u ostavu',
        'secrets' => 'Kopiraj putanju akreditiva povezanih usluga u ostavu',
        'logs' => 'Kopiraj putanju zapisa u ostavu',
    ],

    'artefacts_heading' => 'Tvoji izvorni dokumenti nisu u rezervnoj kopiji',
    'artefacts_body' => 'Rezervna kopija sadrži bazu podataka i ništa više. Izvodi koje si uvezao, pošta koju je skener povukao i računi koje si spustio u nadgledanu fasciklu ostaju tamo gde jesu, u tri gore navedene fascikle. Čuvanje rezervne kopije na sigurnom mestu ih ne kopira, pa potpuna arhiva znači da poneseš i te fascikle — ili da upotrebiš Izvezi sve ispod, što ih pakuje zajedno sa rezervnom kopijom.',

    'export_heading' => 'Izvezi sve',
    'export_body' => 'Jedna arhiva sa šifrovanom kopijom tvoje baze podataka i svakim izvornim dokumentom koji si dao Beatraxu. Raspakuj je gde god želiš i dokumenti su unutra onakvi kakvi su oduvek bili, u fasciklama iz kojih su došli.',
    'export_passphrase_label' => 'Lozinka za bazu podataka',
    'export_confirm_label' => 'Ponovi lozinku',
    'export_passphrase_hint' => 'Baza podataka u arhivi šifruje se ovom lozinkom i bez nje se nikako ne može otvoriti, zato izaberi nešto što ćeš sačuvati. Izvorni dokumenti ulaze onakvi kakvi jesu, pa arhivu drži na mestu kojem veruješ.',
    'export_cta' => 'Izvezi sve kao ZIP',
    'export_working' => 'Arhiva se pravi…',

    'delete_heading' => 'Brisanje podataka',
    'delete_intro' => 'Tvoji podaci su datoteke na ovom uređaju, pa njihovo brisanje znači brisanje tih datoteka. Ovde ne postoji dugme koje to radi umesto tebe, i to namerno: tvoju istoriju zapravo drži sistem datoteka, a kontrola koja bi ispraznila nekoliko tabela i ostavila datoteke na mestu bila bi gora nego ništa.',
    'delete_uninstall' => 'Deinstaliranje Beatraxa ne briše tvoje podatke. To je namerno — slučajno deinstaliranje ne sme da uništi godine istorije — pa sve navedeno ostaje na ovom uređaju dok to sam ne ukloniš.',
    'delete_list_intro' => 'Da ne ostane nijedan trag, obriši svaku od ovih stavki:',
    'delete_journal_note' => 'Uz bazu podataka stoje dve datoteke dnevnika, :wal i :shm. Tvoje najnovije izmene žive u njima dok se ne upišu u bazu, zato obriši sve tri zajedno.',
    'no_telemetry' => 'Nema telemetrije od koje bi se odjavio ni udaljenog naloga koji bi zatvorio.',
];
