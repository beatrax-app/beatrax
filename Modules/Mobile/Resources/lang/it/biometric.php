<?php

declare(strict_types=1);

return [
    'heading' => 'Sblocco biometrico',
    'unavailable' => "Lo sblocco biometrico è disponibile solo nell'app mobile Beatrax.",
    'toggle_label' => 'Sblocca con Face ID / Touch ID',
    'toggle_help' => "Sblocca l'app dopo un riavvio con la biometria invece del PIN. Il PIN resta necessario periodicamente e ogni volta che i tuoi dati biometrici cambiano.",
    'toggle_aria' => 'Sblocca con la biometria',
    'confirm_pin_heading' => 'Conferma il PIN per attivare',
    'current_pin' => 'PIN attuale',
    'enable' => 'Attiva lo sblocco biometrico',

    'errors' => [
        'unavailable' => 'Lo sblocco biometrico non è disponibile su questo dispositivo.',
        'pin_required' => 'Inserisci il PIN (4–10 cifre) per attivare lo sblocco biometrico.',
        'enroll_failed' => 'Impossibile attivare lo sblocco biometrico — controlla il PIN e riprova.',
    ],
];
