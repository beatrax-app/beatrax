<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Paret enhet',
    'page_title' => 'Par en enhet',

    'scan_heading' => 'Par denne enheten',
    'scan_subtitle' => 'Rett kameraet mot koden som vises på den andre enheten.',
    'camera_permission_pending' => 'Kameratilgangen er av. Tillat den for Beatrax i innstillingene på enheten, og prøv igjen.',
    'open_camera' => 'Åpne kameraet',
    'opening_camera' => 'Venter på kameratilgang…',
    'close_camera' => 'Lukk kameraet',
    'viewfinder_aria' => 'Kameraets søker — rett den mot koden på den andre enheten din',
    'viewfinder_idle' => 'Kameraet er av. Åpne det for å skanne koden som vises på den andre enheten din.',
    'scan_prompt' => 'Skann koden på den andre enheten din',
    'enter_code_instead' => 'Skriv inn koden i stedet',

    'enter_heading' => 'Skriv inn koden',
    'camera_off' => 'Kameratilgangen er av. Skriv inn koden fra den andre enheten i stedet.',
    'camera_off_no_search' => 'Kameratilgangen er av, og å søke etter den andre enheten på nettverket virker ikke på iPhone ennå — en kode du skriver inn har dermed ingenting å finne den med. Slå kameratilgangen på igjen for Beatrax i innstillingene på enheten, og skann koden på den andre enheten.',
    'no_search' => 'Å søke etter den andre enheten på nettverket virker ikke på iPhone ennå, så en kode du skriver inn har ingenting å finne. Skann koden med kameraet i stedet — kameraet trenger ikke å søke på nettverket.',
    'word_code_aria' => 'Skriv inn ordkoden fra den andre enheten',
    'submit_code' => 'Send koden',
    'cancel' => 'Avbryt',
    'skip_import' => 'Fortsett uten å importere',

    'confirm_heading' => 'Sammenlign disse ordene med den andre enheten',
    'safety_words_aria' => 'Ord for sikkerhetsnummer: :words',
    'confirm_body' => 'Begge enhetene må vise nøyaktig de samme ordene. Hvis de er forskjellige, trykk på Avbryt — et man-in-the-middle-angrep kan pågå.',
    'awaiting_peer' => 'Venter på at den andre enheten skal bekrefte...',
    'confirm_match' => 'Bekreft — de er like',

    'success_heading' => 'Enheten er paret',
    'success_body' => 'Denne enheten er nå betrodd. Dataene dine synkroniseres så snart du kobler til.',
    'done' => 'Ferdig',

    'errors' => [
        'relay_unreachable' => 'Får ikke kontakt med den andre enheten. Sørg for at begge er på samme nettverk, og at synkronisering er slått på på datamaskinen.',
        'no_road_home' => 'Denne enheten kan ikke søke på nettverket, og koden du skannet inneholder ingen adresse til den andre enheten. Be den vise en ny kode, og skann den i stedet.',
        'invalid_code' => 'Koden er ugyldig eller har utløpt. Be den andre enheten om å generere en ny.',
        'code_incomplete' => 'Koden er ikke fullstendig. Sammenlign den med den andre enheten og skriv inn hele koden.',
        'code_not_accepted' => 'Ingen enhet på dette nettverket godtok koden. Sjekk koden, og at den andre enheten fortsatt viser den.',
        'no_peer_answered' => 'Ingenting på dette nettverket svarte på koden. Sjekk at synkronisering kjører på den andre enheten, eller skann koden dens med kameraet — kameraet trenger ikke å søke på nettverket.',
        'no_peer_answered_ios' => 'Ingenting på dette nettverket svarte på koden. Å søke etter den andre enheten på nettverket virker ikke på iPhone ennå, så skann koden dens med kameraet.',
        'no_peer_answered_camera_off' => 'Ingenting på dette nettverket svarte på koden. Å søke etter den andre enheten på nettverket virker ikke på iPhone ennå, og kameratilgangen er av — slå derfor kameratilgangen på igjen for Beatrax i innstillingene på enheten, og skann koden på den andre enheten.',
        'rate_limited' => 'For mange forsøk. Vent ett minutt og prøv igjen.',
        'identity_locked' => 'Enhetsidentiteten din er låst. Lås opp appen og prøv igjen.',
        'identity_needs_lock' => 'Sett opp app-låsen først — den beskytter enhetens identitet.',
        'safety_number_changed' => 'Den andre enheten ble endret mens du sammenlignet. Sjekk ordene nedenfor på nytt før du bekrefter.',
    ],
];
