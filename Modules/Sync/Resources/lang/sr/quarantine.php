<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count promena napravljena je novijom verzijom Beatraxa|:count promene napravljene su novijom verzijom Beatraxa|:count promena napravljeno je novijom verzijom Beatraxa',
        'body' => 'Ono što je odbijeno navodi nešto što ova verzija Beatraxa nema, pa ovaj uređaj nije imao gde to da smesti. I dalje je na uređaju koji ga je napravio i ništa tvoje nije obrisano.',
        'action' => 'Ažuriraj Beatrax na ovom uređaju. Promene napravljene posle ažuriranja stižu normalno, ali ništa što je već odbijeno ne šalje se ponovo — napravi promenu ovde ponovo ako ti treba i na ovom uređaju.',
    ],
    'untrusted_author' => [
        'summary' => ':count promenu potpisao je uređaj koji ovaj ne prepoznaje|:count promene potpisao je uređaj koji ovaj ne prepoznaje|:count promena potpisao je uređaj koji ovaj ne prepoznaje',
        'body' => 'Ono što je odbijeno došlo je sa uređaja koji nikada nije bio uparen sa ovim ili sa uređaja koji si uklonio. Ovde nije ništa zapisano niti je promenjeno išta što je već bilo ovde.',
        'action' => 'Ako si taj uređaj uklonio sam, upravo to uklanjanje i radi i nema šta da se popravlja. Ako nisi, pogledaj spisak uređaja na ovoj stranici.',
    ],
    'not_verified' => [
        'summary' => ':count promena nije prošla bezbednosnu proveru na ovom uređaju|:count promene nisu prošle bezbednosnu proveru na ovom uređaju|:count promena nije prošlo bezbednosnu proveru na ovom uređaju',
        'body' => 'Potpis nije odgovarao uređaju koji je tvrdio da je napravio promenu ili je promena bila upućena drugom nalogu. Ovde nije ništa zapisano. Među tvojim uređajima ovo ne bi trebalo da se dešava.',
        'action' => 'Pogledaj spisak uređaja na ovoj stranici i ukloni sve što ne prepoznaješ. Ako je svaki uređaj tamo tvoj, a ovo se i dalje dešava, reč je o kvaru u Beatraxu, a ne o nečemu što možeš da popraviš odavde.',
    ],
    'diverged' => [
        'summary' => ':count promena sa drugog uređaja nije mogla da se sačuva ovde|:count promene sa drugog uređaja nisu mogle da se sačuvaju ovde|:count promena sa drugog uređaja nije moglo da se sačuva ovde',
        'body' => 'Stiglo je nešto što ovaj uređaj nije mogao da sačuva: zapis kome nedostaje deo njega samog, datum koji ne postoji, podela koja se više ne poklapa, zapis kome su dva uređaja već dala isti identitet ili brisanje nečega što je ovde još u upotrebi. Ono što je odbijeno nalazi se na tvom drugom uređaju, a ne na ovom, pa dva uređaja više ne sadrže isto.',
        'action' => 'Uporedi zapis na svom drugom uređaju sa onim što vidiš ovde i ponovo napravi promenu ovde — ili je ovde ponovo obriši, ako je nešto što si uklonio drugde još uvek tu. Ništa odbijeno ne šalje se ponovo samo od sebe.',
    ],
    'last_seen' => 'Najnovije: :when',
];
