<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'O :subject',
        'close' => 'Zapri',
    ],

    'page_title' => 'Kje so moji podatki?',
    'intro' => 'Beatrax vse shranjuje na tej napravi. Nič se ne pošilja na strežnik, nič se ne sinhronizira v oblak, nič ne zapusti te naprave, dokler tega sam ne izvoziš.',

    'lives_here' => 'Tvoji podatki živijo tukaj',
    'copy' => 'Kopiraj',
    'copied' => 'Kopirano',

    'location' => [
        'database' => 'Zbirka podatkov:',
        'artefacts_imports' => 'Uvoženi izpiski:',
        'artefacts_mail' => 'Prebrana pošta:',
        'artefacts_drop' => 'Nadzorovana mapa:',
        'backups' => 'Varnostne kopije:',
        'secrets' => 'Poverilnice povezav:',
        'logs' => 'Dnevniki:',
    ],

    'copy_aria' => [
        'database' => 'Kopiraj pot do zbirke podatkov v odložišče',
        'artefacts_imports' => 'Kopiraj pot do uvoženih izpiskov v odložišče',
        'artefacts_mail' => 'Kopiraj pot do prebrane pošte v odložišče',
        'artefacts_drop' => 'Kopiraj pot do nadzorovane mape v odložišče',
        'backups' => 'Kopiraj pot do varnostnih kopij v odložišče',
        'secrets' => 'Kopiraj pot do poverilnic povezav v odložišče',
        'logs' => 'Kopiraj pot do dnevnikov v odložišče',
    ],

    'artefacts_heading' => 'Tvoji izvorni dokumenti niso v varnostni kopiji',
    'artefacts_body' => 'Varnostna kopija vsebuje zbirko podatkov in nič drugega. Izpiski, ki si jih uvozil, pošta, ki jo je potegnil bralnik, in računi, ki si jih odložil v nadzorovano mapo, ostanejo tam, kjer so, v treh zgoraj naštetih mapah. Če varnostno kopijo shraniš na varno, se ti ne prekopirajo, zato popoln arhiv pomeni, da vzameš s seboj tudi te mape — ali pa uporabiš spodnji Izvozi vse, ki jih zapakira skupaj z varnostno kopijo.',

    'export_heading' => 'Izvozi vse',
    'export_body' => 'En sam arhiv s šifrirano kopijo tvoje zbirke podatkov in vsakim izvornim dokumentom, ki si ga dal Beatraxu. Razpakiraj ga kamor koli in dokumenti bodo notri takšni, kot so bili od nekdaj, v mapah, iz katerih so prišli.',
    'export_passphrase_label' => 'Geslo za zbirko podatkov',
    'export_confirm_label' => 'Ponovi geslo',
    'export_passphrase_hint' => 'Zbirka podatkov v arhivu je šifrirana s tem geslom in brez njega je ni mogoče odpreti, zato izberi nekaj, kar boš še imel. Izvorni dokumenti gredo noter takšni, kot so, zato arhiv hrani na mestu, ki mu zaupaš.',
    'export_cta' => 'Izvozi vse kot ZIP',
    'export_working' => 'Arhiv se ustvarja…',

    'delete_heading' => 'Brisanje podatkov',
    'delete_intro' => 'Tvoji podatki so datoteke na tej napravi, zato jih izbrisati pomeni izbrisati te datoteke. Tukaj ni gumba, ki bi to naredil namesto tebe, in to namenoma: tvojo zgodovino v resnici hrani datotečni sistem, gumb, ki bi izpraznil nekaj tabel in datoteke pustil pri miru, pa bi bil slabši od ničesar.',
    'delete_uninstall' => 'Odstranitev Beatraxa ne izbriše tvojih podatkov. To je namerno — nenamerna odstranitev ne sme uničiti let zgodovine — zato vse spodaj ostane na tej napravi, dokler tega sam ne odstraniš.',
    'delete_list_intro' => 'Da za tabo ne ostane sledi, izbriši vse našteto:',
    'delete_journal_note' => 'Ob zbirki podatkov ležita dve dnevniški datoteki, :wal in :shm. Tvoje najnovejše spremembe so v njiju, dokler se ne zapišejo v zbirko, zato izbriši vse tri skupaj.',
    'no_telemetry' => 'Ni telemetrije, ki bi jo zavrnil, in ne oddaljenega računa, ki bi ga zaprl.',
];
