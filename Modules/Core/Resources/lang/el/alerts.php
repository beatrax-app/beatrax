<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Ειδοποιήσεις συστήματος',

    'actions' => [
        'install_next_launch' => 'Εγκατάσταση στην επόμενη εκκίνηση',
        'install_next_launch_aria' => 'Εγκατάσταση στην επόμενη εκκίνηση — σημειώνει την ειδοποίηση συστήματος #:id ως επιλυμένη',
        'skip_version' => 'Παράλειψη αυτής της έκδοσης',
        'release_notes' => 'Σημειώσεις έκδοσης →',
        'update_now' => 'Ενημέρωση τώρα',
        'update_now_aria' => 'Ενημέρωση τώρα — σημειώνει την ειδοποίηση συστήματος #:id ως επιλυμένη',
        'remind_later' => 'Υπενθύμιση αργότερα',
        'mark_resolved' => 'Σήμανση ως επιλυμένης',
        'mark_resolved_aria' => 'Σήμανση ως επιλυμένης — ειδοποίηση συστήματος #:id',
    ],

    'messages' => [
        'update_available' => 'Διαθέσιμη ενημέρωση — το Beatrax :version είναι έτοιμο. Θα εγκατασταθεί στην επόμενη εκκίνηση.',
        'update_stale' => 'Χρησιμοποιείς την έκδοση :current — η έκδοση :latest είναι διαθέσιμη εδώ και 30 ημέρες. Ενημέρωσε τώρα.',
        'update_critical' => 'Διαθέσιμη κρίσιμη ενημέρωση — η έκδοση :version διορθώνει: :summary. Εγκατάστησέ την το συντομότερο δυνατό.',
        'backup_corrupt_with_path' => 'Το αντίγραφο ασφαλείας που γράφτηκε στις :timestamp απέτυχε στον έλεγχο ακεραιότητας. Έλεγξε το :path. Επίλυσέ το πριν βασιστείς στα αντίγραφα ασφαλείας.',
        'backup_corrupt_no_path' => 'Το αντίγραφο ασφαλείας που επιχειρήθηκε στις :timestamp διακόπηκε πριν παραχθεί οποιοδήποτε αρχείο — η βάση προέλευσης απέτυχε στον έλεγχο ακεραιότητας. Επίλυσέ το πριν βασιστείς στα αντίγραφα ασφαλείας.',

        'backup_overdue' => 'Το πιο πρόσφατο επαληθευμένο αντίγραφο ασφαλείας είναι :hoursh παλιό. Εκτέλεσε <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> ή περίμενε την προγραμματισμένη εκτέλεση στις 03:00.',
        'wal_mode_missing' => 'Η SQLite δεν βρίσκεται σε λειτουργία WAL (αυτή τη στιγμή :mode). Οι ταυτόχρονες εγγραφές ενδέχεται να κολλήσουν. Εκτέλεσε <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> για οδηγίες.',
        'synchronous_misconfigured' => 'Το επίπεδο synchronous της SQLite είναι :level (αναμενόταν NORMAL/1). Η συμπεριφορά ανθεκτικότητας ενδέχεται να διαφέρει από τη ρύθμιση. Εκτέλεσε <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code> για οδηγίες.',
        'oauth_scrub_set_failed' => 'Η απόκρυψη των μυστικών OAuth είναι εκτός λειτουργίας. Τα αρχεία καταγραφής και τα αποσπάσματα ελέγχου ενδέχεται να περιέχουν μη αποκρυμμένα διακριτικά μέχρι την επόμενη επιτυχή φόρτωση.',
        'oauth_reauth_required' => 'Τα μυστικά OAuth μεταφέρθηκαν σε αποθήκευση ανά χρήστη. Εξουσιοδοτήστε ξανά το Gmail και τη Microsoft για να συνεχιστεί η σάρωση email. Το παλιό αρχείο μυστικών μετονομάστηκε σε :file για επαναφορά.',
        'oauth_reconsent' => 'Συνδέστε ξανά το :provider σας',
        'reconnect_link' => 'Επανασύνδεση →',
    ],
];
