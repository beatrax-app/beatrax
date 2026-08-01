<?php

declare(strict_types=1);

return [
    'heading_edit' => 'Regel bewerken',
    'heading_new' => 'Nieuwe regel',

    'combinator_aria' => 'Voorwaarde-combinatie',
    'match_all' => 'Aan alle voorwaarden voldoen',
    'match_any' => 'Aan een van de voorwaarden voldoen',

    'condition_label' => 'Voorwaarde :number',
    'condition_field_aria' => 'Veld van voorwaarde :number',
    'condition_operator_aria' => 'Operator van voorwaarde :number',
    'condition_value_aria' => 'Waarde van voorwaarde :number',
    'condition_value_from_aria' => 'Waarde van voorwaarde :number (van)',
    'condition_value_to_aria' => 'Waarde van voorwaarde :number (tot)',
    'to' => 'tot',
    'amount_placeholder' => '0,00',
    'text_placeholder' => 'bijv. SPOTIFY',
    'remove_condition' => 'Voorwaarde verwijderen',
    'add_condition' => '+ Voorwaarde toevoegen',

    'then' => 'Dan',
    'action_label' => 'Actie :number',
    'action_type_aria' => 'Type van actie :number',
    'action_category' => 'Categorie',
    'action_counterparty' => 'Winkelier',
    'action_note' => 'Notitie',
    'action_tax_tag' => 'Belastinglabel',
    'assign_category_aria' => 'Categorie toewijzen voor actie :number',
    'reassign_counterparty_aria' => 'Opnieuw toewijzen aan winkelier voor actie :number',
    'note_text_aria' => 'Notitietekst voor actie :number',
    'note_placeholder' => 'Notitietekst…',
    'note_mode_aria' => 'Notitiemodus voor actie :number',
    'note_set' => 'Instellen',
    'note_append' => 'Toevoegen',
    'deduction_category_aria' => 'Aftrekcategorie voor actie :number',
    'remove_action' => 'Actie verwijderen',
    'add_action' => '+ Actie toevoegen',

    'this_year_only' => 'Alleen dit jaar ▾',
    'override_tax_year' => 'Belastingjaar overschrijven',
    'tax_year_override_aria' => 'Belastingjaar overschrijven voor actie :number',
    'tax_tag_note' => 'Belastinglabelacties gelden bij de volgende hertoepassing, niet bij de huidige import.',

    'priority' => 'Prioriteit',
    'priority_help' => 'Lagere nummers gaan eerst. Regels zonder gedeelde velden botsen nooit.',

    'cancel' => 'Annuleren',
    'save_changes' => 'Wijzigingen opslaan',
    'save_rule' => 'Regel opslaan',
    'saving' => 'Opslaan…',

    'error_rule_unavailable' => 'Die regel is niet langer beschikbaar.',
    'error_invalid_data' => 'Ongeldige regelgegevens — kies uit de keuzelijsten en probeer het opnieuw.',
    'error_duplicate' => 'Er bestaat al een regel met dit veld, deze vergelijking en deze waarde. Bewerk de bestaande regel in plaats daarvan.',
    'error_priority_whole' => 'Prioriteit moet een geheel getal zijn.',
    'error_add_condition' => 'Voeg minstens één voorwaarde toe.',
    'error_add_action' => 'Voeg minstens één actie toe.',
    'condition_value_required' => 'Voer een waarde in voor voorwaarde :position.',
    'condition_bounds_required' => 'Kies een onder- en bovengrens voor voorwaarde :position.',
    'condition_amount_invalid' => 'Voer een geldig bedrag in voor voorwaarde :position.',
    'action_pick_category' => 'Kies een categorie voor deze actie.',
    'action_pick_counterparty' => 'Kies een winkelier om aan toe te wijzen.',
    'action_note_required' => 'Voer notitietekst in.',
    'action_pick_deduction' => 'Kies een aftrekcategorie voor het belastinglabel.',
];
