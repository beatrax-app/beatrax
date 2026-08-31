<?php

declare(strict_types=1);

return [
    'page_title' => 'Smistamento controparti',
    'heading' => 'Smista le controparti sconosciute',

    'progress' => ':seen di :total · :percent% · ~:minutes min rimanenti',
    'progress_aria' => 'Avanzamento dello smistamento',

    'all_caught_aria' => 'Tutte le controparti etichettate',
    'all_caught_heading' => '🎉 Tutto fatto — ogni controparte è etichettata.',
    'back_to_index' => 'Torna alle controparti →',

    'meta' => ':count transazione · ultima volta :date|:count transazioni · ultima volta :date',

    'suggested_aria' => 'Corrispondenza suggerita',
    'suggestion_medium' => '✨ Forse **:name** — affidabilità media',
    'suggestion_low' => 'Corrispondenza per schema: **:name** — affidabilità bassa. Verifica prima di collegare.',
    'suggestion_high' => '✨ Sembra **:name** — affidabilità alta',

    'reasoning' => ':hits di :total transazione recente su questo IBAN porta a :name.|:hits di :total transazioni recenti su questo IBAN portano a :name.',
    'yes_link' => 'Sì, collega a :name ↵',
    'no_not' => 'No, non è :name',

    'recent_on_iban' => 'Transazioni recenti su questo IBAN',
    'recent_on_counterparty' => 'Transazioni recenti con questa controparte',
    'no_transactions_yet' => 'Ancora nessuna transazione registrata.',

    'label_manually' => 'Oppure etichetta manualmente',
    'label_question' => 'Che cos\'è questa controparte?',
    'display_name_label' => 'Nome visualizzato',
    'type_label' => 'Tipo',
    'type_merchant' => 'Esercente',
    'type_personal' => 'Personale',
    'type_bank' => 'Banca',
    'type_government' => 'Ente pubblico',
    'save_label' => 'Salva etichetta',
    'name_required' => 'Dai prima un nome a questa controparte.',
    'draft_kept' => 'Quello che scrivi resta mentre scorri la coda.',

    'skip' => 'Salta per ora',
    'mark_ignored' => 'Non chiedermelo più',
    'not_now_note' => 'Nessuna delle due modifica la controparte: puoi ancora etichettarla più tardi dalla pagina Controparti.',
    'previous' => 'Sconosciuta precedente',

    'kbd_yes' => 'sì',
    'kbd_no' => 'no',
    'kbd_skip' => 'salta',
    'kbd_next' => 'avanti',

    'footer' => ':seen già etichettate · :count da fare',
];
