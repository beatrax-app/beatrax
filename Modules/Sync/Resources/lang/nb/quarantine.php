<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count endring er gjort av en nyere versjon av Beatrax|:count endringer er gjort av en nyere versjon av Beatrax',
        'body' => 'Det som ble avvist, nevner noe denne versjonen av Beatrax ikke har, så denne enheten hadde ingen steder å gjøre av det. Det ligger fortsatt på enheten som gjorde det, og ingenting av ditt er slettet.',
        'action' => 'Oppdater Beatrax på denne enheten. Endringer gjort etter oppdateringen kommer inn som normalt, men ingenting som allerede er avvist, sendes på nytt — gjør endringen her igjen hvis du trenger den på denne enheten også.',
    ],
    'untrusted_author' => [
        'summary' => ':count endring er signert av en enhet denne ikke kjenner igjen|:count endringer er signert av en enhet denne ikke kjenner igjen',
        'body' => 'Det som ble avvist, kom fra en enhet som aldri har vært paret med denne, eller fra en du fjernet. Ingenting ble skrevet her, og ingenting av det som allerede var her, ble endret.',
        'action' => 'Hvis du fjernet den enheten selv, er det nettopp dette en fjerning gjør, og det er ingenting å rette opp. Hvis ikke, se listen over enheter på denne siden.',
    ],
    'not_verified' => [
        'summary' => ':count endring bestod ikke sikkerhetssjekken på denne enheten|:count endringer bestod ikke sikkerhetssjekken på denne enheten',
        'body' => 'En signatur stemte ikke med enheten som hevdet å ha gjort endringen, eller endringen var adressert til en annen konto. Ingenting ble skrevet her. Mellom dine egne enheter skal dette ikke skje.',
        'action' => 'Se listen over enheter på denne siden og fjern alt du ikke kjenner igjen. Hvis alle enhetene der er dine og dette fortsetter å skje, er det en feil i Beatrax og ikke noe du kan rette opp herfra.',
    ],
    'diverged' => [
        'summary' => ':count endring fra en annen enhet kunne ikke lagres her|:count endringer fra en annen enhet kunne ikke lagres her',
        'body' => 'Det kom inn noe denne enheten ikke kunne lagre: en oppføring som mangler en del av seg selv, en dato som ikke finnes, en oppdeling som ikke lenger går opp, en oppføring to enheter allerede hadde gitt samme identitet, eller en sletting av noe som fortsatt er i bruk her. Det som ble avvist, ligger på den andre enheten din og ikke på denne, så de to inneholder ikke lenger det samme.',
        'action' => 'Sammenlign oppføringen på den andre enheten din med det du ser her, og gjør endringen om igjen her — eller slett den her på nytt, hvis noe du fjernet et annet sted fortsatt er her. Ingenting avvist sendes på nytt av seg selv.',
    ],
    'last_seen' => 'Sist: :when',
];
