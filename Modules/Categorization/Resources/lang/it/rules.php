<?php

declare(strict_types=1);

return [
    'page_title' => 'Regole',
    'heading' => 'Regole',
    'intro' => "Pre-categorizza le transazioni durante l'importazione. Le regole si applicano a ogni fonte — banca, carta, PayPal e ricevute email.",
    'device_local_note' => 'Le regole restano su questo dispositivo. Non vengono condivise con gli altri tuoi dispositivi.',

    'reapply' => 'Riapplica le regole alla cronologia',
    'reapplying' => 'Riapplicazione…',
    'new_rule' => 'Nuova regola',

    'reapply_progress_lead' => 'Riapplicazione delle regole…',
    'reapply_progress_of' => 'di',
    'reapply_progress_trail' => 'transazioni controllate',

    'empty_heading' => 'Ancora nessuna regola',
    'empty_body' => "Le regole confrontano le transazioni su più condizioni e applicano automaticamente modifiche a categoria, controparte, nota ed etichetta fiscale — durante l'importazione e ogni volta che le riapplichi alla cronologia esistente.",
    'empty_cta' => 'Crea la tua prima regola',

    'col_priority' => 'Priorità',
    'col_conditions' => 'Condizioni',
    'col_actions' => 'Azioni',
    'col_hits' => 'Corrispondenze',
    'col_created' => 'Creata',
    'col_row_actions' => 'Azioni',
    'inactive_badge' => 'Inattiva',
    'inactive_title' => 'Questa regola non viene applicata. Una regola si disattiva quando la categoria o la controparte a cui punta viene eliminata.',

    'more_conditions' => '+:count altre',

    'delete_confirm' => 'Eliminare?',
    'delete_yes' => 'Sì, elimina',
    'cancel' => 'Annulla',
    'edit' => 'Modifica',
    'delete' => 'Elimina',
    'edit_aria' => 'Modifica la regola (priorità :priority)',
    'delete_aria' => 'Elimina la regola (priorità :priority)',

    'footer_note' => 'Le regole e la cronologia degli esercenti lavorano insieme. Eliminare una regola non cancella quello che Beatrax ha imparato dalle categorizzazioni passate — la prossima importazione potrebbe comunque suggerire la stessa categoria dalla cronologia.',

    'chip_category' => 'Categoria: :path',
    'chip_counterparty' => 'Controparte: :path',
    'chip_note' => 'Nota',
    'chip_tax_tag' => 'Etichetta fiscale',

    'flash_deleted' => 'Regola eliminata.',
    'flash_not_found' => "Regola non trovata (potrebbe essere stata eliminata in un'altra scheda).",
    'flash_saved' => 'Regola salvata.',
    'flash_reapplying' => 'Riapplicazione delle regole alla tua cronologia…',
    'summary_no_changes' => 'Nessuna modifica — la tua cronologia corrisponde già alle tue regole.',
    'summary_updated' => 'Aggiornati :fields su :transactions.',
    'summary_fields' => ':count campo|:count campi',
    'summary_transactions' => ':count transazione|:count transazioni',
    'summary_reconciled_skipped' => ':count transazione riconciliata è stata saltata.|:count transazioni riconciliate sono state saltate.',
];
