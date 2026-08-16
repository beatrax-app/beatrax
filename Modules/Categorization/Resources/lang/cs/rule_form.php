<?php

declare(strict_types=1);

return [
    'heading_edit' => 'Upravit pravidlo',
    'heading_new' => 'Nové pravidlo',

    'combinator_aria' => 'Spojka podmínek',
    'match_all' => 'Splnit všechny podmínky',
    'match_any' => 'Splnit libovolnou podmínku',

    'condition_label' => 'Podmínka :number',
    'condition_field_aria' => 'Pole podmínky :number',
    'condition_operator_aria' => 'Operátor podmínky :number',
    'condition_value_aria' => 'Hodnota podmínky :number',
    'condition_value_from_aria' => 'Hodnota podmínky :number (od)',
    'condition_value_to_aria' => 'Hodnota podmínky :number (do)',
    'to' => 'do',
    'amount_placeholder' => '0,00',
    'text_placeholder' => 'např. SPOTIFY',
    'remove_condition' => 'Odebrat podmínku',
    'add_condition' => '+ Přidat podmínku',

    'then' => 'Pak',
    'action_label' => 'Akce :number',
    'action_type_aria' => 'Typ akce :number',
    'action_category' => 'Kategorie',
    'action_counterparty' => 'Protistrana',
    'action_note' => 'Poznámka',
    'action_tax_tag' => 'Daňový štítek',
    'assign_category_aria' => 'Přiřadit kategorii pro akci :number',
    'reassign_counterparty_aria' => 'Přeřadit na protistranu pro akci :number',
    'note_text_aria' => 'Text poznámky pro akci :number',
    'note_placeholder' => 'Text poznámky…',
    'note_mode_aria' => 'Režim poznámky pro akci :number',
    'note_set' => 'Nastavit',
    'note_append' => 'Připojit',
    'deduction_category_aria' => 'Kategorie odpočtu pro akci :number',
    'remove_action' => 'Odebrat akci',
    'add_action' => '+ Přidat akci',

    'this_year_only' => 'Jen tento rok ▾',
    'override_tax_year' => 'Přepsat daňový rok',
    'tax_year_override_aria' => 'Přepsání daňového roku pro akci :number',
    'tax_tag_note' => 'Akce s daňovým štítkem se projeví až při příštím opětovném použití pravidel, ne u aktuálního importu.',

    'priority' => 'Priorita',
    'priority_help' => 'Nižší čísla se spouštějí dřív. Pravidla bez společných polí si nikdy neodporují.',

    'cancel' => 'Zrušit',
    'save_changes' => 'Uložit změny',
    'save_rule' => 'Uložit pravidlo',
    'saving' => 'Ukládání…',

    'error_rule_unavailable' => 'Toto pravidlo už není dostupné.',
    'error_invalid_data' => 'Neplatná data pravidla — vyber hodnoty ze seznamů a zkus to znovu.',
    'error_duplicate' => 'Pravidlo s tímto polem, porovnáním a hodnotou už existuje. Uprav raději stávající pravidlo.',
    'error_priority_whole' => 'Priorita musí být celé číslo.',
    'error_add_condition' => 'Přidej alespoň jednu podmínku.',
    'error_add_action' => 'Přidej alespoň jednu akci.',
    'condition_value_required' => 'Zadej hodnotu pro podmínku :position.',
    'condition_bounds_required' => 'Zvol dolní a horní mez pro podmínku :position.',
    'condition_amount_invalid' => 'Zadej platnou částku pro podmínku :position.',
    'action_pick_category' => 'Zvol kategorii pro tuto akci.',
    'action_pick_counterparty' => 'Zvol protistranu, na kterou se má přeřadit.',
    'action_note_required' => 'Zadej text poznámky.',
    'action_pick_deduction' => 'Zvol kategorii odpočtu pro daňový štítek.',
];
