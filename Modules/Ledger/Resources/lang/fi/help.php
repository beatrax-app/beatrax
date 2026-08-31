<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Täsmäytys tarkoittaa Beatraxin vertaamista pankin omaan lukuun. Täsmäytetty saldo on tämän tilin alkusaldo plus jokainen rivi, jonka olet merkinnyt selvitetyksi tiliotteen päivään asti, ja erotus on tiliotteesi luku miinus tuo saldo. Merkitse tai poista merkintöjä tapahtumalistalla, kunnes erotus on nolla — tämä näkymä ei koskaan keksi tasauskirjausta. ”:complete” lukitsee sen jälkeen kattamansa rivit: lukittua riviä ei voi muokata, jakaa eikä poistaa ennen kuin avaat sen uudelleen sen omalta sivulta.',
];
