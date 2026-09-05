<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count Änderung stammt von einer neueren Version von Beatrax|:count Änderungen stammen von einer neueren Version von Beatrax',
        'body' => 'Was abgelehnt wurde, benennt etwas, das diese Version von Beatrax nicht hat, deshalb konnte dieses Gerät es nirgends ablegen. Es liegt weiterhin auf dem Gerät, das es gemacht hat, und nichts von dir wurde gelöscht.',
        'action' => 'Aktualisiere Beatrax auf diesem Gerät. Änderungen nach dem Update kommen normal an, aber nichts bereits Abgelehntes wird erneut gesendet — mach die Änderung hier noch einmal, wenn du sie auch auf diesem Gerät brauchst.',
    ],
    'untrusted_author' => [
        'summary' => ':count Änderung wurde von einem Gerät signiert, das dieses hier nicht kennt|:count Änderungen wurden von einem Gerät signiert, das dieses hier nicht kennt',
        'body' => 'Was abgelehnt wurde, kam von einem Gerät, das nie mit diesem gekoppelt war, oder von einem, das du entfernt hast. Hier wurde nichts geschrieben, und nichts, was schon hier war, wurde verändert.',
        'action' => 'Wenn du dieses Gerät selbst entfernt hast, ist genau das die Folge des Entfernens und es gibt nichts zu reparieren. Falls nicht, sieh dir die Geräteliste auf dieser Seite an.',
    ],
    'not_verified' => [
        'summary' => ':count Änderung hat die Sicherheitsprüfung auf diesem Gerät nicht bestanden|:count Änderungen haben die Sicherheitsprüfung auf diesem Gerät nicht bestanden',
        'body' => 'Eine Signatur passte nicht zu dem Gerät, das die Änderung gemacht haben wollte, oder die Änderung war an ein anderes Konto gerichtet. Hier wurde nichts geschrieben. Zwischen deinen eigenen Geräten sollte das nicht vorkommen.',
        'action' => 'Sieh dir die Geräteliste auf dieser Seite an und entferne alles, was du nicht kennst. Wenn dort jedes Gerät dir gehört und das weiter passiert, ist es ein Fehler in Beatrax und nichts, was du von hier aus in Ordnung bringen kannst.',
    ],
    'diverged' => [
        'summary' => ':count Änderung von einem anderen Gerät konnte hier nicht gespeichert werden|:count Änderungen von einem anderen Gerät konnten hier nicht gespeichert werden',
        'body' => 'Es kam etwas an, das dieses Gerät nicht speichern konnte: ein Datensatz, dem ein Teil von sich fehlt, ein Datum, das es nicht gibt, eine Aufteilung, die nicht mehr aufgeht, ein Datensatz, dem zwei Geräte bereits dieselbe Identität gegeben hatten, oder eine Löschung für etwas, das hier noch in Verwendung ist. Was abgelehnt wurde, liegt auf deinem anderen Gerät und nicht auf diesem, die beiden halten also nicht mehr dasselbe.',
        'action' => 'Vergleiche den Datensatz auf deinem anderen Gerät mit dem, was du hier siehst, und mach die Änderung hier noch einmal — oder lösche es hier erneut, wenn etwas, das du anderswo entfernt hast, hier noch steht. Abgelehntes wird von allein nicht erneut gesendet.',
    ],
    'last_seen' => 'Zuletzt: :when',
];
