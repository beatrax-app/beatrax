<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Gekoppeltes Gerät',
    'page_title' => 'Gerät koppeln',

    'scan_heading' => 'Dieses Gerät koppeln',
    'scan_subtitle' => 'Richte die Kamera auf den Code, der auf dem anderen Gerät angezeigt wird.',
    'camera_permission_pending' => 'Der Kamerazugriff ist aus. Erlaube ihn für Beatrax in deinen Geräteeinstellungen und versuche es erneut.',
    'open_camera' => 'Kamera öffnen',
    'opening_camera' => 'Warte auf Kamerazugriff…',
    'close_camera' => 'Kamera schließen',
    'viewfinder_aria' => 'Kamerasucher — richte ihn auf den Code auf deinem anderen Gerät',
    'viewfinder_idle' => 'Die Kamera ist aus. Öffne sie, um den Code auf deinem anderen Gerät zu scannen.',
    'scan_prompt' => 'Scanne den Code auf deinem anderen Gerät',
    'enter_code_instead' => 'Stattdessen Code eingeben',

    'enter_heading' => 'Gib den Code ein',
    'camera_off' => 'Der Kamerazugriff ist aus. Gib stattdessen den Code vom anderen Gerät ein.',
    'word_code_aria' => 'Gib den Wortcode vom anderen Gerät ein',
    'submit_code' => 'Code senden',
    'cancel' => 'Abbrechen',

    'confirm_heading' => 'Vergleiche diese Wörter mit dem anderen Gerät',
    'safety_words_aria' => 'Sicherheitswörter: :words',
    'confirm_body' => 'Beide Geräte müssen genau dieselben Wörter zeigen. Wenn sie sich unterscheiden, tippe auf Abbrechen — es könnte ein Man-in-the-Middle-Angriff laufen.',
    'awaiting_peer' => 'Warte auf die Bestätigung des anderen Geräts...',
    'confirm_match' => 'Bestätigen — sie stimmen überein',

    'success_heading' => 'Gerät gekoppelt',
    'success_body' => 'Diesem Gerät wird jetzt vertraut. Deine Daten werden synchronisiert, sobald du dich verbindest.',
    'done' => 'Fertig',

    'errors' => [
        'relay_unreachable' => 'Das andere Gerät ist nicht erreichbar. Stelle sicher, dass beide im selben Netzwerk sind und die Synchronisierung auf dem Desktop aktiviert ist.',
        'invalid_code' => 'Dieser Code ist ungültig oder abgelaufen. Lass das andere Gerät einen neuen erzeugen.',
        'identity_locked' => 'Die Identität deines Geräts ist gesperrt. Entsperre die App und versuche es erneut.',
    ],
];
