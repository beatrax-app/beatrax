<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Πληκτρολόγησε για αναζήτηση σε προβολές, εντολές και ενέργειες. Πάτησε Esc για κλείσιμο.',
    'search_aria' => 'Πληκτρολόγησε για αναζήτηση σε προβολές, εντολές και ενέργειες',
    'dialog_aria' => 'Παλέτα εντολών',
    'token_suggest_aria' => 'Προτάσεις token',
    'rail_view' => 'Προβολή',
    'rail_dev' => 'Dev',
    'rail_action' => 'Ενέργεια',
    'rail_recent' => 'Πρόσφατα',
    'no_recent' => 'Δεν υπάρχουν ακόμη πρόσφατες επιλογές.',
    'section_transactions' => 'Συναλλαγές',
    'section_counterparties' => 'Αντισυμβαλλόμενοι',
    'section_categories' => 'Κατηγορίες',
    'section_goals_recurring' => 'Στόχοι και επαναλαμβανόμενα',
    'no_name' => '(χωρίς όνομα)',
    'see_all' => 'Δες :count αποτέλεσμα →|Δες και τα :count αποτελέσματα →',
    'no_transactions' => 'Καμία συναλλαγή δεν ταιριάζει με ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'αντισυμβαλλόμενος',
    'source_category' => 'κατηγορία',
    'results_aria' => 'Αποτελέσματα',
    'no_results' => 'Χωρίς αποτελέσματα.',
    'foot_navigate' => 'πλοήγηση',
    'foot_select' => 'επιλογή',
    'foot_close' => 'κλείσιμο',
    'close_aria' => 'Κλείσιμο αναζήτησης',
    'close_caption' => 'Κλείσιμο',
    'foot_try' => 'Δοκίμασε',
    'results' => ':count αποτέλεσμα|:count αποτελέσματα',

    'action' => [
        'run_import' => ['label' => 'Εκτέλεση εισαγωγής', 'hint' => 'Άνοιγμα του οδηγού εισαγωγής'],
        'scan_email' => ['label' => 'Άνοιγμα γραμματοκιβωτίων', 'hint' => 'Τα συνδεδεμένα σου γραμματοκιβώτια'],
        'open_profile' => ['label' => 'Άνοιγμα προφίλ', 'hint' => 'Ρυθμίσεις — λογαριασμός και προτιμήσεις'],
        'toggle_theme' => ['label' => 'Άνοιγμα ρυθμίσεων εμφάνισης', 'hint' => 'Φωτεινό, σκούρο ή του συστήματος'],
    ],

    'run_command' => 'Εκτέλεση :command',

    'nav' => [
        'overview' => ['label' => 'Επισκόπηση προγραμματιστή', 'hint' => 'Πλακίδια συστήματος + πρόσφατες εκτελέσεις'],
        'artisan' => ['label' => 'Εκτέλεση εντολών Artisan', 'hint' => 'Εκτέλεση εγκεκριμένων εντολών'],
        'audit' => ['label' => 'Αρχείο ελέγχου προγραμματιστή', 'hint' => 'Οι δικές σου ενέργειες σε λειτουργία προγραμματιστή'],
        'logs' => ['label' => 'Παρακολούθηση καταγραφών', 'hint' => 'Ζωντανή ροή του laravel-*.log'],
        'queue' => ['label' => 'Επιθεωρητής ουράς', 'hint' => 'Σε αναμονή / απέτυχαν / παρτίδες'],
        'doctor' => ['label' => 'Διαγνωστικά', 'hint' => 'Έλεγχοι συστήματος'],
        // i18n-review: el · nav.sql.label — Πάνελ SQL is a loanword. Πίνακας
        // would collide with the word for a database table, which is what the
        // screen lists, so the loanword is the lesser of the two.
        'sql' => ['label' => 'Πάνελ SQL', 'hint' => 'Περιήγηση μόνο με SELECT'],
        'system' => ['label' => 'Στιγμιότυπο συστήματος', 'hint' => 'Περιβάλλον + διαδρομές + διαμόρφωση'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Ενσωματωμένος πίνακας ελέγχου ουράς'],
        'sync_health' => ['label' => 'Κατάσταση συγχρονισμού', 'hint' => 'Λειτουργίες συγχώνευσης σε καραντίνα ή που παραλείφθηκαν'],
    ],
];
