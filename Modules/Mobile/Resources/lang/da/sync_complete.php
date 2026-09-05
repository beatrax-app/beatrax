<?php

declare(strict_types=1);

return [
    'page_title' => 'Denne enhed er synkroniseret',
    'heading' => 'Denne enhed er synkroniseret',
    'records' => 'Kopierede :count post fra :peer.|Kopierede :count poster fra :peer.',
    'records_none' => 'Ajour med :peer. Der var ikke noget nyt at kopiere.',
    'withheld' => ':count ændring er ikke kommet frem endnu.|:count ændringer er ikke kommet frem endnu.',
    'withheld_action' => 'De er signeret af en enhed, som denne enhed ikke kan tjekke. Intet er tabt — alt bliver på :peer og kommer, hvis en af dine enheder videregiver den identitet, og du bekræfter den under :section.',
    'how_it_works' => 'Herfra og frem',
    'automatic_title' => 'Du bestemmer, hvornår den synkroniserer',
    'automatic_body' => 'Alt, du ændrer på den ene enhed, dukker op på den anden, næste gang du trykker på :action. Den kan ikke køre i baggrunden — app-låsen har den eneste nøgle.',
    'lan_title' => 'På samme netværk',
    'lan_body' => 'Når begge enheder er på dit hjemmenetværk, taler de direkte med hinanden uden noget imellem.',
    'relay_title' => 'Når du er ude',
    'relay_body' => 'Ændringer venter krypteret på din relay, indtil den anden enhed er online igen. Denne enhed henter dem, næste gang du trykker på :action.',
    'no_relay_title' => 'Når du er ude',
    'no_relay_body' => 'Ændringer venter på denne enhed, indtil begge er på dit hjemmenetværk samtidig, og du trykker på :action her.',
    'encrypted_title' => 'Kun dine enheder kan læse det',
    'encrypted_body' => 'Alt bliver krypteret, før det forlader en enhed, og kun dine parrede enheder har nøglerne.',
    'continue' => 'Begynd at bruge Beatrax',
    'peer_fallback' => 'din anden enhed',
];
