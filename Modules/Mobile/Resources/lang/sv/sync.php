<?php

declare(strict_types=1);

return [
    'page_title' => 'Data och enheter',
    'heading' => 'Data och enheter',
    'sync_status' => 'Synkroniseringsstatus',
    'syncing_progress' => 'Synkroniserar… :count post|Synkroniserar… :count poster',
    'initial_sync_aria' => 'Förlopp för första synkroniseringen',
    'no_peers' => 'Parkoppla en annan enhet för att börja synkronisera.',
    'sync_now' => 'Synkronisera nu',
    'result' => [
        'synced' => 'Synkroniserad med din andra enhet.',
        'unreachable' => 'Kunde inte nå din andra enhet — kontrollera att båda är på samma nätverk.',
        'locked' => 'Lås upp appen för att synkronisera.',
        'not_enabled' => 'Synkronisering är inte uppsatt på den här enheten än.',
        'unreadable' => 'Nyckeln på den här enheten går inte att öppna längre. Para ihop igen för att återuppta synkroniseringen.',
        'paused_on_cellular' => 'Pausad — synkronisering är begränsad till Wi-Fi och du är på mobildata.',
    ],
    'background_note' => 'Synkronisering sker när du trycker på Synkronisera nu. Den kan inte köra i bakgrunden — applåset har den enda nyckeln.',
    'network' => 'Nätverk',
    'pause_cellular' => 'Pausa synkronisering på mobildata',
    'pause_cellular_help' => 'Av som standard — synkronisering fungerar överallt. Slå på för att bara synkronisera över Wi-Fi.',
];
