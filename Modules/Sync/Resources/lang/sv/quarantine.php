<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count ändring gjordes av en nyare version av Beatrax|:count ändringar gjordes av en nyare version av Beatrax',
        'body' => 'Det som avvisades pekar på något som den här versionen av Beatrax inte har, så den här enheten hade ingenstans att lägga det. Det finns kvar på enheten som gjorde det, och inget av ditt har raderats.',
        'action' => 'Uppdatera Beatrax på den här enheten. Ändringar som görs efter uppdateringen kommer in som vanligt, men inget som redan avvisats skickas igen — gör om ändringen här om du behöver den på den här enheten också.',
    ],
    'untrusted_author' => [
        'summary' => ':count ändring signerades av en enhet som den här inte känner igen|:count ändringar signerades av en enhet som den här inte känner igen',
        'body' => 'Det som avvisades kom från en enhet som aldrig har parkopplats med den här, eller från en som du tagit bort. Inget skrevs här, och inget av det som redan fanns här ändrades.',
        'action' => 'Om du tog bort den enheten själv är det precis vad en borttagning gör, och det finns inget att rätta till. Om du inte gjorde det, titta i listan över enheter på den här sidan.',
    ],
    'not_verified' => [
        'summary' => ':count ändring klarade inte säkerhetskontrollen på den här enheten|:count ändringar klarade inte säkerhetskontrollen på den här enheten',
        'body' => 'En signatur stämde inte med enheten som uppgav sig ha gjort ändringen, eller så var ändringen adresserad till ett annat konto. Inget skrevs här. Mellan dina egna enheter ska det här inte hända.',
        'action' => 'Titta i listan över enheter på den här sidan och ta bort allt du inte känner igen. Om alla enheter där är dina och det här fortsätter hända är det ett fel i Beatrax och inget du kan rätta till härifrån.',
    ],
    'diverged' => [
        'summary' => ':count ändring från en annan enhet kunde inte sparas här|:count ändringar från en annan enhet kunde inte sparas här',
        'body' => 'Något kom in som den här enheten inte kunde lagra: en post som saknar en del av sig själv, ett datum som inte finns, en uppdelning som inte längre går ihop, en post som två enheter redan gett samma identitet, eller en radering av något som fortfarande används här. Det som avvisades finns på din andra enhet men inte på den här, så de två innehåller inte längre samma sak.',
        'action' => 'Jämför posten på din andra enhet med det du ser här och gör om ändringen här — eller radera den här igen, om något du tog bort någon annanstans fortfarande finns här. Inget avvisat skickas igen av sig själv.',
    ],
    'last_seen' => 'Senast: :when',
];
