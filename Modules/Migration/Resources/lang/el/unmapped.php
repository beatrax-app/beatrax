<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Στόχος: :name',
        'category_goal' => 'Στόχος της κατηγορίας :name',
        'schedule_untitled' => 'Προγραμματισμένη συναλλαγή χωρίς όνομα',
        'transaction' => 'Συναλλαγή: :name · :date · :amount',
        'transaction_unnamed' => 'Συναλλαγή',
        'amount_update' => 'Ενημέρωση ποσού συναλλαγής',
        'budget_history' => 'Ιστορικό προϋπολογισμού σε :currency',
        'budget_file_currency' => 'Νόμισμα του αρχείου προϋπολογισμού',
        'budget_file_mode' => 'Λειτουργία του αρχείου προϋπολογισμού',
    ],

    'conflict' => [
        'budget_assignment' => 'Κατανομή προϋπολογισμού',
        'budget_for_month' => 'Προϋπολογισμός για :category · :month',
        'budget_for_category' => 'Προϋπολογισμός για :category',
        'category_name' => 'Όνομα κατηγορίας',
        'category_name_of' => 'Όνομα της κατηγορίας «:name»',
        'account_name' => 'Όνομα λογαριασμού',
        'account_name_of' => 'Όνομα του λογαριασμού «:name»',
        'transaction_amount' => 'Ποσό συναλλαγής',
        'transaction_amount_of' => 'Ποσό: :name',
        'transaction_amount_of_dated' => 'Ποσό: :name · :date',
        'transaction_description' => 'Περιγραφή συναλλαγής',
        'transaction_description_of' => 'Περιγραφή: :name',
        'transaction_description_of_dated' => 'Περιγραφή: :name · :date',
        'other' => 'Εισαγόμενη τιμή',
    ],

    'reason' => [
        'fingerprint_collision' => 'Αυτή η συναλλαγή συγκρούστηκε με άλλη ήδη καταχωρισμένη συναλλαγή (ίδιο αποτύπωμα) και δεν εισήχθη.',

        // i18n-review: el · reason.split_legs_without_category — the waiting
        // bucket reads «στην κατηγορία Χωρίς κατηγορία», repeating this
        // locale's own name for Uncategorized. Dropping the frame noun leaves
        // the article without a gender to agree with.
        'split_legs_without_category' => ':count σκέλος επιμερισμού από τα :legs δεν έχει κατηγορία, και ένα σκέλος δεν μπορεί να αποθηκευτεί χωρίς αυτήν. Η συναλλαγή εισήχθη με ολόκληρο το ποσό της και περιμένει στην κατηγορία :uncategorized.|:count σκέλη επιμερισμού από τα :legs δεν έχουν κατηγορία, και ένα σκέλος δεν μπορεί να αποθηκευτεί χωρίς αυτήν. Η συναλλαγή εισήχθη με ολόκληρο το ποσό της και περιμένει στην κατηγορία :uncategorized.',
        'split_sum_mismatch' => 'Τα σκέλη του επιμερισμού αθροίζουν :legs, αλλά η συναλλαγή είναι :total, και ένας επιμερισμός πρέπει να ταιριάζει ακριβώς με τη συναλλαγή του. Η συναλλαγή εισήχθη με ολόκληρο το ποσό της, χωρίς τα σκέλη της.',
        'split_unstorable' => 'Το Beatrax δεν μπορεί να αποθηκεύσει αυτόν τον επιμερισμό όπως είναι, οπότε η συναλλαγή εισήχθη μόνη της, χωρίς τα σκέλη της.',
        'goal_without_target_date' => 'Αυτός ο στόχος δεν έχει ημερομηνία-στόχο· το Beatrax απαιτεί μία για να δημιουργήσει στόχο αποταμίευσης.',
        'goal_without_name' => 'Αυτός ο στόχος δεν έχει όνομα· το Beatrax απαιτεί ένα για να δημιουργήσει στόχο αποταμίευσης.',
        'goal_def_unsupported' => 'Το categories.goal_def χρησιμοποιεί μη υποστηριζόμενη (μη επίπεδη) μορφή προτύπου — ο στόχος δεν εισήχθη.',
        'budget_currency_mismatch' => ':count γραμμή προϋπολογισμού δεν εισήχθη: οι προϋπολογισμοί σου τηρούνται σε :envelope, ενώ αυτή η εξαγωγή προϋπολογίζει σε :source.|:count γραμμές προϋπολογισμού δεν εισήχθησαν: οι προϋπολογισμοί σου τηρούνται σε :envelope, ενώ αυτή η εξαγωγή προϋπολογίζει σε :source.',
        'amount_apply_collision' => 'Το νέο ποσό της πηγής δεν μπόρεσε να εφαρμοστεί — συγκρούεται με το αποτύπωμα άλλης συναλλαγής (ίδιος λογαριασμός, ημερομηνία, νόμισμα και αντισυμβαλλόμενος). Παρέμεινε αμετάβλητο.',
        'amount_currency_mismatch' => 'Τα ποσά των συναλλαγών δεν συμφωνήθηκαν: αυτές οι συναλλαγές τηρούνται σε :local, ενώ αυτή η εξαγωγή τις δηλώνει σε :source. Έμειναν αμετάβλητα.',
        'schedule_unsupported' => 'Οι προγραμματισμένες και επαναλαμβανόμενες συναλλαγές δεν έχουν ακόμη τρόπο δημιουργίας από εξωτερική πηγή στο Beatrax — διατηρήθηκαν μόνο ως σημείωση, όχι ως ενεργή επαναλαμβανόμενη σειρά.',
        'saved_report_unsupported' => 'Οι αποθηκευμένες αναφορές και οι ρυθμίσεις ανάλυσης δεν έχουν αντίστοιχο στο Beatrax.',
        'assumed_currency' => "Θεωρήθηκε :currency — δεν βρέθηκε γραμμή 'preferences.currencyCode' σε αυτή την εξαγωγή.",
        'assumed_budget_type' => "Θεωρήθηκε :mode — δεν βρέθηκε γραμμή 'preferences.budgetType' σε αυτή την εξαγωγή.",
        'changed_on_both_sides' => "Τόσο το αρχείο πηγής όσο και το Beatrax το άλλαξαν αυτό μετά την τελευταία εισαγωγή.\nΤοπικά: :local\nΠηγή: :source\nΤελευταία εισαγωγή: :baseline",
        'take_source' => 'Η τιμή της νέας εξαγωγής θα εφαρμοστεί μόλις επιβεβαιώσεις — η τοπική σου τιμή θα αντικατασταθεί.',
        'keep_local' => 'Η τοπική σου τιμή θα διατηρηθεί — η τιμή της νέας εξαγωγής δεν θα εφαρμοστεί.',
        'compared_values' => ":intro\nΤοπικά: :local · Πηγή: :source · Τελευταία εισαγωγή: :baseline",
    ],

    'value' => [
        'none' => '(καμία)',
        'quoted' => '«:value»',
    ],
];
