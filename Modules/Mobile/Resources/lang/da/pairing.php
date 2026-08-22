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
    'skip_import' => 'Fortsæt uden at importere',

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
        'code_not_accepted' => 'Ingen enhed på dette netværk accepterede koden. Tjek koden, og at den anden enhed stadig viser den.',
        'no_peer_answered' => 'Intet på dette netværk svarede på koden. Tjek, at synkronisering kører på den anden enhed, eller scan dens kode med kameraet — kameraet søger ikke på netværket.',
        'no_peer_answered_ios' => 'Intet på dette netværk svarede på koden. At søge efter den anden enhed på netværket virker endnu ikke på iPhone, så scan dens kode med kameraet.',
        'rate_limited' => 'For mange forsøg. Vent et minut, og prøv igen.',
        'identity_locked' => 'Din enheds identitet er låst. Lås appen op, og prøv igen.',
        'identity_needs_lock' => 'Opsæt app-låsen først — den beskytter enhedens identitet.',
        'safety_number_changed' => 'Den anden enhed blev ændret, mens du sammenlignede. Tjek ordene nedenfor igen, før du bekræfter.',
    ],
];
