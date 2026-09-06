<?php

declare(strict_types=1);

return [
    'heading' => 'Zuordnung vorschlagen',
    'intro' => 'Öffnet GitHub in deinem Browser mit ausgefülltem Vorschlag. Mit dabei sind nur Muster, Name, Kategorie und Region von oben — und das Muster ist der Text, so wie ihn dein Kontoauszug geschrieben hat. Dein Name und deine E-Mail-Adresse verlassen dieses Gerät nie.',

    'pattern' => 'Muster',
    'name' => 'Verständlicher Name',
    'name_placeholder' => 'z. B. Albert Heijn',
    'category' => 'Kategorie (optional)',
    'category_placeholder' => 'z. B. Lebensmittel',
    'region' => 'Region',

    'regions' => [
        'other' => 'Andere',
    ],

    'yaml_preview' => 'YAML-Vorschau',

    'cancel' => 'Abbrechen',
    'submit' => 'Auf GitHub öffnen',

    'toast' => 'Vorschlag in deinem Browser geöffnet.',

    'errors' => [
        'pattern_required' => 'Muster ist erforderlich.',
        'name_required' => 'Name ist erforderlich.',
        'browser_refused' => 'Dein Browser ließ sich nicht öffnen, also wurde nichts gesendet und nichts hat dieses Gerät verlassen. Versuche es erneut, oder füge die YAML-Vorschau oben selbst in einen Pull Request ein.',
    ],
];
