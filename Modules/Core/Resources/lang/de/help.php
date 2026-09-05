<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Über :subject',
        'close' => 'Schließen',
    ],

    'page_title' => 'Wo liegen meine Daten?',
    'intro' => 'Beatrax speichert alles auf diesem Gerät. Es gibt keinen Beatrax-Server und kein Cloud-Konto. Nach draußen geht nur das, was du selbst verbindest — ein Postfach, eine Bank über Enable Banking, die Geräte, die du zur Synchronisierung koppelst — dazu eine tägliche Abfrage der Wechselkurse. Jede Verbindung sagt es auf dem Bildschirm, auf dem du sie einschaltest.',

    'lives_here' => 'Deine Daten liegen hier',
    'copy' => 'Kopieren',
    'copied' => 'Kopiert',

    'location' => [
        'database' => 'Datenbank:',
        'artefacts_imports' => 'Importierte Kontoauszüge:',
        'artefacts_mail' => 'Eingelesene E-Mails:',
        'artefacts_drop' => 'Überwachter Ablageordner:',
        'backups' => 'Backups:',
        'secrets' => 'Zugangsdaten der Verbindungen:',
        'logs' => 'Protokolle:',
    ],

    'copy_aria' => [
        'database' => 'Pfad der Datenbank in die Zwischenablage kopieren',
        'artefacts_imports' => 'Pfad der importierten Kontoauszüge in die Zwischenablage kopieren',
        'artefacts_mail' => 'Pfad der eingelesenen E-Mails in die Zwischenablage kopieren',
        'artefacts_drop' => 'Pfad des überwachten Ablageordners in die Zwischenablage kopieren',
        'backups' => 'Pfad der Backups in die Zwischenablage kopieren',
        'secrets' => 'Pfad der Zugangsdaten der Verbindungen in die Zwischenablage kopieren',
        'logs' => 'Pfad der Protokolle in die Zwischenablage kopieren',
    ],

    'artefacts_heading' => 'Deine Quelldokumente stecken nicht im Backup',
    'artefacts_body' => 'Ein Backup enthält die Datenbank und sonst nichts. Die Kontoauszüge, die du importiert hast, die E-Mails, die der Scanner geholt hat, und die Belege, die du in den überwachten Ordner gelegt hast, bleiben dort, wo sie sind — in den drei oben genannten Ordnern. Ein Backup an einen sicheren Ort zu legen kopiert sie nicht mit; ein vollständiges Archiv heißt also, diese Ordner ebenfalls mitzunehmen — oder unten Alles exportieren zu benutzen, das sie zusammen mit dem Backup einpackt.',

    'export_heading' => 'Alles exportieren',
    'export_body' => 'Ein Archiv mit einer verschlüsselten Kopie deiner Datenbank und jedem Quelldokument, das du Beatrax gegeben hast. Entpack es, wo du willst, und deine Dokumente liegen darin wie eh und je, in den Ordnern, aus denen sie kamen.',
    'export_passphrase_label' => 'Passphrase für die Datenbank',
    'export_confirm_label' => 'Passphrase wiederholen',
    'export_passphrase_hint' => 'Die Datenbank im Archiv wird mit dieser Passphrase verschlüsselt und lässt sich ohne sie nicht öffnen — nimm also etwas, das du auch später noch hast. Deine Quelldokumente kommen unverändert hinein, bewahre das Archiv also an einem Ort auf, dem du vertraust.',
    'export_cta' => 'Alles als ZIP exportieren',
    'export_working' => 'Archiv wird erstellt…',

    'delete_heading' => 'Deine Daten löschen',
    'delete_intro' => 'Deine Daten sind Dateien auf diesem Gerät, sie zu löschen heißt also, diese Dateien zu löschen. Es gibt hier keinen Knopf, der das für dich tut, und das mit Absicht: Deine Historie steckt im Dateisystem, und ein Knopf, der ein paar Tabellen leert und die Dateien liegen lässt, wäre schlimmer als gar keiner.',
    'delete_uninstall' => 'Beatrax zu deinstallieren löscht deine Daten nicht. Das ist bewusst so — eine versehentliche Deinstallation darf nicht Jahre an Historie vernichten — deshalb bleibt alles Folgende auf diesem Gerät, bis du es selbst entfernst.',
    'delete_list_intro' => 'Um jede Spur zu entfernen, lösche jedes davon:',
    'delete_journal_note' => 'Neben der Datenbank liegen zwei Journaldateien, :wal und :shm. Deine jüngsten Änderungen stecken darin, bis sie in die Datenbank übernommen werden — lösche also alle drei zusammen.',
    'no_telemetry' => 'Es gibt keine Telemetrie, die du abschalten müsstest, und kein externes Konto, das du kündigen müsstest.',
];
