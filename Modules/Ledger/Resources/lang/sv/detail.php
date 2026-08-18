<?php

declare(strict_types=1);

return [
    'page_title' => 'Transaktion',
    'heading' => 'Transaktion',

    'counterparty' => 'Motpart',
    'amount_native' => 'Belopp (ursprungligt)',
    'amount_settled' => 'Belopp (avräknat i EUR)',
    'effective_rate' => 'Effektiv kurs',
    'ics_markup' => 'Inklusive eventuellt ICS-påslag.',

    'split' => [
        'category' => 'Kategori',
        'open' => 'Dela upp på kategorier',
        'heading' => 'Dela upp på flera kategorier',
        'total' => 'Totalt :amount',
        'tax_per_category' => 'Skattetaggar anges per kategori nedan.',
        'choose_category' => 'Välj en kategori',
        'note_label' => 'Anteckning',
        'note_placeholder' => 'Anteckning (valfritt)',
        'tax_deductible' => 'Avdragsgill',
        'remove_leg_aria' => 'Ta bort den här kategorin',
        'add_category' => '+ Lägg till kategori',
        'soft_cap' => ':count av ~20 kategorier — överväg att gruppera små belopp.',
        'remaining_zero' => 'Återstår :amount ✓',
        'remaining_to_assign' => 'Kvar att fördela: :amount',
        'over_allocated' => 'Överfördelat med :amount — minska en delpost.',
        'save' => 'Spara uppdelningen',
        'saving' => 'Sparar…',
        'unsplit' => 'Ta bort uppdelningen',
        'remove_to_one' => 'Tar du bort den här återstår en kategori — transaktionen blir :category.',
        'remove_to_one_fallback' => 'den här kategorin',
        'remove_category' => 'Ta bort kategori',
        'keep_category' => 'Behåll den här kategorin',
        'restore_single' => 'Återställa till en enda kategori?',
        'confirm_unsplit' => 'Ja, ta bort uppdelningen',
        'keep_split' => 'Behåll uppdelningen',
    ],

    'tax' => [
        'section_aria' => 'Skattetagg',
        'label' => 'Avdragsgill',
    ],

    'reclassify' => [
        'heading' => 'Omklassificera',
        'help' => 'Skriv över den typ som identifierats. Om den här transaktionen är parad med en annan bryts paret på båda sidor om du väljer en typ som inte är en överföring.',
        'choose_aria' => 'Välj ny transaktionstyp',
        'choose_option' => 'Välj en typ…',
        'save' => 'Spara',
    ],

    'note' => [
        'heading' => 'Anteckning',
        'help' => 'Personlig anteckning för den här transaktionen. Syns bara för dig.',
        'label' => 'Anteckning',
        'placeholder' => 'Lägg till en anteckning…',
        'save' => 'Spara anteckningen',
        'saved' => 'Sparad',
    ],

    'reassign' => [
        'heading' => 'Tilldela ny motpart',
        'help' => 'Skriv över den motpart som tagits fram för den här transaktionen.',
        'choose_aria' => 'Välj motpart',
        'choose_option' => 'Välj en motpart…',
        'submit' => 'Tilldela',
    ],

    'goal' => [
        'heading' => 'Sparmål',
        'help' => 'Räkna den här transaktionen mot ett av dina sparmål.',
        'choose_aria' => 'Välj ett sparmål',
        'choose_option' => 'Välj ett mål…',
        'submit' => 'Lägg till i målet',
        'remove_aria' => 'Ta bort :name',
    ],

    'delete' => [
        'heading' => 'Ta bort transaktionen',
        'help' => 'Tar bort den här transaktionen permanent. Åtgärden kan inte ångras.',
        'button' => 'Ta bort',
        'confirm_prompt' => 'Är du säker?',
        'confirm' => 'Ja, ta bort',
        'cancel' => 'Avbryt',
    ],

    'chain' => [
        'view' => 'Visa kedjan',
    ],

    'toast' => [
        'reconciled_locked' => 'Den här transaktionen är avstämd. Häv avstämningen för att göra ändringar.',
        'reclassified_pair_removed' => 'Omklassificerad till :type — paret borttaget',
        'reclassified' => 'Omklassificerad till :type',
        'note_saved' => 'Anteckningen sparad',
        'unreconciled' => 'Avstämningen hävd — du kan redigera transaktionen igen.',
        'counterparty_updated' => 'Motparten uppdaterad',
        'goal_attributed' => 'Räknas mot det här målet',
        'goal_attribution_removed' => 'Räknas inte längre mot det här målet',
        'split_saved' => 'Uppdelningen sparad',
        'removed_one_remains' => 'Borttagen — en kategori återstår',
        'unsplit_restored' => 'Uppdelningen borttagen — återställd till en enda kategori',
    ],

    'errors' => [
        'totals_must_match' => 'Det gick inte att spara — delposternas summa måste stämma exakt med transaktionens totalbelopp.',
        'not_found' => 'Transaktionen hittades inte.',
        'amount_zero' => 'Beloppet kan inte vara €0,00',
        'choose_category' => 'Välj en kategori.',
        'choose_before_removing' => 'Välj en kategori innan du tar bort.',
        'choose_before_unsplitting' => 'Välj en kategori innan du tar bort uppdelningen.',
        'not_found_or_unowned' => 'Transaktionen hittades inte eller ägs inte av användaren.',
        'reconciled_split' => 'Den här transaktionen är avstämd. Häv avstämningen för att ändra uppdelningen.',
        'not_splittable' => "Transaktionstypen ':type' går inte att dela upp.",
        'min_two_legs' => 'En uppdelning kräver minst 2 delposter.',
        'legs_non_zero' => 'Delposternas belopp får inte vara noll.',
        'legs_parent_sign' => 'Delposternas belopp måste ha samma tecken som huvudtransaktionen.',
        'leg_category_not_accessible' => 'Delpostens kategori hittades inte eller är inte tillgänglig för användaren.',
        'survivor_not_accessible' => 'Den kvarvarande kategorin hittades inte eller är inte tillgänglig för användaren.',
        'survivor_must_be_current' => 'Den kvarvarande kategorin måste vara en av uppdelningens nuvarande delpostkategorier.',
    ],
];
