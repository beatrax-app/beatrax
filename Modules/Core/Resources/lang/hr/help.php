<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'O :subject',
        'close' => 'Zatvori',
    ],

    'page_title' => 'Gdje su moji podaci?',
    'intro' => 'Beatrax sve pohranjuje na ovom uređaju. Ne postoji Beatraxov poslužitelj ni račun u oblaku. Sam od sebe odlazi samo jedan poziv — provjera postoji li nova verzija, koju možeš isključiti. Sve ostalo čeka tebe: pristigla pošta, banka preko Enable Bankinga, dnevni upit za tečajeve, uređaji koje upariš za sinkronizaciju, relej koji sam postaviš i svaka poveznica na koju klikneš. Svaka od njih to kaže na zaslonu na kojem je uključuješ.',

    'lives_here' => 'Tvoji podaci nalaze se ovdje',
    'copy' => 'Kopiraj',
    'copied' => 'Kopirano',

    'location' => [
        'database' => 'Baza podataka:',
        'artifacts_imports' => 'Uvezeni izvodi:',
        'artifacts_mail' => 'Skenirana pošta:',
        'artifacts_drop' => 'Nadzirana mapa:',
        'backups' => 'Sigurnosne kopije:',
        'secrets' => 'Vjerodajnice povezanih usluga:',
        'logs' => 'Zapisi:',
    ],

    'copy_aria' => [
        'database' => 'Kopiraj putanju baze podataka u međuspremnik',
        'artifacts_imports' => 'Kopiraj putanju uvezenih izvoda u međuspremnik',
        'artifacts_mail' => 'Kopiraj putanju skenirane pošte u međuspremnik',
        'artifacts_drop' => 'Kopiraj putanju nadzirane mape u međuspremnik',
        'backups' => 'Kopiraj putanju sigurnosnih kopija u međuspremnik',
        'secrets' => 'Kopiraj putanju vjerodajnica povezanih usluga u međuspremnik',
        'logs' => 'Kopiraj putanju zapisa u međuspremnik',
    ],

    'artifacts_heading' => 'Tvoji izvorni dokumenti nisu u sigurnosnoj kopiji',
    'artifacts_body' => 'Sigurnosna kopija sadrži bazu podataka i ništa više. Izvodi koje si uvezao, pošta koju je skener povukao i računi koje si spustio u nadziranu mapu ostaju ondje gdje jesu, u tri gore navedene mape. Spremanje sigurnosne kopije na sigurno mjesto ne kopira ih, pa potpuna arhiva znači da moraš ponijeti i te mape — ili upotrijebiti Izvezi sve u nastavku, što ih pakira zajedno sa sigurnosnom kopijom.',

    'export_heading' => 'Izvezi sve',
    'export_body' => 'Jedna arhiva sa šifriranom kopijom tvoje baze podataka i svakim izvornim dokumentom koji si dao Beatraxu. Raspakiraj je gdje god želiš i dokumenti su unutra onakvi kakvi su oduvijek bili, u mapama iz kojih su došli.',
    'export_passphrase_label' => 'Zaporka za bazu podataka',
    'export_confirm_label' => 'Ponovi zaporku',
    'export_passphrase_hint' => 'Baza podataka u arhivi šifrira se ovom zaporkom i bez nje se nikako ne može otvoriti, zato odaberi nešto što ćeš zadržati. Izvorni dokumenti ulaze onakvi kakvi jesu, pa arhivu čuvaj na mjestu kojem vjeruješ.',
    'export_cta' => 'Izvezi sve kao ZIP',
    'export_working' => 'Arhiva se izrađuje…',

    'delete_heading' => 'Brisanje podataka',
    'delete_intro' => 'Tvoji podaci datoteke su na ovom uređaju, pa njihovo brisanje znači brisanje tih datoteka. Ovdje ne postoji gumb koji to radi umjesto tebe, i to namjerno: tvoju povijest zapravo drži datotečni sustav, a kontrola koja bi ispraznila nekoliko tablica i ostavila datoteke na mjestu bila bi gora nego ništa.',
    'delete_uninstall' => 'Deinstalacija Beatraxa ne briše tvoje podatke. To je namjerno — slučajna deinstalacija ne smije uništiti godine povijesti — pa sve navedeno ostaje na ovom uređaju dok to sam ne ukloniš.',
    'delete_list_intro' => 'Da ne ostane nijedan trag, izbriši svaku od ovih stavki:',
    'delete_journal_note' => 'Uz bazu podataka stoje dvije datoteke dnevnika, :wal i :shm. Tvoje najnovije promjene žive u njima dok se ne upišu u bazu, zato izbriši sve tri zajedno.',
    'no_telemetry' => 'Nema telemetrije od koje bi se odjavio ni udaljenog računa koji bi zatvorio.',

    /** @link ../../../../../.docs/features/budgets/moving-the-budget-month.md#the-mapping-keep-the-distance-from-the-month-the-reader-is-in */
    'period' => 'Razdoblje vremena preko kojega se mjere tvoji proračuni, tvoja nadzorna ploča i svaki iznos za „ovo razdoblje”. „:label” određuje gdje počinje — postavi ga na dan nakon plaće i razdoblje će držati novac koji je za njega isplaćen. Pomicanje tog dana preslaguje svaki iznos u omotnicama koji si već rasporedio, a gdje se dva stara razdoblja sklope u jedno novo njihovi se iznosi zbrajaju; vraćanje dana natrag ih više ne razdvaja.',

    /** @link ../../../../../.docs/features/position/architecture.md#composition-never-a-raw-select */
    'net_worth' => 'Sve za što Beatrax zna da imaš, minus sve što duguješ: stanje svakog računa koji si uvezao ili povezao, pri čemu se stanja kartica i kredita broje na tvoju štetu. Potpunije od onoga što si mu dao nije — račun koji Beatrax nikad nije vidio nije u ovom iznosu. Stanja u drugoj valuti preračunavaju se po tečaju prikazanom ispod iznosa, a ono što nije uspio procijeniti imenovano je ondje umjesto da tiho ispadne.',
];
