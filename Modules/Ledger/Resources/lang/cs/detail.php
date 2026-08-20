<?php

declare(strict_types=1);

return [
    'page_title' => 'Transakce',
    'heading' => 'Transakce',

    'counterparty' => 'Protistrana',
    'amount_native' => 'Částka (původní měna)',
    'amount_settled' => 'Částka (vypořádáno v EUR)',
    'effective_rate' => 'Efektivní kurz',
    'ics_markup' => 'Zahrnuje případnou přirážku ICS.',

    'split' => [
        'category' => 'Kategorie',
        'open' => 'Rozdělit do kategorií',
        'heading' => 'Rozdělení do kategorií',
        'total' => 'Celkem :amount',
        'tax_per_category' => 'Daňové štítky se nastavují níže u každé kategorie zvlášť.',
        'choose_category' => 'Vyber kategorii',
        'note_label' => 'Poznámka',
        'note_placeholder' => 'Poznámka (volitelné)',
        'tax_deductible' => 'Daňově uznatelné',
        'remove_leg_aria' => 'Odebrat tuto kategorii',
        'add_category' => '+ Přidat kategorii',
        'soft_cap' => ':count z ~20 kategorií — zvaž seskupení drobných částek.',
        'remaining_zero' => 'Zbývá :amount ✓',
        'remaining_to_assign' => 'Zbývá přiřadit: :amount',
        'over_allocated' => 'Přiřazeno o :amount víc — sniž některou položku.',
        'save' => 'Uložit rozdělení',
        'saving' => 'Ukládá se…',
        'unsplit' => 'Zrušit rozdělení transakce',
        'remove_to_one' => 'Po odebrání zůstane jedna kategorie — :category.',
        'remove_to_one_fallback' => 'tato kategorie',
        'remove_category' => 'Odebrat kategorii',
        'keep_category' => 'Ponechat tuto kategorii',
        'restore_single' => 'Obnovit jako jedinou kategorii?',
        'confirm_unsplit' => 'Ano, zrušit rozdělení',
        'keep_split' => 'Ponechat rozdělení',
    ],

    'tax' => [
        'section_aria' => 'Daňový štítek',
        'label' => 'Daňově uznatelné',
    ],

    'reclassify' => [
        'heading' => 'Změnit klasifikaci',
        'help' => 'Přepiš zjištěný typ. Pokud je tato transakce spárovaná s jinou, volba jiného typu než převod obě strany rozpáruje.',
        'choose_aria' => 'Vyber nový typ transakce',
        'choose_option' => 'Vyber typ…',
        'save' => 'Uložit',
    ],

    'type_label' => [
        'expense' => 'Výdaj',
        'income' => 'Příjem',
        'transfer_out' => 'Odchozí převod',
        'transfer_in' => 'Příchozí převod',
        'fee' => 'Poplatek',
        'refund' => 'Vrácení peněz',
        'adjustment' => 'Úprava',
    ],

    'note' => [
        'heading' => 'Poznámka',
        'help' => 'Osobní poznámka k této transakci. Vidíš ji jen ty.',
        'label' => 'Poznámka',
        'placeholder' => 'Přidej poznámku…',
        'save' => 'Uložit poznámku',
        'saved' => 'Uloženo',
    ],

    'reassign' => [
        'heading' => 'Změnit protistranu',
        'help' => 'Přepiš rozpoznanou protistranu u této transakce.',
        'choose_aria' => 'Vyber protistranu',
        'choose_option' => 'Vyber protistranu…',
        'submit' => 'Změnit',
    ],

    'goal' => [
        'heading' => 'Spořicí cíl',
        'help' => 'Započítej tuto transakci do některého ze svých spořicích cílů.',
        'choose_aria' => 'Vyber spořicí cíl',
        'choose_option' => 'Vyber cíl…',
        'submit' => 'Přidat k cíli',
        'remove_aria' => 'Odebrat :name',
    ],

    'delete' => [
        'heading' => 'Smazat transakci',
        'help' => 'Trvale odstraní tuto transakci. Tuhle akci nejde vzít zpět.',
        'button' => 'Smazat',
        'confirm_prompt' => 'Určitě?',
        'confirm' => 'Ano, smazat',
        'cancel' => 'Zrušit',
    ],

    'chain' => [
        'view' => 'Zobrazit řetězec',
    ],

    'toast' => [
        'reconciled_locked' => 'Tato transakce je odsouhlasená. Pro změny nejdřív zruš odsouhlasení.',
        'reclassified_pair_removed' => 'Nová klasifikace: :type — párování zrušeno',
        'reclassified' => 'Nová klasifikace: :type',
        'note_saved' => 'Poznámka uložena',
        'unreconciled' => 'Odsouhlasení zrušeno — transakci můžeš zase upravovat.',
        'counterparty_updated' => 'Protistrana upravena',
        'goal_attributed' => 'Započítáno do tohoto cíle',
        'goal_attribution_removed' => 'Už se do tohoto cíle nezapočítává',
        'split_saved' => 'Rozdělení uloženo',
        'removed_one_remains' => 'Odebráno — zůstala jedna kategorie',
        'unsplit_restored' => 'Rozdělení zrušeno — obnoveno na jedinou kategorii',
    ],

    'errors' => [
        'totals_must_match' => 'Nepodařilo se uložit — součet položek musí přesně odpovídat celkové částce transakce.',
        'not_found' => 'Transakce nenalezena.',
        'amount_zero' => 'Částka nemůže být 0,00 €',
        'choose_category' => 'Vyber kategorii.',
        'choose_before_removing' => 'Před odebráním vyber kategorii.',
        'choose_before_unsplitting' => 'Před zrušením rozdělení vyber kategorii.',
        'not_found_or_unowned' => 'Transakce nenalezena nebo nepatří uživateli.',
        'reconciled_split' => 'Tato transakce je odsouhlasená. Pro změnu rozdělení nejdřív zruš odsouhlasení.',
        'not_splittable' => 'Typ transakce „:type“ nelze rozdělit.',
        'min_two_legs' => 'Rozdělení vyžaduje alespoň 2 položky.',
        'legs_non_zero' => 'Částky položek nesmí být nulové.',
        'legs_parent_sign' => 'Částky položek musí mít stejné znaménko jako nadřazená transakce.',
        'leg_category_not_accessible' => 'Kategorie položky nenalezena nebo k ní uživatel nemá přístup.',
        'survivor_not_accessible' => 'Zbývající kategorie nenalezena nebo k ní uživatel nemá přístup.',
        'survivor_must_be_current' => 'Zbývající kategorie musí být jednou ze současných kategorií rozdělení.',
    ],
];
