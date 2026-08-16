<?php

declare(strict_types=1);

return [
    'page_title' => 'Transaktion',
    'heading' => 'Transaktion',

    'counterparty' => 'Modpart',
    'amount_native' => 'Beløb (oprindeligt)',
    'amount_settled' => 'Beløb (afregnet i EUR)',
    'effective_rate' => 'Effektiv kurs',
    'ics_markup' => 'Inklusive et eventuelt ICS-tillæg.',

    'split' => [
        'category' => 'Kategori',
        'open' => 'Opdel på kategorier',
        'heading' => 'Opdel på flere kategorier',
        'total' => 'I alt :amount',
        'tax_per_category' => 'Skattemærker angives pr. kategori nedenfor.',
        'choose_category' => 'Vælg en kategori',
        'note_label' => 'Note',
        'note_placeholder' => 'Note (valgfrit)',
        'tax_deductible' => 'Fradragsberettiget',
        'remove_leg_aria' => 'Fjern denne kategori',
        'add_category' => '+ Tilføj kategori',
        'soft_cap' => ':count af ~20 kategorier — overvej at gruppere små beløb.',
        'remaining_zero' => 'Rest :amount ✓',
        'remaining_to_assign' => 'Tilbage at fordele: :amount',
        'over_allocated' => 'Overfordelt med :amount — reducér en delpost.',
        'save' => 'Gem opdelingen',
        'saving' => 'Gemmer…',
        'unsplit' => 'Ophæv opdelingen',
        'remove_to_one' => 'Fjerner du denne, er der én kategori tilbage — transaktionen bliver :category.',
        'remove_to_one_fallback' => 'denne kategori',
        'remove_category' => 'Fjern kategori',
        'keep_category' => 'Behold denne kategori',
        'restore_single' => 'Gendan som én kategori?',
        'confirm_unsplit' => 'Ja, ophæv opdelingen',
        'keep_split' => 'Behold opdelingen',
    ],

    'tax' => [
        'section_aria' => 'Skattemærke',
        'label' => 'Fradragsberettiget',
    ],

    'reclassify' => [
        'heading' => 'Omklassificér',
        'help' => 'Tilsidesæt den type, der er fundet. Hvis denne transaktion er parret med en anden, ophæves parringen i begge sider, hvis du vælger en type, der ikke er en overførsel.',
        'choose_aria' => 'Vælg ny transaktionstype',
        'choose_option' => 'Vælg en type…',
        'save' => 'Gem',
    ],

    'note' => [
        'heading' => 'Note',
        'help' => 'Personlig note til denne transaktion. Kun synlig for dig.',
        'label' => 'Note',
        'placeholder' => 'Tilføj en note…',
        'save' => 'Gem noten',
        'saved' => 'Gemt',
    ],

    'reassign' => [
        'heading' => 'Tildel ny modpart',
        'help' => 'Tilsidesæt den modpart, der er fundet for denne transaktion.',
        'choose_aria' => 'Vælg modpart',
        'choose_option' => 'Vælg en modpart…',
        'submit' => 'Tildel',
    ],

    'delete' => [
        'heading' => 'Slet transaktionen',
        'help' => 'Fjerner denne transaktion permanent. Handlingen kan ikke fortrydes.',
        'button' => 'Slet',
        'confirm_prompt' => 'Er du sikker?',
        'confirm' => 'Ja, slet',
        'cancel' => 'Annullér',
    ],

    'chain' => [
        'view' => 'Vis kæden',
    ],

    'toast' => [
        'reconciled_locked' => 'Denne transaktion er afstemt. Ophæv afstemningen for at foretage ændringer.',
        'reclassified_pair_removed' => 'Omklassificeret til :type — parringen er fjernet',
        'reclassified' => 'Omklassificeret til :type',
        'note_saved' => 'Noten er gemt',
        'unreconciled' => 'Afstemningen er ophævet — du kan redigere transaktionen igen.',
        'counterparty_updated' => 'Modparten er opdateret',
        'split_saved' => 'Opdelingen er gemt',
        'removed_one_remains' => 'Fjernet — én kategori er tilbage',
        'unsplit_restored' => 'Opdelingen er ophævet — gendannet til én kategori',
    ],

    'errors' => [
        'totals_must_match' => 'Kunne ikke gemme — delposternes sum skal passe præcist med transaktionens samlede beløb.',
        'not_found' => 'Transaktionen blev ikke fundet.',
        'amount_zero' => 'Beløbet kan ikke være €0,00',
        'choose_category' => 'Vælg en kategori.',
        'choose_before_removing' => 'Vælg en kategori, før du fjerner.',
        'choose_before_unsplitting' => 'Vælg en kategori, før du ophæver opdelingen.',
        'not_found_or_unowned' => 'Transaktionen blev ikke fundet eller ejes ikke af brugeren.',
        'reconciled_split' => 'Denne transaktion er afstemt. Ophæv afstemningen for at ændre opdelingen.',
        'not_splittable' => "Transaktionstypen ':type' kan ikke opdeles.",
        'min_two_legs' => 'En opdeling kræver mindst 2 delposter.',
        'legs_non_zero' => 'Delposternes beløb må ikke være nul.',
        'legs_parent_sign' => 'Delposternes beløb skal have samme fortegn som hovedtransaktionen.',
        'leg_category_not_accessible' => 'Delpostens kategori blev ikke fundet eller er ikke tilgængelig for brugeren.',
        'survivor_not_accessible' => 'Den tilbageværende kategori blev ikke fundet eller er ikke tilgængelig for brugeren.',
        'survivor_must_be_current' => 'Den tilbageværende kategori skal være en af opdelingens nuværende delpostkategorier.',
    ],
];
