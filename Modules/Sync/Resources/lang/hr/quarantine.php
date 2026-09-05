<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count promjena napravljena je novijom verzijom Beatraxa|:count promjene napravljene su novijom verzijom Beatraxa|:count promjena napravljeno je novijom verzijom Beatraxa',
        'body' => 'Ono što je odbijeno navodi nešto što ova verzija Beatraxa nema, pa to ovaj uređaj nije imao gdje spremiti. I dalje je na uređaju koji ga je napravio i ništa tvoje nije obrisano.',
        'action' => 'Ažuriraj Beatrax na ovom uređaju. Promjene napravljene nakon ažuriranja stižu normalno, ali ništa što je već odbijeno ne šalje se ponovno — napravi promjenu ovdje ponovno ako ti treba i na ovom uređaju.',
    ],
    'untrusted_author' => [
        'summary' => ':count promjenu potpisao je uređaj koji ovaj ne prepoznaje|:count promjene potpisao je uređaj koji ovaj ne prepoznaje|:count promjena potpisao je uređaj koji ovaj ne prepoznaje',
        'body' => 'Ono što je odbijeno došlo je s uređaja koji nikada nije bio uparen s ovim ili s uređaja koji si uklonio. Ovdje nije ništa zapisano niti je promijenjeno išta što je već bilo ovdje.',
        'action' => 'Ako si taj uređaj uklonio sam, upravo to uklanjanje i radi i nema se što popravljati. Ako nisi, pogledaj popis uređaja na ovoj stranici.',
    ],
    'not_verified' => [
        'summary' => ':count promjena nije prošla sigurnosnu provjeru na ovom uređaju|:count promjene nisu prošle sigurnosnu provjeru na ovom uređaju|:count promjena nije prošlo sigurnosnu provjeru na ovom uređaju',
        'body' => 'Potpis nije odgovarao uređaju koji je tvrdio da je napravio promjenu ili je promjena bila upućena drugom računu. Ovdje nije ništa zapisano. Među tvojim uređajima ovo se ne bi trebalo događati.',
        'action' => 'Pogledaj popis uređaja na ovoj stranici i ukloni sve što ne prepoznaješ. Ako je svaki uređaj ondje tvoj, a ovo se i dalje događa, riječ je o kvaru u Beatraxu, a ne o nečemu što možeš popraviti odavde.',
    ],
    'diverged' => [
        'summary' => ':count promjena s drugog uređaja nije se mogla spremiti ovdje|:count promjene s drugog uređaja nisu se mogle spremiti ovdje|:count promjena s drugog uređaja nije se moglo spremiti ovdje',
        'body' => 'Stiglo je nešto što ovaj uređaj nije mogao pohraniti: zapis kojemu nedostaje dio njega samog, datum koji ne postoji, podjela koja se više ne poklapa, zapis kojemu su dva uređaja već dala isti identitet ili brisanje nečega što je ovdje još u upotrebi. Ono što je odbijeno nalazi se na tvojem drugom uređaju, a ne na ovom, pa dva uređaja više ne sadrže isto.',
        'action' => 'Usporedi zapis na svojem drugom uređaju s onim što vidiš ovdje i ponovno napravi promjenu ovdje — ili je ovdje ponovno obriši, ako je nešto što si uklonio drugdje još uvijek tu. Ništa odbijeno ne šalje se ponovno samo od sebe.',
    ],
    'last_seen' => 'Najnovije: :when',
];
