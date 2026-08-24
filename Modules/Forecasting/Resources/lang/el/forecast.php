<?php

declare(strict_types=1);

return [
    'heading' => 'Πρόβλεψη',
    'page_title' => 'Πρόβλεψη',
    'subtitle' => 'Πού οδεύει το υπόλοιπό σου — τις επόμενες 30 έως 365 ημέρες.',
    'adjust_buffers' => 'Προσαρμογή αποθεμάτων',

    'empty_heading' => 'Δεν υπάρχουν ακόμη δεδομένα πρόβλεψης',
    'empty_body' => 'Σύνδεσε έναν λογαριασμό ή ενέκρινε μια επαναλαμβανόμενη σειρά για να δεις το προβλεπόμενο υπόλοιπό σου τις επόμενες εβδομάδες.',
    'empty_start' => 'Ξεκίνα',
    'empty_import_link' => 'εισάγοντας μια κατάσταση κινήσεων',
    'empty_or' => 'ή',
    'empty_recurring_link' => 'ελέγχοντας τα επαναλαμβανόμενα μοτίβα',

    'account_tablist' => 'Λογαριασμός',
    'all_accounts' => 'Όλοι οι λογαριασμοί',

    'horizon_label' => 'Ορίζοντας πρόβλεψης',
    'n_days' => ':days ημέρα|:days ημέρες',

    'view_by_funder' => 'Προβολή ανά χρηματοδότη',
    'view_by_funder_hint' => 'Σύμπτυξη των σειρών που επιλύθηκαν μέσω αλυσίδας στον λογαριασμό που τις πληρώνει στην πραγματικότητα.',

    'scenario_group' => 'Σενάριο',
    'baseline' => 'Βάση',
    'scenario_word' => 'Σενάριο',
    'new_scenario' => '+ Νέο σενάριο',
    'scenario_name_placeholder' => 'Όνομα σεναρίου',
    'new_scenario_aria' => 'Όνομα νέου σεναρίου',
    'create_scenario' => 'Δημιουργία σεναρίου',
    'cancel' => 'Άκυρο',

    'aggregate_subtitle' => 'Συνολικό υπόλοιπο σε όλους τους λογαριασμούς, προβαλλόμενο για την επόμενη :days ημέρα.|Συνολικό υπόλοιπο σε όλους τους λογαριασμούς, προβαλλόμενο για τις επόμενες :days ημέρες.',

    'today' => 'σήμερα',
    'on_day' => 'την ημέρα',

    'edit_buffer_aria' => 'Επεξεργασία ελάχιστου αποθέματος — :name',
    'buffer_not_set' => 'Απόθεμα: δεν έχει οριστεί',
    'buffer_set' => 'Απόθεμα: :amount',

    'shortfall' => 'Το έλλειμμα ξεκινά :date — :amount κάτω από το απόθεμά σου (:buffer)',

    'compared_against_baseline' => 'Σε σύγκριση με τη βάση παραπάνω',

    'scenario_editor_aria' => 'Επεξεργασία σεναρίου',
    'series_confidence' => 'Αξιοπιστία σειράς',
    'no_series_contribute' => 'Καμία σειρά δεν συνεισφέρει ακόμη στην πρόβλεψη αυτού του λογαριασμού.',

    'net_diff' => 'Καθαρή διαφορά',
    'net_diff_section_aria' => 'Καθαρή διαφορά μεταξύ βάσης και σεναρίου στους ορίζοντες 30 / 60 / 90 ημερών',
    'net_diff_delta_aria' => 'Καθαρή διαφορά την ημέρα :day: :value, το σενάριο είναι :state',
    'better_than_baseline' => 'καλύτερο από τη βάση',
    'worse_than_baseline' => 'χειρότερο από τη βάση',
    'equal_to_baseline' => 'ίσο με τη βάση',
    'at_day' => 'την ημέρα :day',

    'updating' => 'Ενημέρωση',
    'chart_noscript' => 'Το γράφημα απαιτεί JavaScript. Το εύρος καλύπτει :days ημέρα.|Το γράφημα απαιτεί JavaScript. Το εύρος καλύπτει :days ημέρες.',
    'total_balance' => 'Συνολικό υπόλοιπο',

    'per_month_suffix' => '/μήνα',
    'confidence_chip_aria' => ':name, αξιοπιστία :confidence — το εύρος πρόβλεψης είναι :percent τοις εκατό της κεντρικής εκτίμησης',

    'highlights_title' => 'Κύρια σημεία πρόβλεψης',
    'highlights_shortfall_aria' => ':count ενεργό παράθυρο ελλείμματος τις επόμενες :days ημέρες|:count ενεργά παράθυρα ελλείμματος τις επόμενες :days ημέρες',
    'on_date_suffix' => ' στις :date',
    'shortfall_window' => ':count ενεργό παράθυρο ελλείμματος|:count ενεργά παράθυρα ελλείμματος',
    'lowest_in_30_label' => 'Χαμηλότερο σε 30 ημέρες',
    'next_ics' => 'Επόμενος διακανονισμός ICS: :amount στις :date',
    'ics_overdue' => 'Ληξιπρόθεσμος διακανονισμός ICS: :amount, με λήξη στις :date',
];
