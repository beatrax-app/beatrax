<?php

declare(strict_types=1);

return [
    'heading' => 'Γραμματοκιβώτια',
    'intro' => 'Σύνδεσε γραμματοκιβώτια Gmail και Microsoft 365 ώστε το Beatrax να μπορεί να τα σαρώνει για αποδείξεις.',

    'connection_canceled' => 'Η σύνδεση ακυρώθηκε.',
    'connection_failed' => 'Η σύνδεση δεν μπόρεσε να ολοκληρωθεί.',

    'backfilling' => 'Αναδρομική σάρωση',
    'messages_suffix' => 'μηνύματα',

    'connect_heading' => 'Σύνδεσε το email σου',
    'connect_body' => 'Εισάγαγε αποδείξεις από PayPal, ICS Cards, Google Play και άλλους εμπόρους δίνοντας στο Beatrax πρόσβαση μόνο για ανάγνωση σε ένα ή περισσότερα γραμματοκιβώτιά σου.',
    'connect_gmail' => 'Σύνδεση Gmail',
    'connect_microsoft' => 'Σύνδεση Microsoft 365',
    'readonly_note' => 'Το Beatrax μόνο διαβάζει μηνύματα. Δεν στέλνει, δεν βάζει ετικέτες, δεν μετακινεί και δεν διαγράφει ποτέ τίποτα στο γραμματοκιβώτιό σου.',

    'months' => ':count μήνας|:count μήνες',
    'not_scanned_yet' => 'δεν έχει σαρωθεί ακόμη',
    'last_scanned' => 'τελευταία σάρωση',
    'window_prefix' => 'Παράθυρο:',
    'edit' => 'Επεξεργασία',

    'badge' => [
        'idle' => 'Αδρανές',
        'backfilling' => 'Αναδρομική σάρωση',
        'scanning' => 'Σάρωση',
        'rate_limited' => 'Περιορισμός ρυθμού',
        'needs_reauth' => 'Χρειάζεται νέα εξουσιοδότηση',
        'error' => 'Σφάλμα',
    ],

    'retry_seconds' => 'νέα προσπάθεια σε :nδλ',
    'retry_minutes' => 'νέα προσπάθεια σε :nλ',
    'retry_hours' => 'νέα προσπάθεια σε :nώ',

    'reconnect' => 'Επανασύνδεση',
    'disconnect' => 'Αποσύνδεση',
    'scan_now' => 'Σάρωση τώρα',
    'scan_in_progress_title' => 'Η σάρωση βρίσκεται ήδη σε εξέλιξη',

    'add_another' => 'Προσθήκη άλλου γραμματοκιβωτίου',
    'gmail_card_body' => 'Σύνδεσε έναν λογαριασμό Gmail ώστε το Beatrax να μπορεί να τον σαρώνει για αποδείξεις.',
    'microsoft_card_body' => 'Σύνδεσε έναν λογαριασμό Microsoft 365 ή Outlook.com ώστε το Beatrax να μπορεί να τον σαρώνει για αποδείξεις.',

    'discovered_heading' => 'Αποστολείς που εντοπίστηκαν',
    'discovered_body' => 'Αποστολείς που μοιάζει να στέλνουν αποδείξεις αλλά δεν βρίσκονται ακόμη στη λίστα γνωστών αποδείξεων. Πρόσθεσε όσους θέλεις να σαρώνει το Beatrax· απόρριψε τους υπόλοιπους.',
    'last_seen' => 'τελευταία εμφάνιση',
    'seen_times' => 'Εμφανίστηκε :count φορά|Εμφανίστηκε :count φορές',
    'add' => 'Προσθήκη',
    'add_aria' => 'Προσθήκη :email',
    'dismiss' => 'Απόρριψη',
    'dismiss_aria' => 'Απόρριψη :email',

    'toast' => [
        'scan_in_progress' => 'Η σάρωση βρίσκεται ήδη σε εξέλιξη.',
        'scan_started' => 'Η σάρωση ξεκίνησε.',
        'sender_added' => 'Ο αποστολέας προστέθηκε.',
        'sender_dismissed' => 'Ο αποστολέας απορρίφθηκε.',
    ],
];
