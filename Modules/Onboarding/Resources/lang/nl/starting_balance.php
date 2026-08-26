<?php

declare(strict_types=1);

return [
    'eyebrow' => '🧮 BEGINSALDO',
    'confirmed_aria' => 'bevestigd',
    'on_date' => 'op :date',

    'detected_h3' => 'We zagen dat je :label begon met',
    'confirm' => 'Bevestigen',
    'edit' => 'Bewerken',

    'conflict_h3' => 'We zagen twee waarden voor deze rekening — welke klopt?',
    'conflict_legend' => 'Kies een beginsaldo',
    'conflict_from' => 'Van :source:',
    'conflict_helper' => 'We nemen standaard de vroegste datum. Kies de juiste of bewerk handmatig.',
    'edit_manually' => 'Handmatig bewerken',

    'editing_h3' => 'Bewerk het beginsaldo van je :label',
    'input_label' => 'BEGINSALDO',
    'minor_units' => '(kleinste eenheden)',
    'on_date_label' => 'OP DATUM',
    'cancel' => 'Annuleren',
    'save' => 'Opslaan',

    'change' => 'Wijzigen',

    'manual_h3' => 'Voer het beginsaldo van je :label handmatig in',
    'manual_lede' => 'We konden het beginsaldo voor deze rekening niet automatisch detecteren. Voer het handmatig in of sla over.',

    'unknown_state' => 'Onbekende kaartstatus. Herlaad de wizard.',

    'errors' => [
        'account_not_set' => 'Rekening niet ingesteld. Herlaad de wizard.',
        'invalid_amount' => 'Voer een geldig bedrag in.',
        'amount_range' => 'Voer een bedrag in tussen :min en :max.',
        'pick_date' => 'Kies een datum.',
        'pick_valid_date' => 'Kies een geldige datum.',
        'future_date' => 'De datum van het beginsaldo kan niet in de toekomst liggen.',
        'date_warning' => 'Dit is later dan je eerste geïmporteerde transactie (:date). Je dashboard kan transacties van vóór deze datum tonen.',
    ],
];
