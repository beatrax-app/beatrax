<?php

declare(strict_types=1);

return [
    'page_title' => 'Transaksjon',
    'heading' => 'Transaksjon',

    'counterparty' => 'Motpart',
    'description' => 'Beskrivelse',
    'amount_native' => 'Beløp (opprinnelig)',
    'amount_settled' => 'Beløp (oppgjort i EUR)',
    'effective_rate' => 'Effektiv kurs',
    'ics_markup' => 'Inkludert et eventuelt ICS-påslag.',

    'split' => [
        'category' => 'Kategori',
        'open' => 'Del opp i kategorier',
        'heading' => 'Del opp på flere kategorier',
        'total' => 'Totalt :amount',
        'tax_per_category' => 'Skattemerker angis per kategori nedenfor.',
        'choose_category' => 'Velg en kategori',
        'note_label' => 'Notat',
        'note_placeholder' => 'Notat (valgfritt)',
        'tax_deductible' => 'Fradragsberettiget',
        'remove_leg_aria' => 'Fjern denne kategorien',
        'add_category' => '+ Legg til kategori',
        'soft_cap' => ':count av ~20 kategorier — vurder å gruppere små beløp.',
        'remaining_zero' => 'Gjenstår :amount ✓',
        'remaining_to_assign' => 'Igjen å fordele: :amount',
        'over_allocated' => 'Overfordelt med :amount — reduser en delpost.',
        'save' => 'Lagre oppdelingen',
        'saving' => 'Lagrer…',
        'unsplit' => 'Opphev oppdelingen',
        'remove_to_one' => 'Fjerner du denne, står én kategori igjen — transaksjonen blir :category.',
        'remove_to_one_fallback' => 'denne kategorien',
        'remove_category' => 'Fjern kategori',
        'keep_category' => 'Behold denne kategorien',
        'restore_single' => 'Gjenopprette som én kategori?',
        'survivor_legend' => 'Kategori som beholdes',
        'confirm_unsplit' => 'Ja, opphev oppdelingen',
        'keep_split' => 'Behold oppdelingen',
    ],

    'tax' => [
        'section_aria' => 'Skattemerke',
        'label' => 'Fradragsberettiget',
    ],

    'reclassify' => [
        'heading' => 'Omklassifiser',
        'help' => 'Overstyr typen som ble funnet. Hvis denne transaksjonen er paret med en annen, oppheves paringen på begge sider når du velger en type som ikke er en overføring.',
        'choose_aria' => 'Velg ny transaksjonstype',
        'choose_option' => 'Velg en type…',
        'save' => 'Lagre',
    ],

    'type_label' => [
        'expense' => 'Utgift',
        'income' => 'Inntekt',
        'transfer_out' => 'Overføring ut',
        'transfer_in' => 'Overføring inn',
        'fee' => 'Gebyr',
        'refund' => 'Refusjon',
        'adjustment' => 'Justering',
    ],

    'note' => [
        'heading' => 'Notat',
        'help' => 'Personlig notat for denne transaksjonen. Bare synlig for deg.',
        'label' => 'Notat',
        'placeholder' => 'Legg til et notat…',
        'save' => 'Lagre notatet',
        'saved' => 'Lagret',
    ],

    'reassign' => [
        'heading' => 'Tildel ny motpart',
        'help' => 'Overstyr motparten som ble funnet for denne transaksjonen.',
        'choose_aria' => 'Velg motpart',
        'choose_option' => 'Velg en motpart…',
        'submit' => 'Tildel',
    ],

    'goal' => [
        'heading' => 'Sparemål',
        'help' => 'Tell denne transaksjonen med i et av sparemålene dine.',
        'choose_aria' => 'Velg et sparemål',
        'choose_option' => 'Velg et mål…',
        'submit' => 'Legg til i målet',
        'remove_aria' => 'Fjern :name',
    ],

    'delete' => [
        'heading' => 'Slett transaksjonen',
        'help' => 'Fjerner denne transaksjonen permanent. Handlingen kan ikke angres.',
        'button' => 'Slett',
        'confirm_prompt' => 'Er du sikker?',
        'confirm' => 'Ja, slett',
        'cancel' => 'Avbryt',
    ],

    'chain' => [
        'view' => 'Vis kjeden',
    ],

    'toast' => [
        'reconciled_locked' => 'Denne transaksjonen er avstemt. Opphev avstemmingen for å gjøre endringer.',
        'reclassified_pair_removed' => 'Omklassifisert til :type — paringen er fjernet',
        'reclassified' => 'Omklassifisert til :type',
        'note_saved' => 'Notatet er lagret',
        'unreconciled' => 'Avstemmingen er opphevet — du kan redigere transaksjonen igjen.',
        'counterparty_updated' => 'Motparten er oppdatert',
        'goal_attributed' => 'Telles med i dette målet',
        'goal_attribution_removed' => 'Telles ikke lenger med i dette målet',
        'split_saved' => 'Oppdelingen er lagret',
        'removed_one_remains' => 'Fjernet — én kategori står igjen',
        'unsplit_restored' => 'Oppdelingen er opphevet — gjenopprettet til én kategori',
    ],

    'errors' => [
        'totals_must_match' => 'Kunne ikke lagre — summen av delpostene må stemme nøyaktig med transaksjonens totalbeløp.',
        'not_found' => 'Transaksjonen ble ikke funnet.',
        'amount_zero' => 'Beløpet kan ikke være :amount',
        'choose_category' => 'Velg en kategori.',
        'choose_before_removing' => 'Velg en kategori før du fjerner.',
        'choose_before_unsplitting' => 'Velg en kategori før du opphever oppdelingen.',
        'not_found_or_unowned' => 'Transaksjonen ble ikke funnet eller eies ikke av brukeren.',
        'reconciled_split' => 'Denne transaksjonen er avstemt. Opphev avstemmingen for å endre oppdelingen.',
        'not_splittable' => "Transaksjonstypen ':type' kan ikke deles opp.",
        'min_two_legs' => 'En oppdeling krever minst 2 delposter.',
        'legs_non_zero' => 'Delpostenes beløp kan ikke være null.',
        'legs_parent_sign' => 'Delpostenes beløp må ha samme fortegn som hovedtransaksjonen.',
        'leg_category_not_accessible' => 'Delpostens kategori ble ikke funnet eller er ikke tilgjengelig for brukeren.',
        'survivor_not_accessible' => 'Den gjenværende kategorien ble ikke funnet eller er ikke tilgjengelig for brukeren.',
        'survivor_must_be_current' => 'Den gjenværende kategorien må være en av oppdelingens nåværende delpostkategorier.',
    ],
];
