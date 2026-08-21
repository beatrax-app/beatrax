<?php

declare(strict_types=1);

return [
    'heading_named' => 'Keten voor :name',
    'heading' => 'Keten',

    'unresolved_heading' => 'Keten nog niet opgelost',
    'unresolved_body' => 'De keten-resolver is nog bezig. Open de beoordelingswachtrij of vernieuw over een moment.',

    'none_heading' => 'Geen financieringsketen gevonden',
    'none_body' => 'Voor deze transactie is geen financieringsketen gevonden. Als je er een verwachtte, dien dan een kandidaat in vanuit de beoordelingswachtrij.',

    'none_beyond_leg' => 'Geen financieringsketen gevonden voorbij deze schakel.',

    'covers_charges' => 'Dekt :count ICS-afschrijving|Dekt :count ICS-afschrijvingen',
    'no_ics_charges' => 'Geen ICS-afschrijvingen in deze afwikkeling',
    'show_more_fanout' => ':count meer tonen · :shown van :total',

    'confirm' => 'Bevestigen',
    'reject' => 'Afwijzen',
    'confirm_aria' => 'Ketenkoppeling :id bevestigen',
    'reject_aria' => 'Ketenkoppeling :id afwijzen',

    'confidence_aria' => [
        'deterministic' => 'Betrouwbaarheid: deterministische match',
        'confirmed' => 'Betrouwbaarheid: bevestigd',
        'candidate' => 'Betrouwbaarheid: kandidaat; vereist beoordeling',
    ],
];
