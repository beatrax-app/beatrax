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
    'camera_off_no_search' => 'Der Kamerazugriff ist aus, und die Netzwerksuche nach dem anderen Gerät funktioniert auf dem iPhone noch nicht — ein getippter Code findet es also nicht von allein. Schalte den Kamerazugriff für Beatrax in den Geräteeinstellungen wieder ein und scanne den Code auf dem anderen Gerät, oder sende den Code hier, und dieser Bildschirm fragt dich, wo das andere Gerät steht.',
    'no_search' => 'Die Netzwerksuche nach dem anderen Gerät funktioniert auf dem iPhone noch nicht, ein getippter Code findet es also nicht von allein. Scanne den Code stattdessen mit der Kamera — dafür ist keine Netzwerksuche nötig. Wenn du nicht scannen kannst, sende den Code, und dieser Bildschirm fragt dich, wo das andere Gerät steht.',
    'word_code_aria' => 'Gib den Wortcode vom anderen Gerät ein',
    'initiator_address' => 'Wo steht das andere Gerät?',
    'initiator_address_help' => 'Seine Adresse in diesem Netzwerk, als Host und Port. Der Desktop zeigt sie unter Geräte und Synchronisierung. Sende den Code erneut, sobald du sie eingetragen hast.',
    'submit_code' => 'Code senden',
    'cancel' => 'Abbrechen',
    'skip_import' => 'Ohne Import fortfahren',

    'confirm_heading' => 'Vergleiche diese Wörter mit dem anderen Gerät',
    'safety_words_aria' => 'Sicherheitswörter: :words',
    'confirm_body' => 'Beide Geräte müssen genau dieselben Wörter zeigen. Wenn sie sich unterscheiden, tippe auf Abbrechen — es könnte ein Man-in-the-Middle-Angriff laufen.',
    'awaiting_peer' => 'Warte auf die Bestätigung des anderen Geräts...',
    'confirm_match' => 'Bestätigen — sie stimmen überein',

    'success_heading' => 'Gerät gekoppelt',
    'success_body' => 'Diesem Gerät wird jetzt vertraut. Deine Daten werden synchronisiert, sobald du dich verbindest.',
    'encryption_incomplete' => 'Das Gerät ist gekoppelt, aber die Verschlüsselung der darauf gespeicherten Daten wurde nicht abgeschlossen. Die Daten sind noch nicht im Ruhezustand verschlüsselt.',
    'done' => 'Fertig',

    'errors' => [
        'relay_unreachable' => 'Das andere Gerät ist nicht erreichbar. Stelle sicher, dass beide im selben Netzwerk sind und die Synchronisierung auf dem Desktop aktiviert ist.',
        'no_road_home' => 'Dieses Gerät kann das Netzwerk nicht durchsuchen, und der gescannte Code enthält keine Adresse für das andere Gerät. Lass dort einen neuen Code anzeigen und scanne diesen.',
        'invalid_code' => 'Dieser Code ist ungültig oder abgelaufen. Lass das andere Gerät einen neuen erzeugen.',
        'already_under_way' => 'Dieses Gerät hat den Code bereits übernommen und wartet auf die Bestätigung des anderen Geräts. Bleibt sie aus, lass einen neuen Code erzeugen und verwende den.',
        'vouched_but_refused' => 'Das andere Gerät hat den Code noch, aber dieses Gerät konnte ihn nicht übernehmen. Lass dort einen neuen Code erzeugen und verwende den.',
        'code_incomplete' => 'Das ist kein vollständiger Code. Vergleiche ihn mit dem anderen Gerät und gib ihn ganz ein.',
        'initiator_address_invalid' => 'Das ist keine Adresse, die dieses Gerät anwählen kann. Gib sie als Host und Port ein, zum Beispiel 192.168.1.20:8100.',
        'code_not_accepted' => 'Kein Gerät in diesem Netzwerk hat den Code akzeptiert. Prüfe den Code und ob das andere Gerät ihn noch anzeigt.',
        'no_peer_answered' => 'Nichts in diesem Netzwerk hat auf den Code geantwortet. Prüfe, ob die Synchronisierung auf dem anderen Gerät läuft, oder scanne dessen Code mit der Kamera — die Kamera sucht nicht im Netzwerk.',
        'no_peer_answered_ios' => 'Nichts in diesem Netzwerk hat auf den Code geantwortet. Die Suche nach dem anderen Gerät im Netzwerk funktioniert auf dem iPhone noch nicht — scanne dessen Code deshalb mit der Kamera.',
        'no_peer_answered_camera_off' => 'Nichts in diesem Netzwerk hat auf den Code geantwortet. Die Suche nach dem anderen Gerät im Netzwerk funktioniert auf dem iPhone noch nicht, und der Kamerazugriff ist aus — schalte den Kamerazugriff deshalb für Beatrax in deinen Geräteeinstellungen wieder ein und scanne den Code auf dem anderen Gerät.',
        'rate_limited' => 'Zu viele Versuche. Warte eine Minute und versuche es erneut.',
        'identity_locked' => 'Die Identität deines Geräts ist gesperrt. Entsperre die App und versuche es erneut.',
        'identity_needs_lock' => 'Richte zuerst die App-Sperre ein — sie schützt die Identität deines Geräts.',
        'safety_number_changed' => 'Das andere Gerät hat sich während des Vergleichs geändert. Prüfe die Wörter unten erneut, bevor du bestätigst.',
    ],
];
