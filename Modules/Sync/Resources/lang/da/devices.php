<?php

declare(strict_types=1);

return [
    'heading' => 'Enheder og synkronisering',

    'enable_sync' => 'Aktivér synkronisering',
    'enable_sync_help' => 'Del dine data sikkert mellem betroede enheder. Kræver en app-lås.',

    'app_lock_notice' => 'Sæt en app-lås op først for at aktivere synkronisering.',
    'go_to_app_lock' => 'Gå til App-lås',

    'encrypted_at_rest' => 'Data krypteret i hvile',
    'encrypted_at_rest_help' => 'Dine data er beskyttet med adgangssætningen til din app-lås.',
    'on' => 'Til',
    'securing' => 'Beskytter dine data…',
    'do_not_close' => 'Luk ikke dette vindue.',
    'encryption_progress_aria' => 'Fremdrift for krypteringen',
    'not_encrypted_offer' => 'Dine data er ikke krypteret i hvile. Sæt kryptering op for at beskytte dem, hvis denne enhed bliver væk eller stjålet.',
    'enable_encryption' => 'Aktivér kryptering',

    'your_devices' => 'Dine enheder',

    // Settings keeps a pointer to the moved surface; the section
    // itself now lives on /sync with the status and sync action.
    'moved_help' => 'Parring, enhedsnavne og kryptering findes nu sammen med din synkroniseringsstatus.',
    'moved_cta' => 'Åbn Synkronisering og enhed',
    'device_name' => 'Enhedsnavn',
    'save' => 'Gem',
    'peer_default_name' => 'Parret enhed',
    'rename_device' => 'Omdøb enhed',
    'this_device' => 'Denne enhed',
    'removed' => 'Fjernet',
    'confirmed' => 'Bekræftet',
    'awaiting_confirmation' => 'Afventer bekræftelse',
    'safety_number_words' => 'Ord for sikkerhedsnummer:',
    'paired' => 'Parret',
    'remove_aria' => 'Fjern :name',
    'remove' => 'Fjern',
    'pair_new_device' => 'Par en ny enhed',

    'relay_endpoint' => 'Relay-endepunkt',
    'relay_endpoint_help' => 'Valgfrit. Når det er angivet, synkroniserer offline-enheder via dette relay. Lad feltet stå tomt for kun LAN&#8209;direkte.',
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
    'encryption_enabled_body' => 'Dine data er nu krypteret i hvile.',
    'done_encryption_enabled' => 'Færdig — kryptering aktiveret',
    'encryption_failed' => 'Opsætning af kryptering mislykkedes',
    'encryption_failed_body' => 'Dine data blev ikke ændret. Din sikkerhedskopi blev bevaret.',
    'close_no_changes' => 'Luk — ingen ændringer blev foretaget',

    'remove_this_device' => 'Fjern denne enhed',
    'removing' => 'Fjerner:',
    'remove_rotates_key' => 'Når du fjerner denne enhed, roteres krypteringsnøglen, så den ikke modtager fremtidige opdateringer.',
    'remove_cannot_erase' => 'Det kan ikke slette data, der allerede ligger på den enhed. Hvis denne enhed er blevet væk eller stjålet, så betragt alle data, den indeholdt, som kompromitteret.',
    'remove_device' => 'Fjern enhed',
    'keep_device' => 'Behold enhed',
    'rotating_key' => 'Roterer krypteringsnøglen…',

    'flash' => [
        'app_lock_first' => 'Sæt en app-lås op først for at aktivere synkronisering.',
        'enable_failed' => 'Synkronisering kunne ikke aktiveres. Sørg for, at din app-lås er aktiv, og prøv igen.',
        'cannot_remove_self' => 'Du kan ikke fjerne denne enhed — det er den, du bruger.',
        'remove_failed' => 'Enheden kunne ikke fjernes. Prøv igen.',
        'app_lock_first_settings' => 'Sæt en app-lås op først for at ændre synkroniseringsindstillingerne.',
        'relay_cleared' => 'Relay-endepunktet blev ryddet.',
        'relay_saved' => 'Relay-endepunktet blev gemt.',
        'relay_save_failed' => 'Relay-endepunktet kunne ikke gemmes: :message',
    ],
];
