<?php

declare(strict_types=1);

return [
    'about_heading' => 'Über die gemeinsame Liste',
    'about_body' => 'Eine mitgelieferte YAML-Datei, die kryptische Kontoauszugscodes verständlichen Händlernamen zuordnet. Wenn du sie aktivierst, liest Beatrax die Liste beim Import; beim Abschicken eines Vorschlags öffnet sich GitHub in deinem Browser.',

    'mappings' => 'Zuordnungen',
    'contributors' => 'Mitwirkende',

    'use_shared_list' => [
        'title' => 'Gemeinsame Händlerliste verwenden',
        'help' => 'Lass Beatrax die mitgelieferte Liste lesen, um verständliche Namen für Händler einzusetzen, die du nicht selbst umbenannt hast.',
    ],

    'offer_to_contribute' => [
        'title' => 'Beitrag anbieten',
        'help' => 'Zeigt in der Triage-Zeile die Aktion „Hilf anderen, das zu identifizieren“, damit du mit einem Klick einen Vorschlag für die gemeinsame Liste abschicken kannst.',
    ],

    'update_on_updates' => [
        'title' => 'Gemeinsame Liste bei App-Updates aktualisieren',
        'help' => 'Die mitgelieferte Liste jedes Mal auffrischen, wenn Beatrax sich selbst aktualisiert.',
        'note' => 'Wird mit einem künftigen App-Update aktiv — die aktuelle Version findest du unter Einstellungen → Über.',
    ],
];
