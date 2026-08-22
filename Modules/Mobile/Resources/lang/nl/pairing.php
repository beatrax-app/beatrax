<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Gekoppeld apparaat',
    'page_title' => 'Apparaat koppelen',

    'scan_heading' => 'Koppel dit apparaat',
    'scan_subtitle' => 'Richt de camera op de code die op het andere apparaat wordt getoond.',
    'camera_permission_pending' => 'Cameratoegang staat uit. Sta die toe voor Beatrax in je apparaatinstellingen en probeer het opnieuw.',
    'open_camera' => 'Camera openen',
    'opening_camera' => 'Wachten op cameratoegang…',
    'close_camera' => 'Camera sluiten',
    'viewfinder_aria' => 'Camerabeeld — richt het op de code op je andere apparaat',
    'viewfinder_idle' => 'De camera staat uit. Open hem om de code op je andere apparaat te scannen.',
    'scan_prompt' => 'Scan de code op je andere apparaat',
    'enter_code_instead' => 'Code invoeren in plaats daarvan',

    'enter_heading' => 'Voer de code in',
    'camera_off' => 'Cameratoegang staat uit. Voer in plaats daarvan de code van het andere apparaat in.',
    'word_code_aria' => 'Voer de woordcode van het andere apparaat in',
    'submit_code' => 'Code versturen',
    'cancel' => 'Annuleren',
    'skip_import' => 'Doorgaan zonder importeren',

    'confirm_heading' => 'Vergelijk deze woorden met het andere apparaat',
    'safety_words_aria' => 'Veiligheidswoorden: :words',
    'confirm_body' => 'Beide apparaten moeten exact dezelfde woorden tonen. Als ze verschillen, tik dan op Annuleren — er kan een man-in-the-middle-aanval gaande zijn.',
    'awaiting_peer' => 'Wachten tot het andere apparaat bevestigt...',
    'confirm_match' => 'Bevestigen — ze komen overeen',

    'success_heading' => 'Apparaat gekoppeld',
    'success_body' => 'Dit apparaat wordt nu vertrouwd. Je gegevens worden gesynchroniseerd zodra je verbinding maakt.',
    'done' => 'Klaar',

    'errors' => [
        'relay_unreachable' => 'Kan het andere apparaat niet bereiken. Zorg dat beide op hetzelfde netwerk zitten en synchronisatie op de desktop aanstaat.',
        'invalid_code' => 'Deze code is ongeldig of verlopen. Vraag het andere apparaat om een nieuwe te genereren.',
        'code_not_accepted' => 'Geen enkel apparaat in dit netwerk accepteerde die code. Controleer de code en of het andere apparaat hem nog toont.',
        'no_peer_answered' => 'Niets in dit netwerk reageerde op die code. Controleer of synchronisatie op het andere apparaat draait, of scan zijn code met de camera — de camera hoeft niet in het netwerk te zoeken.',
        'no_peer_answered_ios' => 'Niets in dit netwerk reageerde op die code. Zoeken naar het andere apparaat in het netwerk werkt nog niet op de iPhone, dus scan zijn code met de camera.',
        'rate_limited' => 'Te veel pogingen. Wacht een minuut en probeer het opnieuw.',
        'identity_locked' => 'De identiteit van je apparaat is vergrendeld. Ontgrendel de app en probeer het opnieuw.',
        'identity_needs_lock' => 'Stel eerst de app-vergrendeling in — die beschermt de identiteit van je apparaat.',
        'safety_number_changed' => 'Het andere apparaat is veranderd terwijl je vergeleek. Controleer de woorden hieronder opnieuw voordat je bevestigt.',
    ],
];
