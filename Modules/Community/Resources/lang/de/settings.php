<?php

declare(strict_types=1);

return [
    'about_body' => 'Eine mitgelieferte YAML-Datei, die kryptische Kontoauszugscodes verständlichen Händlernamen zuordnet. Wenn du sie aktivierst, liest Beatrax die Liste beim Import; beim Abschicken eines Vorschlags öffnet sich GitHub in deinem Browser.',

    'mappings' => ':count Zuordnung|:count Zuordnungen',
    // i18n-review: de · contributors — Mitwirkende is an adjectival noun, so the
    // singular has to pick a gender; this takes the strong masculine Mitwirkender
    // after a bare numeral. Whether a stats caption wants that form is the call.
    'contributors' => ':count Mitwirkender|:count Mitwirkende',

    'use_shared_list' => [
        'title' => 'Gemeinsame Händlerliste verwenden',
        'help' => 'Lass Beatrax die mitgelieferte Liste lesen, um verständliche Namen für Händler einzusetzen, die du nicht selbst umbenannt hast.',
    ],

    'offer_to_contribute' => [
        'title' => 'Beitrag anbieten',
        'help' => 'Zeigt in der Triage-Zeile die Aktion „Hilf anderen, das zu identifizieren“, damit du mit einem Klick einen Vorschlag für die gemeinsame Liste abschicken kannst.',
        // i18n-review: de · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Zeigt in der Triage-Zeile die Aktion „Hilf anderen, das zu identifizieren“, damit du mit einem Tippen einen Vorschlag für die gemeinsame Liste abschicken kannst.',
    ],

    'update_on_updates' => [
        'title' => 'Gemeinsame Liste bei App-Updates aktualisieren',
        'help' => 'Die mitgelieferte Liste jedes Mal auffrischen, wenn Beatrax sich selbst aktualisiert.',
        'help_phone' => 'Die mitgelieferte Liste jedes Mal auffrischen, wenn eine neue Version von Beatrax aus dem App Store oder von Google Play installiert wird.',
        'note' => 'Wird mit einem künftigen App-Update aktiv — die Version, die du nutzt, steht oben in der Seitenleiste.',
    ],
];
