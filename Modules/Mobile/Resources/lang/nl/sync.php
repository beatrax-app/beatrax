<?php

declare(strict_types=1);

return [
    'page_title' => 'Gegevens & apparaten',
    'heading' => 'Gegevens & apparaten',
    'sync_status' => 'Synchronisatiestatus',
    'syncing_progress' => 'Synchroniseren… :count record|Synchroniseren… :count records',
    'initial_sync_aria' => 'Voortgang eerste synchronisatie',
    'no_peers' => 'Koppel een ander apparaat om te synchroniseren.',
    'sync_now' => 'Nu synchroniseren',
    'result' => [
        'synced' => 'Gesynchroniseerd met je andere apparaat.',
        'unreachable' => 'Geen verbinding met je andere apparaat — controleer of beide op hetzelfde netwerk zitten.',
        'locked' => 'Ontgrendel de app om te synchroniseren.',
        'not_enabled' => 'Synchroniseren is nog niet ingesteld op dit apparaat.',
        'unreadable' => 'De sleutel op dit apparaat opent niet meer. Koppel opnieuw om te blijven synchroniseren.',
        'paused_on_cellular' => 'Gepauzeerd — synchroniseren staat op alleen wifi en je gebruikt mobiele data.',
    ],
    'background_note' => 'Beatrax blijft luisteren zolang het openstaat, dus een gekoppeld apparaat kan er op elk moment mee synchroniseren. Nu synchroniseren start een gegevensuitwisseling vanaf deze kant.',
    'background_note_phone' => 'Synchroniseren gebeurt wanneer je op Nu synchroniseren tikt. Op de achtergrond kan het niet — de app-vergrendeling heeft de enige sleutel.',
    'network' => 'Netwerk',
    'pause_cellular' => 'Synchroniseren pauzeren op mobiel netwerk',
    'pause_cellular_help' => 'Standaard uit — synchroniseren werkt overal. Zet aan om alleen via wifi te synchroniseren.',
];
