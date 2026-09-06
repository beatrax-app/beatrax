<?php

declare(strict_types=1);

return [
    'what_heading' => 'Waarover wil je meldingen',
    'background_note' => 'Beatrax bereidt deze voor terwijl de app open is. Een geplande achtergrondrun kan dat niet — de app-vergrendeling heeft de enige sleutel — dus wat klaarstaat wordt opgepakt terwijl je de app verder gebruikt.',
    'background_note_phone' => 'Beatrax bereidt deze voor terwijl de app open is. Op de achtergrond kan dat niet — de app-vergrendeling heeft de enige sleutel — dus wat klaarstaat komt binnen zodra je de app weer opent.',
    'system_grant_refused' => 'Je apparaat laat Beatrax geen meldingen tonen, dus niets hieronder bereikt je. Zet ze voor Beatrax aan in de instellingen van je apparaat.',

    'reminders' => [
        'label' => 'Betalingsherinneringen',
        'help' => 'Krijg een seintje voordat een terugkerende betaling verschuldigd is.',
    ],

    'lead_days' => [
        'label' => 'Herinner me ___ dagen van tevoren',
        'help' => 'Hoeveel dagen vóór de vervaldatum de herinnering verschijnt. 1–30 dagen.',
    ],

    'budget_nudges' => [
        'label' => 'Budgethints',
        'help' => 'Krijg een melding wanneer een categoriebudget bijna op is.',
    ],

    'digest' => [
        'label' => 'Je positie',
        'help' => 'Hoe vaak je een samenvatting krijgt van hoe je ervoor staat deze periode.',
        'daily' => 'Dagelijks',
        'weekly' => 'Wekelijks',
        'off' => 'Uit',
    ],

    'savings' => [
        'label' => 'Bespaartips',
        'help' => 'Krijg een melding wanneer Beatrax een goedkoper abonnement of een besparingsmogelijkheid ontdekt.',
    ],

    'when_heading' => 'Wanneer en hoe',

    'quiet_hours' => [
        'label' => 'Stille uren',
        'help' => 'Geen geluid of banner tijdens dit venster — meldingen komen nog steeds in je inbox terecht.',
        'from' => 'Van',
        'to' => 'Tot',
    ],

    'hide_details' => [
        'label' => 'Details in meldingen verbergen',
        'help' => 'Verberg bedragen en winkeliernamen in de melding zelf. Zet aan als je scherm zichtbaar kan zijn voor anderen.',
    ],

    'save' => 'Meldingsinstellingen opslaan',
    'saved' => 'Opgeslagen.',

    'other_devices' => [
        'summary' => 'Andere apparaten',
        'empty' => 'Nog geen andere apparaten gekoppeld.',
        'unnamed' => 'Naamloos apparaat',
        'summary_line' => 'herinneringen :reminders · hints :nudges · overzicht :digest · besparingen :savings',
        'on' => 'aan',
        'off' => 'uit',
    ],

    'errors' => [
        'save_failed' => 'Je meldingsinstellingen konden niet worden opgeslagen. Probeer het opnieuw.',
    ],
];
