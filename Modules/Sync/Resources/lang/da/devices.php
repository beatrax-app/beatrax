<?php

declare(strict_types=1);

return [
    'heading' => 'Enheder og synkronisering',

    'enable_sync' => 'Aktivér synkronisering',
    'enable_sync_help' => 'Del dine data sikkert mellem betroede enheder. Kræver en app-lås. Når den er slået til, krypteres dine data, og app-låsen kan ikke slås fra igen.',

    'app_lock_notice' => 'Sæt en app-lås op først for at aktivere synkronisering.',
    'go_to_app_lock' => 'Gå til App-lås',

    'identity_unreadable' => 'Denne enheds synkroniseringsidentitet blev oprettet med en anden app-lås og kan ikke længere åbnes. Indtil den kan, kan enheden hverken synkronisere eller parre. Gendanner du den databasesikkerhedskopi, den blev lavet med, kan den læses igen.',
    'identity_unreadable_replace_help' => 'Du kan også starte forfra: enheden får en ny identitet, den gamle bliver liggende ubrugt, og enheder, du har parret før, skal parres igen.',
    'identity_unreadable_replace' => 'Start en ny identitet for denne enhed',

    'encrypted_at_rest' => 'Data krypteret i hvile',
    'encrypted_at_rest_scope' => 'Noter, transaktionsbeskrivelser og navne og IBAN på dem, du betaler, er krypteret i regnskabet med din app-låseadgangskode. Beløb, datoer og din egen kontos navn og IBAN er ikke. Søgeindekset gemmer sin egen læsbare kopi af, hvem du betaler, dine transaktionsbeskrivelser og dine skattenoter, og nogle forhandlernavne står i klartekst andre steder i databasefilen.',
    'on' => 'Til',
    'securing' => 'Beskytter dine data…',
    'do_not_close' => 'Luk ikke dette vindue.',
    'encryption_progress_aria' => 'Fremdrift for krypteringen',
    'not_encrypted_offer' => 'Dine data er ikke krypteret i hvile. Kryptering skjuler, hvem du betaler, hvis denne enhed mistes eller stjæles — beløb, datoer og søgeindekset forbliver læsbare.',
    'enable_encryption' => 'Aktivér kryptering',

    'your_devices' => 'Dine enheder',

    'device_name' => 'Enhedsnavn',
    'save' => 'Gem',
    'peer_default_name' => 'Parret enhed',
    'rename_device' => 'Omdøb enhed',
    'rename_device_caption' => 'Omdøb',
    'this_device' => 'Denne enhed',
    'removed' => 'Fjernet',
    'confirmed' => 'Bekræftet',
    'awaiting_confirmation' => 'Afventer bekræftelse',
    'safety_number_words' => 'Ord for sikkerhedsnummer:',
    'paired' => 'Parret',
    'remove_aria' => 'Fjern :name',
    'remove' => 'Fjern',
    'pair_new_device' => 'Par en ny enhed',

    'pairing_waiting' => 'Afslut parringen med :name',
    'pairing_waiting_help' => 'Begge skærme skal vise de samme seks ord, før parringen tæller. Åbn den igen for at sammenligne dem.',
    'pairing_waiting_resume' => 'Fortsæt parringen',
    'pairing_waiting_lock_override' => 'Oplåsning åbner denne parring igen i stedet for at lade den udløbe, så den varer længere end den app-låsetid, du har angivet. Den slutter, når du fuldfører eller annullerer den.',

    'relay_endpoint' => 'Relay-endepunkt',
    'relay_endpoint_help' => 'Valgfrit. Når det er angivet, synkroniserer offline-enheder via dette relay. Lad feltet stå tomt for kun LAN&#8209;direkte.',
    'relay_endpoint_help_phone' => 'Valgfrit. Når det er angivet, rejser ændringer via dette relay, også når dine enheder ikke er på samme netværk. Denne enhed henter dem, når du synkroniserer fra denne skærm — aldrig i baggrunden, for app-låsen har den eneste nøgle. Lad feltet stå tomt for kun LAN&#8209;direkte.',
    'relay_endpoint_aria' => 'URL til relay-endepunkt',
    'relay_insecure_warning' => 'Dette relay-endepunkt bruger almindelig HTTP. Relayet dekrypterer aldrig dine data, men en usikker forbindelse afslører krypterede størrelser og tidspunkter over for dem, der overvåger netværket. Brug et <strong>https://</strong>-endepunkt for det bedste privatliv.',

    'enable_at_rest' => 'Aktivér kryptering i hvile',
    'enable_at_rest_body' => 'Dine data bliver krypteret med adgangssætningen til din app-lås. Der oprettes automatisk en sikkerhedskopi inden migreringen.',
    'no_recovery_warning' => 'Hvis du mister adgangssætningen til din app-lås og hverken har en sikkerhedskopi eller en anden betroet enhed, kan dine data ikke gendannes.',
    'recover_help' => 'For at få adgang igen kan du parre denne enhed på ny fra en anden betroet enhed eller bruge din uafhængige krypterede sikkerhedskopi.',
    'amounts_plaintext' => 'Beløb krypteres ikke i hvile — saldi og totaler forbliver læsbare, så dine månedstotaler bliver ved med at stemme.',
    'search_plaintext' => 'Søgeindekset gemmer en kopi i klartekst af forhandler- og beskrivelsestekst, så fritekstsøgning bliver ved med at virke.',
    'keep_unencrypted' => 'Behold data ukrypteret',
    'encryption_enabled' => 'Kryptering aktiveret',
    'encryption_enabled_scope' => 'Noter, beskrivelser og hvem du betaler, er nu krypteret med din app-låseadgangskode. Beløb, datoer og søgeindekset forbliver læsbare.',
    'done_encryption_enabled' => 'Færdig — kryptering aktiveret',
    'encryption_failed' => 'Opsætning af kryptering mislykkedes',
    'encryption_failed_body' => 'Dine data blev ikke ændret. Din sikkerhedskopi blev bevaret.',
    'close_no_changes' => 'Luk — ingen ændringer blev foretaget',

    'remove_this_device' => 'Fjern denne enhed',
    'removing' => 'Fjerner:',
    'remove_rotates_key' => 'Når du fjerner denne enhed, roteres krypteringsnøglen, så den ikke modtager fremtidige opdateringer.',
    'remove_cannot_erase' => 'Det kan ikke slette data, der allerede ligger på den enhed. Hvis denne enhed er blevet væk eller stjålet, så betragt alle data, den indeholdt, som kompromitteret.',
    'remove_is_local' => 'Dine andre enheder har deres egen liste. Indtil du også fjerner den der, bliver de ved med at synkronisere med den.',
    'remove_device' => 'Fjern enhed',
    'keep_device' => 'Behold enhed',
    'rotating_key' => 'Roterer krypteringsnøglen…',

    'flash' => [
        'app_lock_first' => 'Sæt en app-lås op først for at aktivere synkronisering.',
        'enable_failed' => 'Synkronisering kunne ikke aktiveres. Sørg for, at din app-lås er aktiv, og prøv igen.',
        'identity_replaced' => 'Denne enhed har en ny synkroniseringsidentitet. Par dine andre enheder igen.',
        'identity_replace_failed' => 'Den gamle enhedsidentitet kunne ikke lægges til side. Prøv igen.',
        'cannot_remove_self' => 'Du kan ikke fjerne denne enhed — det er den, du bruger.',
        'remove_failed' => 'Enheden kunne ikke fjernes. Prøv igen.',
        'app_lock_first_settings' => 'Sæt en app-lås op først for at ændre synkroniseringsindstillingerne.',
        'relay_cleared' => 'Relay-endepunktet blev ryddet.',
        'relay_saved' => 'Relay-endepunktet blev gemt.',
        'relay_save_failed' => 'Relay-endepunktet kunne ikke gemmes: :message',
    ],
    'app_lock_permanent' => 'Når dine data først er krypteret, kan applåsen ikke slås fra — den holder den eneste nøgle, og der er ingen vej tilbage til ukrypteret.',
    'backlog_heading' => 'Venter på at blive tilføjet',
    'backlog_deferred' => 'Denne enhed har modtaget data fra en anden enhed og har endnu ikke føjet dem til dit regnskab. Intet går tabt — det sker automatisk, normalt inden for et øjeblik.',
    'backlog_awaiting_key' => 'Denne enhed har modtaget data, som den endnu ikke har nøglen til. Intet går tabt. Åbn appen på den enhed, du har parret med, mens denne er åben, så de to kan forbinde og nøglen kan sendes.',
];
