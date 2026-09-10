<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Täsmäytys tarkoittaa Beatraxin vertaamista pankin omaan lukuun. Täsmäytetty saldo on tämän tilin alkusaldo plus jokainen rivi, jonka olet merkinnyt selvitetyksi tiliotteen päivään asti, ja erotus on tiliotteesi luku miinus tuo saldo. Merkitse tai poista merkintöjä tapahtumalistalla, kunnes erotus on nolla — tämä näkymä ei koskaan keksi tasauskirjausta. ”:complete” lukitsee sen jälkeen kattamansa rivit: lukittua riviä ei voi muokata, jakaa eikä poistaa ennen kuin avaat sen uudelleen sen omalta sivulta.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Missä rivi on suhteessa tiliotteeseesi. ”:uncleared” tarkoittaa, että Beatraxilla on tapahtuma mutta et ole vielä kohdistanut sitä otteeseen; napauta pilleriä, niin siitä tulee ”:cleared” — juuri nämä merkinnät täsmäytysnäkymä laskee yhteen. ”:reconciled” ei ole napautettavissa: sen asettaa valmis täsmäytys, joka lukitsee rivin, joten luokka, muistiinpano, jako ja veromerkinnät pysyvät ennallaan, kunnes avaat lukituksen uudelleen.',
];
