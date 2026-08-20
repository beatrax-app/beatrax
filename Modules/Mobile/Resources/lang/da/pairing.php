<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Parret enhed',
    'page_title' => 'Par en enhed',

    'scan_heading' => 'Par denne enhed',
    'scan_subtitle' => 'Ret kameraet mod koden, der vises på den anden enhed.',
    'camera_permission_pending' => 'Kameraadgang er slået fra. Tillad den for Beatrax i enhedens indstillinger, og prøv igen.',
    'open_camera' => 'Åbn kameraet',
    'opening_camera' => 'Venter på kameraadgang…',
    'close_camera' => 'Luk kameraet',
    'viewfinder_aria' => 'Kameraets søger — ret den mod koden på din anden enhed',
    'viewfinder_idle' => 'Kameraet er slået fra. Åbn det for at scanne koden, der vises på din anden enhed.',
    'scan_prompt' => 'Scan koden på din anden enhed',
    'enter_code_instead' => 'Indtast koden i stedet',

    'enter_heading' => 'Indtast koden',
    'camera_off' => 'Kameraadgang er slået fra. Indtast koden fra den anden enhed i stedet.',
    'word_code_aria' => 'Indtast ordkoden fra den anden enhed',
    'submit_code' => 'Send koden',
    'cancel' => 'Annullér',

    'confirm_heading' => 'Sammenlign disse ord med den anden enhed',
    'safety_words_aria' => 'Ord for sikkerhedsnummer: :words',
    'confirm_body' => 'Begge enheder skal vise præcis de samme ord. Hvis de er forskellige, så tryk på Annullér — et man-in-the-middle-angreb kan være i gang.',
    'awaiting_peer' => 'Venter på, at den anden enhed bekræfter...',
    'confirm_match' => 'Bekræft — de er ens',

    'success_heading' => 'Enheden er parret',
    'success_body' => 'Denne enhed er nu betroet. Dine data synkroniseres, så snart du forbinder.',
    'done' => 'Færdig',

    'errors' => [
        'relay_unreachable' => 'Den anden enhed kan ikke nås. Sørg for, at begge er på samme netværk, og at synkronisering er slået til på computeren.',
        'invalid_code' => 'Koden er ugyldig eller udløbet. Bed den anden enhed om at generere en ny.',
        'identity_locked' => 'Din enheds identitet er låst. Lås appen op, og prøv igen.',
        'identity_needs_lock' => 'Opsæt app-låsen først — den beskytter enhedens identitet.',
    ],
];
