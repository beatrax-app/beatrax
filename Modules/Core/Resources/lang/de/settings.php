<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Darstellung',
        'money' => 'Geld',
        'insights' => 'Einblicke & Warnungen',
        'security' => 'Sicherheit & Geräte',
        'data' => 'Importe & Daten',
        'app' => 'App',
    ],

    'title' => 'Einstellungen',
    'subtitle' => 'Einstellungen dafür, wie deine Finanzen in der App erscheinen.',

    'appearance' => [
        'heading' => 'Erscheinungsbild',
        'theme' => 'Design',
        'theme_light' => 'Hell',
        'theme_dark' => 'Dunkel',
        'theme_system' => 'System',
        'theme_help' => 'System folgt der Hell- oder Dunkel-Einstellung deines Betriebssystems.',
    ],

    'language' => [
        'apply' => 'Anwenden',
        'heading' => 'Sprache',
        'label' => 'Anzeigesprache',

        'system' => 'System',
        'help' => 'Ändert die Wörter auf dem Bildschirm und die Schreibweise von Beträgen. System folgt der Sprache deines Browsers oder Betriebssystems, standardmäßig Englisch.',
    ],

    'country' => [
        'heading' => 'Land',
        'label' => 'Dein Land',
        'help' => 'Legt fest, an welchem Land sich die Steuerregeln, Behörden und Bankgebühren orientieren, die die App erkennt. Sprache und Schreibweise von Beträgen ändern sich dadurch nicht.',
        'choose' => 'Land wählen…',
        'switch_note' => 'Ein Wechsel fügt neue Kategorien hinzu — bestehende Markierungen werden nie geändert.',

        'wording_note' => 'Die Namen der Steuerkategorien stammen aus der Steuererklärung, die in :country verwendet wird, und bleiben daher in jeder App-Sprache in den Worten dieses Landes.',

        'countries' => [
            'at' => 'Österreich',
            'be' => 'Belgien',
            'bg' => 'Bulgarien',
            'ca' => 'Kanada',
            'ch' => 'Schweiz',
            'cy' => 'Zypern',
            'cz' => 'Tschechien',
            'de' => 'Deutschland',
            'dk' => 'Dänemark',
            'ee' => 'Estland',
            'es' => 'Spanien',
            'fi' => 'Finnland',
            'fr' => 'Frankreich',
            'gb' => 'Vereinigtes Königreich',
            'gr' => 'Griechenland',
            'hr' => 'Kroatien',
            'hu' => 'Ungarn',
            'ie' => 'Irland',
            'is' => 'Island',
            'it' => 'Italien',
            'lt' => 'Litauen',
            'lu' => 'Luxemburg',
            'lv' => 'Lettland',
            'mt' => 'Malta',
            'nl' => 'Niederlande',
            'no' => 'Norwegen',
            'pl' => 'Polen',
            'pt' => 'Portugal',
            'ro' => 'Rumänien',
            'se' => 'Schweden',
            'si' => 'Slowenien',
            'sk' => 'Slowakei',
            'us' => 'Vereinigte Staaten',
        ],
    ],

    'currency_display' => [
        'heading' => 'Währungsanzeige',
        'label' => 'Standardansicht in der Transaktionsliste',
        'eur_only' => 'Nur :code',
        'original' => 'Originalwährung',
        'help' => 'Du kannst weiterhin pro Seite aus der Transaktionsliste heraus umschalten.',
    ],

    'base_currency' => [
        'heading' => 'Basiswährung für Auswertungen',
        'label' => 'Auswertungswährung',
        'help' => 'Alle Summen und Zusammenfassungen werden in diese Währung umgerechnet. Jedes Konto zeigt daneben weiterhin seine eigene Originalwährung.',
    ],

    'exchange_rates' => [
        'heading' => 'Wechselkurse',
        'fetch_online' => 'Aktuelle Kurse online abrufen',
        'online_on' => 'Kurse werden täglich von der ECB abgerufen. Nur Abfragen von Währungspaaren — keine persönlichen Daten.',
        'last_updated' => 'Zuletzt aktualisiert: :date.',
        'online_off' => 'Es werden die mitgelieferten Kurse verwendet. Keine Daten verlassen dieses Gerät.',
        'fetch_aria' => 'Aktuelle Wechselkurse online abrufen',
        'refreshing' => 'Wird aktualisiert…',
        'next_refresh' => 'Nächste automatische Aktualisierung: täglich um 09:00',
        'refresh_gave_up' => 'Die Kurse konnten nicht aktualisiert werden. Es gelten weiterhin die Kurse auf diesem Gerät.',
        'refresh_now' => 'Jetzt aktualisieren',
    ],

    'period' => [
        'heading' => 'Zeitraum',
        'label' => 'Zeitraum beginnt am Tag',
        'help' => 'Nummeriert von 1 bis 28. Die meisten lassen das auf 1 (Kalendermonat). Nimm 25, wenn dein Gehalt am 25. eingeht und „dein Monat“ für dich dann beginnt.',
    ],

    'recurring' => [
        'heading' => 'Erkennung wiederkehrender Zahlungen',
        'window_label' => 'Erkennungsfenster (Monate)',
        'window_help' => 'Wie viele Monate Verlauf durchsucht werden, wenn Transaktionen zu wiederkehrenden Mustern gruppiert werden.',
        'income_label' => 'Mindesteinkommen (Cent)',
        'income_help' => 'Einnahmen unter diesem Schwellenwert werden nicht automatisch gruppiert. Gespeichert in Cent — 200000 bedeutet €2.000,00. Setze den Wert auf 0, um den Schwellenwert abzuschalten.',
    ],

    'drift' => [
        'heading' => 'Abweichungswarnungen',
        'label' => 'Standardschwelle für Abweichungswarnungen',
        'help' => 'Warnungen werden ausgelöst, wenn der letzte Betrag einer wiederkehrenden Abbuchung um mehr als diesen Prozentsatz vom vorherigen Betrag abweicht. Einstellungen pro Reihe haben Vorrang.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (Standard)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Einstellungen speichern',
    'saved' => 'Gespeichert.',

    'anomaly_heading' => 'Anomalieerkennung',
    'notifications_heading' => 'Benachrichtigungen',

    'forecasting' => [
        'heading' => 'Prognose',
        'intro' => 'Beatrax schreibt deinen Saldo ausgehend vom aktuellen Stand deiner Konten fort. Für Konten ohne Kontoauszugssaldo (PayPal, alte CSV-Importe) legst du hier den Anfangssaldo fest, damit die Prognose von einem bekannten Punkt aus startet.',
        'no_accounts' => 'Noch keine Konten — importiere einen Kontoauszug, um eines hinzuzufügen.',
    ],

    'auto_import' => [
        'heading' => 'Automatischer Import',
        'label' => 'Automatischer Import aus dem Ablageordner',

        'active_html' => 'Der Ablageordner ist aktiv. Beatrax durchsucht <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> alle 5 Minuten nach neuen Dateien.',
        'inactive_html' => 'Wenn aktiviert, durchsucht Beatrax <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> alle 5 Minuten nach <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code>- und <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code>-Dateien und importiert sie über dieselbe Matcher-Pipeline wie der Assistent. Verarbeitete Dateien wandern nach <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, damit sie nie doppelt importiert werden.',
    ],

    'aliases' => [
        'heading' => 'Aliase',
        'intro' => 'Sieh dir die verständlichen Namen an, die du Beatrax für kryptische Beschreibungen im Kontoauszug beigebracht hast, und bearbeite sie.',
        'manage' => 'Aliase verwalten →',
    ],

    'tax_heading' => 'Steuern',
    'shared_merchant_heading' => 'Gemeinsame Händlerliste',
    'data_backup_heading' => 'Daten & Backup',
    'install_heading' => 'Installation',

    'about_updates' => [
        'heading' => 'Über Updates',
        'body' => 'Beatrax aktualisiert sich nach der Installation automatisch. Nach der Installation der allerersten Version kommen künftige Versionen über ein Banner in der App — du musst GitHub nicht erneut aufsuchen. Sollte ein künftiges Update einmal nicht durchlaufen, kannst du dir den neuesten Installer jederzeit manuell von der Releases-Seite herunterladen.',
        'open_releases' => 'Releases-Seite öffnen →',
    ],

    'privacy' => [
        'heading' => 'Datenschutzerklärung',
        'body' => 'Beatrax hält deine Finanzen auf deinen eigenen Geräten. Die Erklärung sagt, was das heißt, was die optionalen Online-Funktionen senden und wie du deine Daten entfernst.',
        'open' => 'Datenschutzerklärung lesen →',
        'url_hint' => 'Falls der Link nicht öffnet, rufe auf:',
    ],

    'first_run_tour' => [
        'heading' => 'Einführungstour',
        'body' => 'Starte den Einrichtungsassistenten erneut, wenn du den Einstieg noch einmal durchlaufen möchtest.',
        'run_again' => 'Einrichtungsassistent erneut starten',
    ],

    'developer' => [
        'heading' => 'Entwickler',
        'label' => 'Dev-Konsole in der App',
        'help' => 'Zeigt die Dev-Konsole unter /dev. Setzt den Schalter „Erweitert“ bei jeder Anmeldung zurück.',
        'aria' => 'Entwicklermodus',
    ],

    'errors' => [
        'currency_required' => 'Wähle eine Währung.',
        'window_months' => 'Wähle zwischen 2 und 60 Monaten.',
        'threshold' => 'Wähle einen Schwellenwert von 1%, 2%, 5%, 10%, 25% oder 50%.',
        'amount' => 'Gib einen Betrag ab €0 ein.',
        'period_day' => 'Wähle einen Tag von 1 bis 28.',
        'currency_view' => 'Wähle eine der verfügbaren Optionen.',
    ],
];
