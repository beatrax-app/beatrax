<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Η εισαγωγή ολοκληρώθηκε',
        'receipts' => 'Βρέθηκαν νέες αποδείξεις',
        'drift' => 'Μια επαναλαμβανόμενη χρέωση άλλαξε',
        'forecast' => 'Έρχεται έλλειμμα ρευστότητας',
        'budget_nudge' => 'Ο προϋπολογισμός σχεδόν εξαντλήθηκε',
        'savings_prompt' => 'Υπάρχει φθηνότερο πρόγραμμα',
        'ics_statement_ready' => 'Νέα κατάσταση ICS διαθέσιμη',
        'payment_reminder_confident' => 'Λήξη πληρωμής: :day',
        'payment_reminder_hedged' => 'Λήξη πληρωμής: περίπου :day',
        'position_digest_daily' => 'Η ημερήσια σου εικόνα',
        'position_digest_weekly' => 'Η εβδομαδιαία σου εικόνα',
    ],

    'body' => [
        'budget_nudge' => ':category — δαπανήθηκαν :spent από :budget.',
        'receipts_matched' => ':count απόδειξη αντιστοιχίστηκε από το email σου.|:count αποδείξεις αντιστοιχίστηκαν από το email σου.',
        'import_finished' => 'Εισήχθη :count συναλλαγή.|Εισήχθησαν :count συναλλαγές.',
        'drift' => 'Μια επαναλαμβανόμενη χρέωση :direction κατά :amount.',
        'forecast' => 'Το προβλεπόμενο υπόλοιπό σου πέφτει κάτω από το μηδέν μέσα στις επόμενες 30 ημέρες.',
        'ics_statement_ready' => 'Κατέβασέ την από την πύλη ICS και άφησέ την στο Beatrax για να παραμείνουν ενημερωμένες οι δαπάνες αυτής της κάρτας.',
        'payment_reminder_hedged' => ':name — αναμένεται περίπου :day, :amount.',
        'payment_reminder_confident' => ':name — λήξη: :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/μήνα)',
    ],

    'drift_direction' => [
        'up' => 'αυξήθηκε',
        'down' => 'μειώθηκε',
    ],

    'digest' => [
        'nothing_notable' => 'Τίποτα δεν χρειάζεται την προσοχή σου.',
        'flow' => 'Είσοδοι :in, έξοδοι :out, καθαρό :net.',
        'over_budget' => ':amount πάνω από τον προϋπολογισμό μέχρι στιγμής.',
        'payments_due' => '1 πληρωμή λήγει αυτή την περίοδο.|:count πληρωμές λήγουν αυτή την περίοδο.',
        'shortfall' => 'Έρχεται έλλειμμα ρευστότητας.',
    ],
];
