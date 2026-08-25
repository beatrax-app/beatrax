<?php

declare(strict_types=1);

return [
    'back' => 'Vissza a Beatraxhoz',

    '404' => [
        'title' => 'Ez az oldal nem létezik',
        'body' => 'A hivatkozás lehet régi, vagy az oldal nevet kapott. Az adataiddal semmi baj.',
    ],
    '4xx' => [
        'title' => 'Ezt a kérést nem lehet feldolgozni',
        'body' => 'Az oldal olyan módon nyílt meg, amelyre nem számít. Az adatai változatlanok.',
    ],

    '419' => [
        'title' => 'A munkameneted lejárt',
        'body' => 'Elég sokáig voltál távol ahhoz, hogy az oldal elavuljon. Nyisd meg újra a Beatraxot, és folytasd.',
    ],

    '500' => [
        'title' => 'Valami elromlott',
        'body' => 'A hiba bekerült az eszköz naplójába. Az adataid nem változtak.',
    ],

    '503' => [
        'title' => 'A Beatrax rövid ideig nem érhető el',
        'body' => 'Egy frissítés vagy karbantartás fejeződik be. Próbáld újra egy pillanat múlva.',
    ],
];
