<?php

declare(strict_types=1);

return [
    'page_title' => 'Transazione',
    'heading' => 'Transazione',

    'counterparty' => 'Controparte',
    'amount_native' => 'Importo (valuta originale)',
    'amount_settled' => 'Importo (regolato in EUR)',
    'effective_rate' => 'Tasso effettivo',
    'ics_markup' => "Include l'eventuale maggiorazione ICS.",

    'split' => [
        'category' => 'Categoria',
        'open' => 'Suddividi tra categorie',
        'heading' => 'Suddivisione tra categorie',
        'total' => 'Totale :amount',
        'tax_per_category' => 'Le etichette fiscali si impostano per categoria qui sotto.',
        'choose_category' => 'Scegli una categoria',
        'note_label' => 'Nota',
        'note_placeholder' => 'Nota (facoltativa)',
        'tax_deductible' => 'Detraibile',
        'remove_leg_aria' => 'Rimuovi questa categoria',
        'add_category' => '+ Aggiungi categoria',
        'soft_cap' => ':count di ~20 categorie — valuta di raggruppare gli importi piccoli.',
        'remaining_zero' => 'Rimanente :amount ✓',
        'remaining_to_assign' => 'Ancora da assegnare: :amount',
        'over_allocated' => 'Assegnato in eccesso di :amount — riduci una quota.',
        'save' => 'Salva la suddivisione',
        'saving' => 'Salvataggio…',
        'unsplit' => 'Annulla la suddivisione',
        'remove_to_one' => 'Rimuovendo questa resta una sola categoria — la transazione diventa :category.',
        'remove_to_one_fallback' => 'questa categoria',
        'remove_category' => 'Rimuovi la categoria',
        'keep_category' => 'Mantieni questa categoria',
        'restore_single' => 'Ripristinare come categoria singola?',
        'survivor_legend' => 'Categoria da mantenere',
        'confirm_unsplit' => 'Sì, annulla la suddivisione',
        'keep_split' => 'Mantieni la suddivisione',
    ],

    'tax' => [
        'section_aria' => 'Etichetta fiscale',
        'label' => 'Detraibile',
    ],

    'reclassify' => [
        'heading' => 'Riclassifica',
        'help' => "Sostituisce il tipo rilevato. Se questa transazione è abbinata a un'altra, scegliere un tipo diverso da trasferimento annulla l'abbinamento su entrambi i lati.",
        'choose_aria' => 'Scegli il nuovo tipo di transazione',
        'choose_option' => 'Scegli un tipo…',
        'save' => 'Salva',
    ],

    'type_label' => [
        'expense' => 'Spesa',
        'income' => 'Entrata',
        'transfer_out' => 'Trasferimento in uscita',
        'transfer_in' => 'Trasferimento in entrata',
        'fee' => 'Commissione',
        'refund' => 'Rimborso',
        'adjustment' => 'Rettifica',
    ],

    'note' => [
        'heading' => 'Nota',
        'help' => 'Nota personale per questa transazione. Visibile solo a te.',
        'label' => 'Nota',
        'placeholder' => 'Aggiungi una nota…',
        'save' => 'Salva la nota',
        'saved' => 'Salvata',
    ],

    'reassign' => [
        'heading' => 'Riassegna la controparte',
        'help' => 'Sostituisce la controparte rilevata per questa transazione.',
        'choose_aria' => 'Scegli la controparte',
        'choose_option' => 'Scegli una controparte…',
        'submit' => 'Riassegna',
    ],

    'goal' => [
        'heading' => 'Obiettivo di risparmio',
        'help' => 'Conteggia questa transazione in uno dei tuoi obiettivi di risparmio.',
        'choose_aria' => 'Scegli un obiettivo di risparmio',
        'choose_option' => 'Scegli un obiettivo…',
        'submit' => "Aggiungi all'obiettivo",
        'remove_aria' => 'Rimuovi :name',
    ],

    'delete' => [
        'heading' => 'Elimina la transazione',
        'help' => "Rimuove definitivamente questa transazione. L'operazione non può essere annullata.",
        'button' => 'Elimina',
        'confirm_prompt' => 'Sei sicuro?',
        'confirm' => 'Sì, elimina',
        'cancel' => 'Annulla',
    ],

    'chain' => [
        'view' => 'Vedi la catena',
    ],

    'toast' => [
        'reconciled_locked' => 'Questa transazione è riconciliata. Annulla la riconciliazione per modificarla.',
        'reclassified_pair_removed' => 'Riclassificata come :type — abbinamento rimosso',
        'reclassified' => 'Riclassificata come :type',
        'note_saved' => 'Nota salvata',
        'unreconciled' => 'Riconciliazione annullata — puoi modificare di nuovo questa transazione.',
        'counterparty_updated' => 'Controparte aggiornata',
        'goal_attributed' => 'Conteggiato in questo obiettivo',
        'goal_attribution_removed' => 'Non è più conteggiato in questo obiettivo',
        'split_saved' => 'Suddivisione salvata',
        'removed_one_remains' => 'Rimossa — resta una categoria',
        'unsplit_restored' => 'Suddivisione annullata — ripristinata a una sola categoria',
    ],

    'errors' => [
        'totals_must_match' => 'Salvataggio non riuscito — il totale delle quote deve corrispondere esattamente al totale della transazione.',
        'not_found' => 'Transazione non trovata.',
        'amount_zero' => "L'importo non può essere :amount",
        'choose_category' => 'Scegli una categoria.',
        'choose_before_removing' => 'Scegli una categoria prima di rimuovere.',
        'choose_before_unsplitting' => 'Scegli una categoria prima di annullare la suddivisione.',
        'not_found_or_unowned' => "Transazione non trovata o non appartenente all'utente.",
        'reconciled_split' => 'Questa transazione è riconciliata. Annulla la riconciliazione per modificarne la suddivisione.',
        'not_splittable' => 'Il tipo di transazione «:type» non è suddivisibile.',
        'min_two_legs' => 'Una suddivisione richiede almeno 2 quote.',
        'legs_non_zero' => 'Gli importi delle quote non possono essere zero.',
        'legs_parent_sign' => 'Gli importi delle quote devono avere lo stesso segno della transazione principale.',
        'leg_category_not_accessible' => "Categoria della quota non trovata o non accessibile all'utente.",
        'survivor_not_accessible' => "Categoria rimanente non trovata o non accessibile all'utente.",
        'survivor_must_be_current' => 'La categoria rimanente deve essere una delle categorie attuali della suddivisione.',
    ],
];
