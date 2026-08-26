<?php

declare(strict_types=1);

return [
    'page_title' => 'Reguli',
    'heading' => 'Reguli',
    'intro' => 'Categorisește tranzacțiile încă de la import. Regulile se aplică fiecărei surse — bancă, card, PayPal și bonuri primite pe e-mail.',
    'device_local_note' => 'Regulile rămân pe acest dispozitiv. Nu sunt partajate cu celelalte dispozitive ale tale.',

    'reapply' => 'Reaplică regulile pe istoric',
    'reapplying' => 'Se reaplică…',
    'new_rule' => 'Regulă nouă',

    'reapply_progress_lead' => 'Se reaplică regulile…',
    'reapply_progress_of' => 'din',
    'reapply_progress_trail' => 'tranzacții verificate',

    'empty_heading' => 'Încă nicio regulă',
    'empty_body' => 'Regulile potrivesc tranzacțiile pe baza mai multor condiții și aplică automat modificări de categorie, contraparte, notă și etichetă fiscală — la import și oricând le reaplici pe istoricul tău existent.',
    'empty_cta' => 'Creează prima ta regulă',

    'col_priority' => 'Prioritate',
    'col_conditions' => 'Condiții',
    'col_actions' => 'Acțiuni',
    'col_hits' => 'Potriviri',
    'col_created' => 'Creată',
    'col_row_actions' => 'Acțiuni',
    'inactive_badge' => 'Inactivă',
    'inactive_title' => 'Această regulă nu rulează. O regulă se dezactivează când categoria sau contrapartida la care trimite este ștearsă.',

    'more_conditions' => '+:count în plus',

    'delete_confirm' => 'Ștergi?',
    'delete_yes' => 'Da, șterge',
    'cancel' => 'Anulează',
    'edit' => 'Editează',
    'delete' => 'Șterge',
    'edit_aria' => 'Editează regula (prioritate :priority)',
    'delete_aria' => 'Șterge regula (prioritate :priority)',

    'footer_note' => 'Regulile și istoricul comercianților lucrează împreună. Ștergerea unei reguli nu șterge ce a învățat Beatrax din categorisirile anterioare — următorul import poate sugera în continuare aceeași categorie din istoric.',

    'chip_category' => 'Categorie: :path',
    'chip_counterparty' => 'Contraparte: :path',
    'chip_note' => 'Notă',
    'chip_tax_tag' => 'Etichetă fiscală',

    'flash_deleted' => 'Regulă ștearsă.',
    'flash_not_found' => 'Regula nu a fost găsită (poate a fost ștearsă în altă filă).',
    'flash_saved' => 'Regulă salvată.',
    'flash_reapplying' => 'Se reaplică regulile pe istoricul tău…',
    'summary_no_changes' => 'Nicio schimbare — istoricul tău corespunde deja regulilor tale.',
    'summary_updated' => 'S-au actualizat :fields în :transactions.',
    'summary_fields' => ':count câmp|:count câmpuri|:count de câmpuri',
    'summary_transactions' => ':count tranzacție|:count tranzacții|:count de tranzacții',
    'summary_reconciled_skipped' => ':count tranzacție reconciliată a fost omisă.|:count tranzacții reconciliate au fost omise.|:count de tranzacții reconciliate au fost omise.',
];
