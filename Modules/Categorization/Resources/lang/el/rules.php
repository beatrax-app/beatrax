<?php

declare(strict_types=1);

return [
    'page_title' => 'Κανόνες',
    'heading' => 'Κανόνες',
    'intro' => 'Προκατηγοριοποίησε συναλλαγές κατά την εισαγωγή. Οι κανόνες ισχύουν για κάθε πηγή — τράπεζα, κάρτα, PayPal και αποδείξεις email.',
    'device_local_note' => 'Οι κανόνες παραμένουν σε αυτή τη συσκευή. Δεν κοινοποιούνται στις άλλες συσκευές σας.',

    'reapply' => 'Εκ νέου εφαρμογή κανόνων στο ιστορικό',
    // i18n-review: el · reapply_confirm — «κατάσταση» on its own also reads as "state", so
    // this writes the fuller «κατάσταση λογαριασμού» where ledger::reconcile.statement_date
    // carries the bare noun. Confirm the longer form is what a reader here expects.
    'reapply_confirm' => 'Να εφαρμοστούν ξανά όλοι οι κανόνες σε ολόκληρο το ιστορικό σου; Κάθε κατηγορία, αντισυμβαλλόμενος, σημείωση και φορολογική ετικέτα που έβαλε ένας κανόνας ξαναγράφεται. Ό,τι όρισες με το χέρι παραμένει, όπως και οτιδήποτε βρίσκεται σε συμφωνημένη κατάσταση λογαριασμού ή σε συναλλαγή που έχεις επιμερίσει. Τίποτα δεν επαναφέρει τις παλιές τιμές.',
    'reapplying' => 'Εφαρμόζονται ξανά…',
    'new_rule' => 'Νέος κανόνας',

    // i18n-review: el · reapply_progress — the verb ελέγχθηκε/ελέγχθηκαν follows the
    // arm :count selects, but what was checked is :checked. At a total of one the
    // singular is right; a native eye should say whether a fixed plural reads better.
    'reapply_progress' => 'Οι κανόνες εφαρμόζονται ξανά… :checked από :count συναλλαγή ελέγχθηκε|Οι κανόνες εφαρμόζονται ξανά… :checked από :count συναλλαγές ελέγχθηκαν',

    'empty_heading' => 'Δεν υπάρχουν ακόμη κανόνες',
    'empty_body' => 'Οι κανόνες αντιστοιχίζουν συναλλαγές με βάση πολλαπλές συνθήκες και εφαρμόζουν αυτόματα αλλαγές σε κατηγορία, αντισυμβαλλόμενο, σημείωση και φορολογική ετικέτα — κατά την εισαγωγή και κάθε φορά που τους εφαρμόζεις ξανά στο υπάρχον ιστορικό σου.',
    'empty_cta' => 'Δημιούργησε τον πρώτο σου κανόνα',

    'col_priority' => 'Προτεραιότητα',
    'col_conditions' => 'Συνθήκες',
    'col_actions' => 'Ενέργειες',
    'col_hits' => 'Αντιστοιχίσεις',
    'col_created' => 'Δημιουργήθηκε',
    'col_row_actions' => 'Ενέργειες',
    'inactive_badge' => 'Ανενεργός',
    'combinator_all' => 'ΟΛΕΣ',
    'combinator_any' => 'ΟΠΟΙΑΔΗΠΟΤΕ',
    'inactive_title' => 'Αυτός ο κανόνας δεν εκτελείται. Ένας κανόνας απενεργοποιείται όταν διαγραφεί η κατηγορία ή ο αντισυμβαλλόμενος στον οποίο παραπέμπει.',

    'more_conditions' => '+:count ακόμη',

    'delete_confirm' => 'Διαγραφή;',
    'delete_yes' => 'Ναι, διάγραψέ τον',
    'cancel' => 'Άκυρο',
    'edit' => 'Επεξεργασία',
    'delete' => 'Διαγραφή',
    'edit_aria' => 'Επεξεργασία κανόνα (προτεραιότητα :priority)',
    'delete_aria' => 'Διαγραφή κανόνα (προτεραιότητα :priority)',

    'footer_note' => 'Οι κανόνες και το ιστορικό εμπόρων λειτουργούν μαζί. Η διαγραφή ενός κανόνα δεν σβήνει όσα έχει μάθει το Beatrax από προηγούμενες κατηγοριοποιήσεις — η επόμενη εισαγωγή μπορεί να προτείνει ξανά αυτόματα την ίδια κατηγορία από το ιστορικό.',

    'chip_category' => 'Κατηγορία: :path',
    'chip_counterparty' => 'Αντισυμβαλλόμενος: :path',
    'chip_note' => 'Σημείωση',
    'chip_tax_tag' => 'Φορολογική ετικέτα',

    'flash_deleted' => 'Ο κανόνας διαγράφηκε.',
    'flash_not_found' => 'Ο κανόνας δεν βρέθηκε (μπορεί να διαγράφηκε σε άλλη καρτέλα).',
    'flash_saved' => 'Ο κανόνας αποθηκεύτηκε.',
    'flash_reapplying' => 'Οι κανόνες εφαρμόζονται ξανά στο ιστορικό σου…',
    'summary_no_changes' => 'Καμία αλλαγή — το ιστορικό σου ταιριάζει ήδη με τους κανόνες σου.',
    'summary_updated' => 'Ενημερώθηκαν :fields σε :transactions.',
    'summary_fields' => ':count πεδίο|:count πεδία',
    'summary_transactions' => ':count συναλλαγή|:count συναλλαγές',
    'summary_reconciled_skipped' => 'Παραλείφθηκε :count συμφωνημένη συναλλαγή.|Παραλείφθηκαν :count συμφωνημένες συναλλαγές.',
];
