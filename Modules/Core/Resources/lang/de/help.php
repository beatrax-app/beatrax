<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Über :subject',
        'close' => 'Schließen',
    ],

    'page_title' => 'Wo liegen meine Daten?',
    'intro' => 'Beatrax speichert alles auf diesem Gerät. Es gibt keinen Beatrax-Server und kein Cloud-Konto. Nur eine Abfrage geht von selbst hinaus — die Prüfung auf eine neue Version, die du abschalten kannst. Alles andere wartet auf dich: ein Postfach, eine Bank über Enable Banking, eine tägliche Abfrage der Wechselkurse, die Geräte, die du zur Synchronisierung koppelst, ein Relay, das du einrichtest, und jeder Link, den du anklickst. Jedes davon sagt es auf dem Bildschirm, auf dem du es einschaltest.',

    'lives_here' => 'Deine Daten liegen hier',
    'copy' => 'Kopieren',
    'copied' => 'Kopiert',

    'location' => [
        'database' => 'Datenbank:',
        'artifacts_imports' => 'Importierte Kontoauszüge:',
        'artifacts_mail' => 'Eingelesene E-Mails:',
        'artifacts_drop' => 'Überwachter Ablageordner:',
        'backups' => 'Backups:',
        'secrets' => 'Zugangsdaten der Verbindungen:',
        'logs' => 'Protokolle:',
    ],

    'copy_aria' => [
        'database' => 'Pfad der Datenbank in die Zwischenablage kopieren',
        'artifacts_imports' => 'Pfad der importierten Kontoauszüge in die Zwischenablage kopieren',
        'artifacts_mail' => 'Pfad der eingelesenen E-Mails in die Zwischenablage kopieren',
        'artifacts_drop' => 'Pfad des überwachten Ablageordners in die Zwischenablage kopieren',
        'backups' => 'Pfad der Backups in die Zwischenablage kopieren',
        'secrets' => 'Pfad der Zugangsdaten der Verbindungen in die Zwischenablage kopieren',
        'logs' => 'Pfad der Protokolle in die Zwischenablage kopieren',
    ],

    'artifacts_heading' => 'Deine Quelldokumente stecken nicht im Backup',
    'artifacts_body' => 'Ein Backup enthält die Datenbank und sonst nichts. Die Kontoauszüge, die du importiert hast, die E-Mails, die der Scanner geholt hat, und die Belege, die du in den überwachten Ordner gelegt hast, bleiben dort, wo sie sind — in den drei oben genannten Ordnern. Ein Backup an einen sicheren Ort zu legen kopiert sie nicht mit; ein vollständiges Archiv heißt also, diese Ordner ebenfalls mitzunehmen — oder unten Alles exportieren zu benutzen, das sie zusammen mit dem Backup einpackt.',

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

    /** @link ../../../../../.docs/features/budgets/moving-the-budget-month.md#the-mapping-keep-the-distance-from-the-month-the-reader-is-in */
    'period' => 'Der Zeitraum, über den deine Budgets, dein Dashboard und jede „diese Periode“-Zahl gemessen werden. „:label“ legt fest, wo er beginnt — stell ihn auf den Tag nach deinem Zahltag, und eine Periode enthält genau das Geld, das sie decken sollte. Diesen Tag zu verschieben ordnet jeden bereits zugewiesenen Umschlagbetrag neu ein, und wo zwei alte Perioden auf eine neue fallen, werden ihre Beträge addiert; den Tag zurückzustellen teilt sie nicht wieder auf.',

    /** @link ../../../../../.docs/features/position/architecture.md#composition-never-a-raw-select */
    'net_worth' => 'Alles, wovon Beatrax weiß, dass du es hast, minus alles, was du schuldest: der Saldo jedes Kontos, das du importiert oder verbunden hast, wobei Karten- und Kreditsalden gegengerechnet werden. Vollständiger als das, was du eingegeben hast, ist es nicht — ein Konto, das Beatrax nie gesehen hat, steckt nicht in dieser Zahl. Salden in anderer Währung werden zu dem Kurs umgerechnet, der unter der Zahl steht, und was sich nicht bewerten ließ, wird dort benannt statt stillschweigend weggelassen.',
];
