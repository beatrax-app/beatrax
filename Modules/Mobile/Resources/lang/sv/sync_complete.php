<?php

declare(strict_types=1);

return [
    'page_title' => 'Den här enheten är synkroniserad',
    'heading' => 'Den här enheten är synkroniserad',
    'records' => 'Kopierade :count post från :peer.|Kopierade :count poster från :peer.',
    'records_none' => 'Ikapp med :peer. Det fanns inget nytt att kopiera.',
    'withheld' => ':count ändring har inte kommit fram än.|:count ändringar har inte kommit fram än.',
    'withheld_action' => 'De är signerade av en enhet som den här enheten inte kan kontrollera. Ingenting går förlorat — allt blir kvar på :peer och kommer fram när någon av dina enheter skickar vidare den identiteten och du bekräftar den under :section.',
    'how_it_works' => 'Härifrån och framåt',
    'automatic_title' => 'Du väljer när den synkroniserar',
    'automatic_body' => 'Allt du ändrar på den ena enheten dyker upp på den andra nästa gång du trycker på :action. Den kan inte köra i bakgrunden — applåset har den enda nyckeln.',
    'lan_title' => 'På samma nätverk',
    'lan_body' => 'När båda enheterna är på ditt hemnätverk pratar de direkt med varandra, utan något däremellan.',
    'relay_title' => 'När du är borta',
    'relay_body' => 'Ändringar väntar krypterade på din relay tills den andra enheten är online igen. Den här enheten hämtar dem nästa gång du trycker på :action.',
    'no_relay_title' => 'När du är borta',
    'no_relay_body' => 'Ändringar väntar på den här enheten tills båda är på ditt hemnätverk samtidigt och du trycker på :action här.',
    'encrypted_title' => 'Bara dina enheter kan läsa det',
    'encrypted_body' => 'Allt krypteras innan det lämnar en enhet, och bara dina parkopplade enheter har nycklarna.',
    'continue' => 'Börja använda Beatrax',
    'peer_fallback' => 'din andra enhet',
];
