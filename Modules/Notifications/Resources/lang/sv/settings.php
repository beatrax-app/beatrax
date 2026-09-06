<?php

declare(strict_types=1);

return [
    'what_heading' => 'Vad du vill få notiser om',
    'background_note' => 'Beatrax förbereder dem medan appen är öppen. En schemalagd körning i bakgrunden klarar det inte — applåset har den enda nyckeln — så det som väntar hämtas medan du fortsätter använda appen.',
    'background_note_phone' => 'Beatrax förbereder dem medan appen är öppen. I bakgrunden kan den inte — applåset har den enda nyckeln — så det som väntar kommer nästa gång du öppnar appen.',
    'system_grant_refused' => 'Din enhet låter inte Beatrax visa aviseringar, så inget av det nedan når dig. Slå på dem för Beatrax i enhetens inställningar.',

    'reminders' => [
        'label' => 'Betalningspåminnelser',
        'help' => 'Få en påminnelse innan en återkommande betalning förfaller.',
    ],

    'lead_days' => [
        'label' => 'Påminn mig ___ dagar innan',
        'help' => 'Hur många dagar före förfallodagen påminnelsen skickas. 1–30 dagar.',
    ],

    'budget_nudges' => [
        'label' => 'Budgetvarningar',
        'help' => 'Få besked när en kategoribudget nästan är slut.',
    ],

    'digest' => [
        'label' => 'Din ställning',
        'help' => 'Hur ofta du får en sammanfattning av läget den här perioden.',
        'daily' => 'Dagligen',
        'weekly' => 'Veckovis',
        'off' => 'Av',
    ],

    'savings' => [
        'label' => 'Tips om sparmöjligheter',
        'help' => 'Få besked när Beatrax hittar ett billigare abonnemang eller något du kan spara på.',
    ],

    'when_heading' => 'När och hur',

    'quiet_hours' => [
        'label' => 'Tysta timmar',
        'help' => 'Inget ljud och ingen banner under det här intervallet — notiserna hamnar ändå i din inkorg.',
        'from' => 'Från',
        'to' => 'Till',
    ],

    'hide_details' => [
        'label' => 'Dölj detaljer i notiser',
        'help' => 'Dölj belopp och handlarnamn i själva notisbannern. Slå på om din skärm kan synas för andra.',
    ],

    'save' => 'Spara notisinställningar',
    'saved' => 'Sparat.',

    'other_devices' => [
        'summary' => 'Andra enheter',
        'empty' => 'Inga andra enheter är parkopplade än.',
        'unnamed' => 'Namnlös enhet',

        'summary_line' => 'påminnelser :reminders · budgetvarningar :nudges · sammanfattning :digest · sparande :savings',
        'on' => 'på',
        'off' => 'av',
    ],

    'errors' => [
        'save_failed' => 'Det gick inte att spara dina notisinställningar. Försök igen.',
    ],
];
