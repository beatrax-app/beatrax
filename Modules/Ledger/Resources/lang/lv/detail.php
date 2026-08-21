<?php

declare(strict_types=1);

return [
    'page_title' => 'Darījums',
    'heading' => 'Darījums',

    'counterparty' => 'Darījuma partneris',
    'amount_native' => 'Summa (sākotnējā valūtā)',
    'amount_settled' => 'Summa (norēķinu EUR)',
    'effective_rate' => 'Faktiskais kurss',
    'ics_markup' => 'Ietver iespējamo ICS uzcenojumu.',

    'split' => [
        'category' => 'Kategorija',
        'open' => 'Sadalīt pa kategorijām',
        'heading' => 'Sadalījums pa kategorijām',
        'total' => 'Kopā :amount',
        'tax_per_category' => 'Nodokļu atzīmes zemāk tiek iestatītas katrai kategorijai atsevišķi.',
        'choose_category' => 'Izvēlieties kategoriju',
        'note_label' => 'Piezīme',
        'note_placeholder' => 'Piezīme (neobligāti)',
        'tax_deductible' => 'Attaisnotie izdevumi',
        'remove_leg_aria' => 'Noņemt šo kategoriju',
        'add_category' => '+ Pievienot kategoriju',
        'soft_cap' => ':count no ~20 kategorijām — apsveriet mazo summu apvienošanu.',
        'remaining_zero' => 'Atlikums :amount ✓',
        'remaining_to_assign' => 'Vēl jāpiešķir: :amount',
        'over_allocated' => 'Piešķirts par :amount vairāk — samaziniet kādu daļu.',
        'save' => 'Saglabāt sadalījumu',
        'saving' => 'Saglabā…',
        'unsplit' => 'Atcelt darījuma sadalījumu',
        'remove_to_one' => 'Pēc noņemšanas paliks viena kategorija — darījums kļūs par :category.',
        'remove_to_one_fallback' => 'šo kategoriju',
        'remove_category' => 'Noņemt kategoriju',
        'keep_category' => 'Paturēt šo kategoriju',
        'restore_single' => 'Atjaunot kā vienu kategoriju?',
        'survivor_legend' => 'Kategorija, ko paturēt',
        'confirm_unsplit' => 'Jā, atcelt sadalījumu',
        'keep_split' => 'Paturēt sadalījumu',
    ],

    'tax' => [
        'section_aria' => 'Nodokļu atzīme',
        'label' => 'Attaisnotie izdevumi',
    ],

    'reclassify' => [
        'heading' => 'Pārklasificēt',
        'help' => 'Aizstājiet automātiski noteikto veidu. Ja šis darījums ir sapārots ar citu, izvēloties veidu, kas nav pārskaitījums, pārojums abām pusēm tiks noņemts.',
        'choose_aria' => 'Izvēlieties jaunu darījuma veidu',
        'choose_option' => 'Izvēlieties veidu…',
        'save' => 'Saglabāt',
    ],

    'type_label' => [
        'expense' => 'Izdevumi',
        'income' => 'Ieņēmumi',
        'transfer_out' => 'Izejošs pārskaitījums',
        'transfer_in' => 'Ienākošs pārskaitījums',
        'fee' => 'Komisijas maksa',
        'refund' => 'Atmaksa',
        'adjustment' => 'Korekcija',
    ],

    'note' => [
        'heading' => 'Piezīme',
        'help' => 'Personiska piezīme par šo darījumu. Redzama tikai jums.',
        'label' => 'Piezīme',
        'placeholder' => 'Pievienojiet piezīmi…',
        'save' => 'Saglabāt piezīmi',
        'saved' => 'Saglabāts',
    ],

    'reassign' => [
        'heading' => 'Mainīt darījuma partneri',
        'help' => 'Aizstājiet šim darījumam noteikto darījuma partneri.',
        'choose_aria' => 'Izvēlieties darījuma partneri',
        'choose_option' => 'Izvēlieties darījuma partneri…',
        'submit' => 'Mainīt',
    ],

    'goal' => [
        'heading' => 'Uzkrājumu mērķis',
        'help' => 'Ieskaitiet šo darījumu vienā no saviem uzkrājumu mērķiem.',
        'choose_aria' => 'Izvēlieties uzkrājumu mērķi',
        'choose_option' => 'Izvēlieties mērķi…',
        'submit' => 'Pievienot mērķim',
        'remove_aria' => 'Noņemt :name',
    ],

    'delete' => [
        'heading' => 'Dzēst darījumu',
        'help' => 'Neatgriezeniski dzēš šo darījumu. Šo darbību nevar atsaukt.',
        'button' => 'Dzēst',
        'confirm_prompt' => 'Vai tiešām?',
        'confirm' => 'Jā, dzēst',
        'cancel' => 'Atcelt',
    ],

    'chain' => [
        'view' => 'Skatīt ķēdi',
    ],

    'toast' => [
        'reconciled_locked' => 'Šis darījums ir saskaņots. Atceliet saskaņojumu, lai veiktu izmaiņas.',
        'reclassified_pair_removed' => 'Pārklasificēts uz :type — pārojums noņemts',
        'reclassified' => 'Pārklasificēts uz :type',
        'note_saved' => 'Piezīme saglabāta',
        'unreconciled' => 'Saskaņojums atcelts — varat atkal rediģēt šo darījumu.',
        'counterparty_updated' => 'Darījuma partneris atjaunināts',
        'goal_attributed' => 'Ieskaitīts šajā mērķī',
        'goal_attribution_removed' => 'Vairs netiek ieskaitīts šajā mērķī',
        'split_saved' => 'Sadalījums saglabāts',
        'removed_one_remains' => 'Noņemts — palikusi viena kategorija',
        'unsplit_restored' => 'Sadalījums atcelts — atjaunota viena kategorija',
    ],

    'errors' => [
        'totals_must_match' => 'Neizdevās saglabāt — daļu kopsummai precīzi jāsakrīt ar darījuma kopsummu.',
        'not_found' => 'Darījums nav atrasts.',
        'amount_zero' => 'Summa nevar būt :amount',
        'choose_category' => 'Izvēlieties kategoriju.',
        'choose_before_removing' => 'Pirms noņemšanas izvēlieties kategoriju.',
        'choose_before_unsplitting' => 'Pirms sadalījuma atcelšanas izvēlieties kategoriju.',
        'not_found_or_unowned' => 'Darījums nav atrasts vai nepieder lietotājam.',
        'reconciled_split' => 'Šis darījums ir saskaņots. Atceliet saskaņojumu, lai mainītu tā sadalījumu.',
        'not_splittable' => "Darījuma veidu ':type' nevar sadalīt.",
        'min_two_legs' => 'Sadalījumam nepieciešamas vismaz 2 daļas.',
        'legs_non_zero' => 'Daļu summas nedrīkst būt nulle.',
        'legs_parent_sign' => 'Daļu summām jābūt ar tādu pašu zīmi kā pamatdarījumam.',
        'leg_category_not_accessible' => 'Daļas kategorija nav atrasta vai lietotājam nav tai piekļuves.',
        'survivor_not_accessible' => 'Paliekošā kategorija nav atrasta vai lietotājam nav tai piekļuves.',
        'survivor_must_be_current' => 'Paliekošajai kategorijai jābūt vienai no pašreizējām sadalījuma daļu kategorijām.',
    ],
];
