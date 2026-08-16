<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Ο λογαριασμός σου PayPal',
    'h1' => 'Σύνδεσε τον λογαριασμό σου PayPal',

    'lede_html' => 'Άφησε εδώ την εξαγωγή αναλυτικών στοιχείων συναλλαγών του PayPal — εμφανίζεται ως <em lang="nl">Rapport Transactiegegevens</em> σε ολλανδικό λογαριασμό PayPal. Η αναφορά υπολοίπου (<span lang="nl">Saldorapport</span>) δεν λειτουργεί — χρειάζονται δεδομένα ανά κίνηση.',

    'format_group_aria' => 'Το PayPal εξάγει μόνο σε CSV',
    'got_it_as' => 'Το κατέβασες ως:',
    'badge_only_format' => 'μοναδική μορφή',

    'mini' => [
        'login_label' => 'Σύνδεση',
        'custom_label' => 'Προσαρμοσμένες καταστάσεις',
        'range_label' => 'Επιλογή εύρους',
        'range_sub' => 'Τελευταίοι 12 μήνες',
        'download_label' => 'Λήψη ως CSV',
    ],

    'drop_lead' => 'Άφησε εδώ το CSV με τα αναλυτικά στοιχεία συναλλαγών',
    'browse_file' => 'ή επίλεξε ένα αρχείο',

    'file_ready' => '· ✓ έτοιμο',

    'skip' => 'Παράλειψη αυτού του βήματος',
    'continue' => 'Συνέχεια →',

    'errors' => [
        'required' => 'Άφησε πρώτα στο πλαίσιο το CSV Rapport Transactiegegevens του PayPal.',
        'max' => 'Αυτό το αρχείο είναι πολύ μεγάλο. Οι εξαγωγές Rapport Transactiegegevens του PayPal είναι συνήθως αρκετά κάτω από 10 MB.',
        'extensions' => 'Αυτό το αρχείο δεν μοιάζει με CSV του PayPal. Κατέβασε από το PayPal το Rapport Transactiegegevens (όχι την αναφορά υπολοίπου Saldorapport) σε μορφή CSV.',
        'unreadable' => 'Δεν ήταν δυνατή η ανάγνωση αυτού του αρχείου. Το πλήρες σφάλμα βρίσκεται στο /dev/logs.',
    ],
];
