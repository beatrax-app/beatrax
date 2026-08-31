<?php

declare(strict_types=1);

return [
    'page_title' => 'Prima nota',
    'heading' => 'Prima nota',
    'intro' => 'Registra a mano i contanti e le altre spese fuori banca. Le voci manuali confluiscono nello stesso registro delle tue importazioni — vengono categorizzate, associate a una controparte, rilevate come ricorrenti e conteggiate nel tuo mese.',

    'direction' => 'Direzione',
    'expense' => 'Spesa',
    'income' => 'Entrata',

    'amount' => 'Importo (:symbol)',
    'date' => 'Data',
    'counterparty' => 'Controparte',
    'counterparty_placeholder' => 'es. Panetteria',
    'category' => 'Categoria',
    'optional' => '(facoltativo)',
    'uncategorized' => 'Senza categoria',
    'note' => 'Nota',

    'add_entry' => 'Aggiungi voce',
    'manual_entries' => 'Voci manuali',
    'no_entries' => 'Nessuna voce manuale.',
    'delete_entry' => 'Elimina voce',
    'delete_entry_caption' => 'Elimina',
    'delete' => 'Elimina',
    'delete_confirm' => 'Eliminare questa voce?',
    'delete_keep' => 'Mantieni',

    'errors' => [
        'amount_positive' => 'Inserisci un importo maggiore di zero.',
        'amount_too_large' => 'Questo importo è troppo grande. Controlla le cifre.',
        'amount_unreadable' => 'Non è stato possibile leggere l’importo. Inseriscilo con al massimo :decimals decimale, per esempio :example.|Non è stato possibile leggere l’importo. Inseriscilo con al massimo :decimals decimali, per esempio :example.',
        'amount_unreadable_whole' => 'Non è stato possibile leggere l’importo. Questa valuta non ha decimali, quindi inserisci un numero intero, per esempio :example.',
        'invalid_date' => 'Inserisci una data valida.',
        'not_recorded' => 'La voce non è stata registrata. Prova ad aggiungerla di nuovo.',
    ],

    'toast' => [
        'added' => 'Voce di cassa aggiunta.',
        'removed' => 'Voce di cassa rimossa.',
        'reconciled_locked' => 'Questa transazione è riconciliata. Annulla la riconciliazione per modificarla.',
    ],
];
