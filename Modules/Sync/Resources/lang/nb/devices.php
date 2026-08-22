<?php

declare(strict_types=1);

return [
    'heading' => 'Enheter og synkronisering',

    'enable_sync' => 'Aktiver synkronisering',
    'enable_sync_help' => 'Del dataene dine sikkert mellom betrodde enheter. Krever en applås. Når den først er på, krypteres dataene dine og applåsen kan ikke slås av igjen.',

    'app_lock_notice' => 'Sett opp en applås først for å aktivere synkronisering.',
    'go_to_app_lock' => 'Gå til Applås',

    'identity_unreadable' => 'Synkroniseringsidentiteten til denne enheten ble opprettet med en annen app-lås og åpnes ikke lenger. Så lenge det er slik, kan enheten verken synkronisere eller pare. Gjenoppretter du databasesikkerhetskopien den ble laget med, kan den leses igjen.',
    'identity_unreadable_replace_help' => 'Du kan også begynne på nytt: enheten får en ny identitet, den gamle blir liggende ubrukt, og enheter du har paret før må pares på nytt.',
    'identity_unreadable_replace' => 'Start en ny identitet for denne enheten',

    'encrypted_at_rest' => 'Data kryptert i hvile',
    'encrypted_at_rest_scope' => 'Notater, transaksjonsbeskrivelser og navn og IBAN til dem du betaler, er kryptert i regnskapet med applåspassordet ditt. Beløp, datoer og ditt eget kontonavn og IBAN er ikke det. Søkeindeksen beholder sin egen lesbare kopi av hvem du betaler, transaksjonsbeskrivelsene dine og skattenotatene dine, og enkelte butikknavn står i klartekst andre steder i databasefilen.',
    'on' => 'På',
    'securing' => 'Sikrer dataene dine…',
    'do_not_close' => 'Ikke lukk dette vinduet.',
    'encryption_progress_aria' => 'Fremdrift for krypteringen',
    'not_encrypted_offer' => 'Dataene dine er ikke kryptert i hvile. Kryptering skjuler hvem du betaler hvis enheten mistes eller stjeles — beløp, datoer og søkeindeksen forblir lesbare.',
    'enable_encryption' => 'Aktiver kryptering',

    'your_devices' => 'Enhetene dine',

    // Settings keeps a pointer to the moved surface; the section
    // itself now lives on /sync with the status and sync action.
    'moved_help' => 'Paring, enhetsnavn og kryptering ligger nå sammen med synkroniseringsstatusen din.',
    'moved_cta' => 'Åpne Synkronisering og enhet',
    'device_name' => 'Enhetsnavn',
    'save' => 'Lagre',
    'peer_default_name' => 'Paret enhet',
    'rename_device' => 'Gi enheten nytt navn',
    'this_device' => 'Denne enheten',
    'removed' => 'Fjernet',
    'confirmed' => 'Bekreftet',
    'awaiting_confirmation' => 'Venter på bekreftelse',
    'safety_number_words' => 'Ord for sikkerhetsnummer:',
    'paired' => 'Paret',
    'remove_aria' => 'Fjern :name',
    'remove' => 'Fjern',
    'pair_new_device' => 'Par en ny enhet',

    'pairing_waiting' => 'Fullfør paringen med :name',
    'pairing_waiting_help' => 'Begge skjermene må vise de samme seks ordene før paringen teller. Åpne den igjen for å sammenligne dem.',
    'pairing_waiting_resume' => 'Fortsett paringen',
    'pairing_waiting_lock_override' => 'Opplåsing åpner denne paringen på nytt i stedet for å la den utløpe, så den varer lenger enn app-låstiden du har satt. Den avsluttes når du fullfører eller avbryter den.',

    'relay_endpoint' => 'Relay-endepunkt',
    'relay_endpoint_help' => 'Valgfritt. Når det er angitt, synkroniserer frakoblede enheter via dette relayet. La feltet stå tomt for bare LAN&#8209;direkte.',
    'relay_endpoint_aria' => 'URL til relay-endepunkt',
    'relay_insecure_warning' => 'Dette relay-endepunktet bruker vanlig HTTP. Relayet dekrypterer aldri dataene dine, men en usikker tilkobling avslører krypterte størrelser og tidspunkter for dem som overvåker nettverket. Bruk et <strong>https://</strong>-endepunkt for best mulig personvern.',

    'enable_at_rest' => 'Aktiver kryptering i hvile',
    'enable_at_rest_body' => 'Dataene dine krypteres med passordfrasen for applåsen din. Det opprettes automatisk en sikkerhetskopi før migreringen.',
    'no_recovery_warning' => 'Hvis du mister passordfrasen for applåsen og verken har en sikkerhetskopi eller en annen betrodd enhet, kan ikke dataene dine gjenopprettes.',
    'recover_help' => 'For å få tilgang igjen kan du pare denne enheten på nytt fra en annen betrodd enhet, eller bruke den uavhengige krypterte sikkerhetskopien din.',
    'amounts_plaintext' => 'Beløp krypteres ikke i hvile — saldoer og totaler forblir lesbare slik at månedstotalene dine fortsatt stemmer.',
    'search_plaintext' => 'Søkeindeksen beholder en kopi i klartekst av forhandler- og beskrivelsestekst slik at fritekstsøk fortsatt fungerer.',
    'keep_unencrypted' => 'Behold data ukryptert',
    'encryption_enabled' => 'Kryptering aktivert',
    'encryption_enabled_scope' => 'Notater, beskrivelser og hvem du betaler, er nå kryptert med applåspassordet ditt. Beløp, datoer og søkeindeksen forblir lesbare.',
    'done_encryption_enabled' => 'Ferdig — kryptering aktivert',
    'encryption_failed' => 'Oppsett av kryptering mislyktes',
    'encryption_failed_body' => 'Dataene dine ble ikke endret. Sikkerhetskopien din ble bevart.',
    'close_no_changes' => 'Lukk — ingen endringer ble gjort',

    'remove_this_device' => 'Fjern denne enheten',
    'removing' => 'Fjerner:',
    'remove_rotates_key' => 'Når du fjerner denne enheten, roteres krypteringsnøkkelen slik at den ikke mottar fremtidige oppdateringer.',
    'remove_cannot_erase' => 'Det kan ikke slette data som allerede ligger på den enheten. Hvis denne enheten er mistet eller stjålet, bør du regne alle data den inneholdt, som kompromittert.',
    'remove_device' => 'Fjern enhet',
    'keep_device' => 'Behold enhet',
    'rotating_key' => 'Roterer krypteringsnøkkelen…',

    'flash' => [
        'app_lock_first' => 'Sett opp en applås først for å aktivere synkronisering.',
        'enable_failed' => 'Kunne ikke aktivere synkronisering. Sørg for at applåsen din er aktiv, og prøv igjen.',
        'identity_replaced' => 'Denne enheten har en ny synkroniseringsidentitet. Par de andre enhetene dine på nytt.',
        'identity_replace_failed' => 'Den gamle enhetsidentiteten kunne ikke legges til side. Prøv igjen.',
        'cannot_remove_self' => 'Du kan ikke fjerne denne enheten — det er den du bruker.',
        'remove_failed' => 'Kunne ikke fjerne enheten. Prøv igjen.',
        'app_lock_first_settings' => 'Sett opp en applås først for å endre synkroniseringsinnstillingene.',
        'relay_cleared' => 'Relay-endepunktet ble tømt.',
        'relay_saved' => 'Relay-endepunktet ble lagret.',
        'relay_save_failed' => 'Kunne ikke lagre relay-endepunktet: :message',
    ],
    'app_lock_permanent' => 'Når dataene dine først er kryptert, kan ikke applåsen slås av — den holder den eneste nøkkelen, og det finnes ingen vei tilbake til ukryptert.',
    'backlog_heading' => 'Venter på å bli lagt til',
    'backlog_deferred' => 'Denne enheten har mottatt data fra en annen enhet og har ennå ikke lagt dem til i regnskapet ditt. Ingenting går tapt — det skjer automatisk, vanligvis i løpet av et øyeblikk.',
    'backlog_awaiting_key' => 'Denne enheten har mottatt data den ennå ikke har nøkkelen til. Ingenting går tapt. Åpne appen på enheten du paret med mens denne er åpen, slik at de to kan koble til og nøkkelen kan sendes.',
];
