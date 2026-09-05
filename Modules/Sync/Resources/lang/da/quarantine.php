<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count ændring er lavet af en nyere version af Beatrax|:count ændringer er lavet af en nyere version af Beatrax',
        'body' => 'Det, der blev afvist, nævner noget, denne version af Beatrax ikke har, så denne enhed havde ingen steder at lægge det. Det ligger stadig på den enhed, der lavede det, og intet af dit er blevet slettet.',
        'action' => 'Opdater Beatrax på denne enhed. Ændringer lavet efter opdateringen kommer ind som normalt, men intet, der allerede er afvist, bliver sendt igen — lav ændringen her igen, hvis du også har brug for den på denne enhed.',
    ],
    'untrusted_author' => [
        'summary' => ':count ændring er signeret af en enhed, som denne ikke kender|:count ændringer er signeret af en enhed, som denne ikke kender',
        'body' => 'Det, der blev afvist, kom fra en enhed, der aldrig har været parret med denne, eller fra en, du har fjernet. Der blev ikke skrevet noget her, og intet af det, der allerede var her, blev ændret.',
        'action' => 'Hvis du selv har fjernet den enhed, er det netop det, en fjernelse gør, og der er intet at rette. Hvis ikke, så se listen over enheder på denne side.',
    ],
    'not_verified' => [
        'summary' => ':count ændring bestod ikke sikkerhedstjekket på denne enhed|:count ændringer bestod ikke sikkerhedstjekket på denne enhed',
        'body' => 'En signatur passede ikke til den enhed, der hævdede at have lavet ændringen, eller ændringen var adresseret til en anden konto. Der blev ikke skrevet noget her. Mellem dine egne enheder bør det ikke ske.',
        'action' => 'Se listen over enheder på denne side, og fjern alt, du ikke kender. Hvis alle enheder der er dine, og det bliver ved med at ske, er det en fejl i Beatrax og ikke noget, du kan rette herfra.',
    ],
    'diverged' => [
        'summary' => ':count ændring fra en anden enhed kunne ikke gemmes her|:count ændringer fra en anden enhed kunne ikke gemmes her',
        'body' => 'Der kom noget ind, som denne enhed ikke kunne gemme: en post, der mangler en del af sig selv, en dato, der ikke findes, en opdeling, der ikke længere går op, en post, som to enheder allerede havde givet den samme identitet, eller en sletning af noget, der stadig er i brug her. Det, der blev afvist, ligger på din anden enhed og ikke på denne, så de to indeholder ikke længere det samme.',
        'action' => 'Sammenlign posten på din anden enhed med det, du ser her, og lav ændringen om her — eller slet den her igen, hvis noget, du fjernede et andet sted, stadig er her. Intet afvist bliver sendt igen af sig selv.',
    ],
    'last_seen' => 'Senest: :when',
];
