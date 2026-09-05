<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Η εισαγωγή ολοκληρώθηκε',
        'receipts' => 'Βρέθηκαν νέες αποδείξεις',
        'manual_entry' => 'Το βιβλίο μετρητών ενημερώθηκε',
        'migration_finished' => 'Η μεταφορά ολοκληρώθηκε',
        'drift' => 'Μια επαναλαμβανόμενη χρέωση άλλαξε',
        'forecast' => 'Έρχεται έλλειμμα ρευστότητας',
        'budget_nudge' => 'Ο προϋπολογισμός σχεδόν εξαντλήθηκε',
        'budget_nudge_spent' => 'Ο προϋπολογισμός εξαντλήθηκε',
        'budget_nudge_over' => 'Ο προϋπολογισμός ξεπεράστηκε',
        'savings_prompt' => 'Ένα σημείο όπου μπορείς να εξοικονομήσεις',
        'ics_statement_ready' => 'Νέα κατάσταση ICS διαθέσιμη',
        'payment_reminder_confident' => 'Λήξη πληρωμής: :day (:date)',
        'payment_reminder_hedged' => 'Λήξη πληρωμής: περίπου :day (:date)',
        'position_digest_daily' => 'Η ημερήσια σου εικόνα',
        'position_digest_weekly' => 'Η εβδομαδιαία σου εικόνα',
    ],

    'body' => [
        'budget_nudge' => ':category — δαπανήθηκαν :spent από :budget.',
        'receipts_matched' => ':count απόδειξη αντιστοιχίστηκε από το email σου.|:count αποδείξεις αντιστοιχίστηκαν από το email σου.',
        'import_finished' => 'Εισήχθη :count συναλλαγή.|Εισήχθησαν :count συναλλαγές.',
        'manual_entry' => 'Προστέθηκε :count καταχώριση με το χέρι.|Προστέθηκαν :count καταχωρίσεις με το χέρι.',
        'migration_finished' => 'Ο προϋπολογισμός σου μεταφέρθηκε, μαζί με :count συναλλαγή.|Ο προϋπολογισμός σου μεταφέρθηκε, μαζί με :count συναλλαγές.',
        'drift' => 'Μια επαναλαμβανόμενη χρέωση :direction κατά :amount.',
        'forecast' => 'Το προβλεπόμενο υπόλοιπό σου πέφτει κάτω από το μηδέν στις :date.',
        'forecast_buffer' => 'Το προβλεπόμενο υπόλοιπό σου πέφτει κάτω από το απόθεμά σου (:buffer) στις :date.',
        'ics_statement_ready' => 'Κατέβασέ την από την πύλη ICS και άφησέ την στο Beatrax για να παραμείνουν ενημερωμένες οι δαπάνες αυτής της κάρτας.',
        'payment_reminder_hedged' => ':name — αναμένεται περίπου :day (:date), :amount.',
        'payment_reminder_confident' => ':name — λήξη: :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'αυξήθηκε',
        'down' => 'μειώθηκε',
    ],

    'digest' => [
        'nothing_notable' => 'Τίποτα δεν χρειάζεται την προσοχή σου.',
        'flow' => 'Είσοδοι :in, έξοδοι :out, καθαρό :net.',
        'net_worth' => 'Καθαρή θέση :amount.',
        'over_budget' => ':amount πάνω από τον προϋπολογισμό μέχρι στιγμής.',
        'payments_due' => ':count πληρωμή λήγει αυτή την περίοδο.|:count πληρωμές λήγουν αυτή την περίοδο.',
        'shortfall' => 'Έρχεται έλλειμμα ρευστότητας.',
        'forecast_not_run' => 'Δεν έχει τρέξει ακόμη πρόβλεψη ταμειακών ροών.',
    ],
];
